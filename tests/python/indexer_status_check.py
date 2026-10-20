#!/usr/bin/env python3
"""Unit checks for indexer_lib.status (no database, no indexing).

The publisher is the only new code that runs inside every index pass, so its
failure modes are the interesting part: it must build valid SQL (a duplicated
backtick in an ON DUPLICATE KEY UPDATE is enough to disable it silently), it must
throttle the per-file updates, it must close a run with an expression instead of
a timestamp, and it must disappear without raising when the migration has not
run yet.

The fake connection records SQL instead of executing it, so this test can run
against any database and cannot leave a status row behind.

Usage:
  docker cp tests/python/indexer_status_check.py awi-python:/tmp/
  docker exec -e AWI_PROJECTS_LIB=/opt/scripts awi-python \
    sh -c 'cd /tmp && python indexer_status_check.py'
"""

import os
import sys
import types

sys.path.insert(0, os.environ.get("AWI_PROJECTS_LIB", "/opt/scripts"))

import indexer_lib.status as status

FAILED = []


def check(label, cond, detail=""):
    print(f"  {label:<58} {detail}  {'OK' if cond else '<<< FAILED'}")
    if not cond:
        FAILED.append(label)


class FakeError(Exception):
    def __init__(self, errno=None, message=""):
        super().__init__(message or f"error {errno}")
        self.errno = errno


class FakeCursor:
    def __init__(self, log, fail=False):
        self.log = log
        self.fail = fail
        self.lastrowid = 1
        self.rowcount = 1
        self._rows = []

    def execute(self, sql, params=()):
        if self.fail:
            raise FakeError(1146, "Table 'awi_db.indexer_runs' doesn't exist")
        self.log.append((" ".join(sql.split()), tuple(params)))
        if "SELECT id FROM indexer_runs" in sql:
            self._rows = []

    def fetchall(self):
        return self._rows

    def close(self):
        pass


class FakeConn:
    def __init__(self, log, fail=False):
        self.log = log
        self.fail = fail
        self.closed = False

    def cursor(self):
        return FakeCursor(self.log, self.fail)

    def ping(self, reconnect=True, attempts=1, delay=0):
        if self.closed:
            raise RuntimeError("closed")

    def close(self):
        self.closed = True


def install_fake(fail=False):
    log = []
    fake = types.SimpleNamespace(
        connector=types.SimpleNamespace(connect=lambda **kwargs: FakeConn(log, fail))
    )
    status.mysql = fake
    return log


PARAMS = {"host": "h", "user": "u", "password": "p", "database": "d"}

# ---------------------------------------------------------------------------
# 1. a run: insert, phase boundary, throttled progress, finish
# ---------------------------------------------------------------------------
print("\n=== run lifecycle ===\n")
log = install_fake()

run = status.RunStatus(PARAMS, reason="created", phase_mode="two", config={"x": 1})
check("the run gets an id", run.run_id == 1, str(run.run_id))
check("the insert is an indexer_runs INSERT",
      any("INSERT INTO indexer_runs" in sql for sql, _ in log), "")
check("the stale-running rows are closed first",
      any("status = 'interrupted'" in sql for sql, _ in log), "")

log.clear()
run.phase("headers", total=100)
phase_sql = log[-1][0]
check("a phase starts phase_started_at with NOW()",
      "`phase_started_at` = NOW()" in phase_sql, phase_sql)
check("a phase update refreshes the heartbeat",
      "`heartbeat_at` = NOW()" in phase_sql, "")
check("a phase update carries the total", 100 in log[-1][1], str(log[-1][1]))

log.clear()
run.progress(force=True, phase_done=1, files_scanned=1)
first = len(log)
run.progress(phase_done=2, files_scanned=2)
check("a forced progress write is emitted", first == 1, str(first))
check("an immediate second progress write is throttled",
      len(log) == first, f"{len(log)} writes")
run.progress(force=True, phase_done=50, error_count=2, last_error="bad.fits")
check("progress can carry an error",
      any("`last_error` = %s" in sql for sql, _ in log), "")

log.clear()
run.finish("done", error_count=2, files_done=5, metrics_done=4)
finish_sql = next(sql for sql, _ in log if "UPDATE indexer_runs SET" in sql)
finish_params = next(params for sql, params in log if "UPDATE indexer_runs SET" in sql)
check("finish writes finished_at with NOW(), not a value",
      "`finished_at` = NOW()" in finish_sql, finish_sql)
check("finish writes the counters", 5 in finish_params and 4 in finish_params,
      str(finish_params))
check("finish prunes the history to five", any("DELETE FROM indexer_runs" in sql for sql, _ in log), "")
check("finish is idempotent", run.finish("error") is None, "")

# ---------------------------------------------------------------------------
# 2. watcher: the upsert must be valid SQL (single backticks in VALUES())
# ---------------------------------------------------------------------------
print("\n=== watcher lifecycle ===\n")
log = install_fake()
watcher = status.WatcherStatus(PARAMS, config={"poll": 30})
init_sql = log[-1][0]
check("watcher insert uses an upsert", "ON DUPLICATE KEY UPDATE" in init_sql, "")
check("VALUES() has exactly one backtick pair",
      "`state` = VALUES(`state`)" in init_sql and "``" not in init_sql, init_sql)
watcher.update("idle", force=True, known_files=42)
check("a state update is written",
      any("`state` = VALUES(`state`)" in sql for sql, _ in log), "")
log.clear()
watcher.scanned(43)
check("a scan stamps last_scan_at with NOW()",
      any("last_scan_at = NOW()" in sql for sql, _ in log), "")
watcher.update("stopped", force=True)
check("the stopped state is recorded",
      any("`state` = VALUES(`state`)" in sql and "stopped" in params
          for sql, params in log), "")
watcher.close()

# ---------------------------------------------------------------------------
# 3. missing tables: disable, do not raise, do not keep trying
# ---------------------------------------------------------------------------
print("\n=== missing migration ===\n")
log = install_fake(fail=True)
try:
    missing = status.RunStatus(PARAMS, reason="test")
    raised = False
except Exception as exc:  # noqa: BLE001 - the assertion is "nothing escapes"
    missing = None
    raised = True
    print("  raised:", exc)
check("a missing status table does not raise", not raised, "")
check("the run is reported as unavailable", missing.run_id is None, "")
check("the unavailable run is already finished", missing._finished is True, "")
log.clear()
missing.progress(force=True, phase_done=1)
missing.finish("done")
check("later writes are no-ops", log == [], f"{len(log)} writes")

print("\nRESULT:", "OK" if not FAILED else f"{len(FAILED)} FAILED")
if FAILED:
    print("FAILED:", ", ".join(FAILED))
    sys.exit(1)
