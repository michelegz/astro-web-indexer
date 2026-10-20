"""Durable progress for the indexer, written where PHP can read it.

The web container cannot see this process: the two containers share no writable
filesystem and PHP must not reach into Docker, so the database is the channel.
This module is that channel and nothing else. It knows no indexing logic; it
only records what reindex.py and watch_fs.py are doing.

Design rules
------------
* Best effort. A status write must never stop an index or fail a run. The first
  failure disables the publisher for the rest of the process and is logged once.
* Its own connection, autocommit. reindex.py commits data in batches and rolls a
  failed batch back; a status write on that connection would be rolled back with
  it, and a status write inside a batch would lengthen the transaction. This
  connection writes only status rows.
* NOW() for every timestamp. MariaDB writes the clock, not Python: the web side
  computes ages with TIMESTAMPDIFF() against the same clock, so the containers
  may disagree about their timezone without the UI lying.
* Stable codes, not prose. Phases and reasons are translated by the web side;
  the database stores ``created``, ``metrics`` and the like.
"""

import json
import logging
import os
import socket
import time

try:
    import mysql.connector
except ImportError:  # pragma: no cover - the image always ships the driver
    mysql = None

logger = logging.getLogger('awi.status')

# How many finished runs the admin card shows. The table is pruned to this many
# when a run finishes, so it never grows with the age of the archive.
RUN_HISTORY = 5

# A run whose heartbeat is older than this is treated as interrupted by the next
# run. Five minutes is well above a single slow frame (the indexer warns above
# 60 s) and keeps a concurrent manual run from being declared dead.
STALE_RUN_SECONDS = 300

# One status write every two seconds is plenty for a progress bar and keeps the
# write load invisible next to the image decoding.
PROGRESS_INTERVAL = 2.0

# The watcher heartbeat must say "alive" even when nothing happens. It also must
# not turn the status table into a write log, hence the slow interval.
WATCHER_HEARTBEAT_INTERVAL = 10.0

_RUN_COLUMNS = {
    'status', 'reason', 'phase', 'phase_done', 'phase_total',
    'files_scanned', 'db_files', 'skipped', 'files_total', 'files_done',
    'metrics_total', 'metrics_done', 'error_count', 'last_error',
    'phase_mode', 'config', 'pid', 'host',
}

_WATCHER_COLUMNS = {
    'state', 'last_scan_at', 'pending_reason', 'failures',
    'cooldown_seconds', 'known_files', 'config',
}


def _json_config(config):
    if not config:
        return None
    try:
        return json.dumps(config, default=str)[:2000]
    except Exception:
        return None


class _Publisher:
    """One short autocommit connection, disabled on the first real failure."""

    def __init__(self, db_params):
        self._db = dict(db_params or {})
        self._conn = None
        self._disabled = False
        self._logged = False

    def _disable(self, reason, quiet=False):
        if self._disabled:
            return
        self._disabled = True
        self._close()
        if quiet:
            logger.debug("Indexer status publisher disabled: %s", reason)
        elif not self._logged:
            self._logged = True
            logger.warning("Indexer status publisher disabled: %s", reason)

    def _close(self):
        conn, self._conn = self._conn, None
        if conn is not None:
            try:
                conn.close()
            except Exception:
                pass

    def _cursor(self):
        if self._disabled:
            return None
        if mysql is None:
            self._disable("mysql driver is not available", quiet=True)
            return None
        if self._conn is not None:
            try:
                self._conn.ping(reconnect=True, attempts=1, delay=0)
                return self._conn.cursor()
            except Exception:
                self._close()
        try:
            self._conn = mysql.connector.connect(
                host=self._db.get('host', 'mariadb'),
                user=self._db.get('user', 'awi_user'),
                password=self._db.get('password', ''),
                database=self._db.get('database', 'awi_db'),
                autocommit=True,
                # This connection is opened from inside the indexing loop: an
                # unreachable DB must cost a few seconds once, not block image
                # decoding for the driver's default timeout.
                connection_timeout=5,
            )
            return self._conn.cursor()
        except Exception as exc:
            self._disable(str(exc))
            return None

    def execute(self, sql, params=()):
        """Run one statement. Returns (lastrowid, rowcount) or None."""
        cur = self._cursor()
        if cur is None:
            return None
        try:
            cur.execute(sql, params)
            result = (cur.lastrowid, cur.rowcount)
            try:
                cur.close()
            except Exception:
                self._close()
            return result
        except Exception as exc:
            try:
                cur.close()
            except Exception:
                pass
            # 1146 = table does not exist, 1054 = unknown column: this is a status
            # table that the migrations have not created yet. Indexing must keep
            # working, so stop trying for the rest of the process, quietly.
            errno = getattr(exc, 'errno', None)
            if errno in (1054, 1146):
                self._disable(str(exc), quiet=True)
            else:
                self._close()
                self._disable(str(exc))
            return None

    def fetchall(self, sql, params=()):
        cur = self._cursor()
        if cur is None:
            return []
        try:
            cur.execute(sql, params)
            rows = cur.fetchall()
            try:
                cur.close()
            except Exception:
                self._close()
            return rows
        except Exception as exc:
            try:
                cur.close()
            except Exception:
                pass
            errno = getattr(exc, 'errno', None)
            if errno in (1054, 1146):
                self._disable(str(exc), quiet=True)
            else:
                self._close()
                self._disable(str(exc))
            return []

    def close(self):
        self._close()


class RunStatus:
    """The row of one reindex run.

    ``run_id`` is None when the status tables are missing or the DB is not
    reachable through this connection: every later call is then a no-op, and
    ``finished`` is already True so the caller's finally block stays quiet.
    """

    def __init__(self, db_params, reason='manual', phase_mode=None, config=None):
        self._pub = _Publisher(db_params)
        self._last_write = 0.0
        self._finished = False
        self.run_id = None
        try:
            self._start(reason, phase_mode, config)
        except Exception as exc:  # noqa: BLE001 - status must not stop the run
            logger.warning("Indexer status publisher unavailable: %s", exc)
            self._pub.close()
        if self.run_id is None:
            self._finished = True

    def _start(self, reason, phase_mode, config):
        # A crash leaves status='running' behind. The next run is the only process
        # that can tell, so it closes those rows first. The heartbeat guard keeps
        # a real concurrent run (a manual backfill beside the watcher) alive.
        self._pub.execute(
            "UPDATE indexer_runs SET status = 'interrupted', finished_at = NOW() "
            "WHERE status = 'running' AND (heartbeat_at IS NULL "
            "OR heartbeat_at < (NOW() - INTERVAL %s SECOND))",
            (STALE_RUN_SECONDS,),
        )
        result = self._pub.execute(
            "INSERT INTO indexer_runs "
            "(started_at, status, reason, phase, phase_started_at, heartbeat_at, "
            " phase_mode, config, pid, host) "
            "VALUES (NOW(), 'running', %s, 'starting', NOW(), NOW(), %s, %s, %s, %s)",
            (
                str(reason or 'manual')[:32],
                str(phase_mode)[:8] if phase_mode else None,
                _json_config(config),
                os.getpid(),
                socket.gethostname()[:64],
            ),
        )
        if result is None:
            return
        self.run_id = int(result[0])
        self._prune_history()

    def _prune_history(self):
        # Keep exactly the newest finished runs, whether or not this run ever
        # reaches the UI. A status table needs a summary, not an audit log.
        rows = self._pub.fetchall(
            "SELECT id FROM indexer_runs WHERE status <> 'running' "
            "ORDER BY id DESC LIMIT %s",
            (RUN_HISTORY,),
        )
        keep = [int(r[0]) for r in rows]
        if keep:
            placeholders = ','.join(['%s'] * len(keep))
            self._pub.execute(
                f"DELETE FROM indexer_runs WHERE status <> 'running' AND id NOT IN ({placeholders})",
                tuple(keep),
            )
        else:
            self._pub.execute("DELETE FROM indexer_runs WHERE status <> 'running'")

    def _write(self, fields, heartbeat=True):
        if self._finished or self.run_id is None:
            return
        allowed = {k: v for k, v in fields.items() if k in _RUN_COLUMNS}
        if heartbeat:
            allowed['heartbeat_at'] = None  # sentinel handled below
        if not allowed:
            return
        assignments = []
        params = []
        for column, value in allowed.items():
            if column == 'heartbeat_at':
                assignments.append("`heartbeat_at` = NOW()")
            else:
                assignments.append(f"`{column}` = %s")
                params.append(value)
        params.append(self.run_id)
        self._pub.execute(
            "UPDATE indexer_runs SET " + ', '.join(assignments) + " WHERE id = %s",
            tuple(params),
        )

    def phase(self, phase, total=0, **fields):
        """Start a new phase. Always writes: a phase boundary is not noise."""
        payload = {
            'phase': str(phase)[:24],
            'phase_done': 0,
            'phase_total': int(total or 0),
        }
        payload.update({k: v for k, v in fields.items() if k in _RUN_COLUMNS})
        self._write_raw(payload, raw={'phase_started_at': 'NOW()'})
        self._last_write = time.monotonic()

    def _write_raw(self, fields, raw=None):
        if self._finished or self.run_id is None:
            return
        raw = raw or {}
        assignments = []
        params = []
        # Raw entries may be columns that are not in ``fields`` (phase_started_at,
        # finished_at): they are expressions, not values.
        for column in raw:
            assignments.append(f"`{column}` = {raw[column]}")
        for column, value in fields.items():
            if column in raw:
                continue
            if column in _RUN_COLUMNS:
                assignments.append(f"`{column}` = %s")
                params.append(value)
        if 'heartbeat_at' not in raw:
            assignments.append("`heartbeat_at` = NOW()")
        if not assignments:
            return
        params.append(self.run_id)
        self._pub.execute(
            "UPDATE indexer_runs SET " + ', '.join(assignments) + " WHERE id = %s",
            tuple(params),
        )

    def progress(self, force=False, **fields):
        """Update progress fields, at most once every PROGRESS_INTERVAL seconds.

        ``files_scanned`` and the phase counters arrive per file; without the
        throttle a large archive would turn a progress bar into one UPDATE per
        frame. Phase boundaries call force=True through phase() / finish().
        """
        now = time.monotonic()
        if not force and now - self._last_write < PROGRESS_INTERVAL:
            return
        self._last_write = now
        self._write(fields)

    def finish(self, status='done', error_count=None, last_error=None, **fields):
        if self._finished or self.run_id is None:
            self._finished = True
            return
        payload = dict(fields)
        payload['status'] = str(status)[:16]
        if error_count is not None:
            payload['error_count'] = int(error_count)
        if last_error is not None:
            payload['last_error'] = str(last_error)[:2000]
        self._write_raw(payload, raw={'finished_at': 'NOW()'})
        # The start-time prune sees one fewer finished row than the table holds
        # after this run closes, so prune again here: the table keeps exactly the
        # newest RUN_HISTORY finished runs between runs, not RUN_HISTORY + 1.
        self._prune_history()
        self._finished = True
        self._pub.close()

    def close(self):
        self._pub.close()


class WatcherStatus:
    """The singleton row of watch_fs.py.

    States describe what the watcher is doing, not just that it exists:
    ``idle`` waits, ``scanning`` walks the archive, ``scheduled`` has a reindex
    waiting for the cooldown, ``running`` spawned it, ``working`` is a
    project-suggestion backfill (which can block the loop for minutes), and
    ``error``/``stopped`` say the rest.
    """

    def __init__(self, db_params, config=None):
        self._pub = _Publisher(db_params)
        self._state = None
        self._last_write = 0.0
        self._last_config = _json_config(config)
        try:
            self._upsert('starting', force=True, started_at_raw='NOW()')
        except Exception as exc:  # noqa: BLE001 - status must not stop the watcher
            logger.warning("Watcher status publisher unavailable: %s", exc)
            self._pub.close()

    def _upsert(self, state=None, force=False, fields=None, started_at_raw=None):
        fields = dict(fields or {})
        if state is not None:
            fields['state'] = state
        changed = state is not None and state != self._state
        now = time.monotonic()
        if not force and not changed and now - self._last_write < WATCHER_HEARTBEAT_INTERVAL:
            return
        self._last_write = now
        if state is not None:
            self._state = state

        columns = ['id', 'seen_at']
        params = []
        values = ['1', 'NOW()']
        if started_at_raw:
            columns.append('started_at')
            values.append(started_at_raw)
        for column, value in fields.items():
            if column in _WATCHER_COLUMNS:
                columns.append(column)
                values.append('%s')
                params.append(value)
        # config changes only at startup; include it on the insert and keep it on
        # the update so a restart refreshes it.
        if 'config' not in fields and self._last_config is not None:
            columns.append('config')
            values.append('%s')
            params.append(self._last_config)
        insert_columns = ', '.join(f'`{column}`' for column in columns)
        insert_values = ', '.join(values)
        update_parts = [
            f"`{column}` = VALUES(`{column}`)"
            for column in columns if column != 'id'
        ]
        self._pub.execute(
            f"INSERT INTO indexer_watcher ({insert_columns}) VALUES ({insert_values}) "
            f"ON DUPLICATE KEY UPDATE " + ', '.join(update_parts),
            tuple(params),
        )

    def update(self, state=None, force=False, **fields):
        self._upsert(state=state, force=force, fields=fields)

    def heartbeat(self):
        """Keep the row fresh while the loop is idle; state is untouched."""
        self._upsert(force=False)

    def scanned(self, known_files):
        """A filesystem sweep finished; its count is the archive's size."""
        self._upsert(
            force=True,
            fields={'known_files': int(known_files)},
        )
        self._pub.execute(
            "UPDATE indexer_watcher SET last_scan_at = NOW() WHERE id = 1"
        )

    def close(self):
        self._pub.close()
