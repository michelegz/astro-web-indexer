#!/usr/bin/env python3
import time
import sys
import os
import argparse
import json
import logging
import subprocess
import platform
from pathlib import Path
from watchdog.observers import Observer

# Long-lived connection for the suggestion queue, plus the clock of the last
# stale-running reset. Module level because the queue poll is called from the main
# loop every second and must not rebuild either one per call.
_suggest_conn = None
_last_stale_reset = 0.0
# The reset only matters after a worker crash, so once a minute is plenty.
_STALE_RESET_EVERY = 60.0
try:
    from watchdog.observers.polling import PollingObserver
except ImportError:
    PollingObserver = None
from watchdog.events import FileSystemEventHandler, FileSystemEvent

# Configure logging
logging.basicConfig(
    level=logging.INFO, # Changed default level to INFO for better feedback
    format='%(asctime)s - %(levelname)s - %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)

VALID_EXTS = {".fits", ".fit", ".fz", ".xisf"}

# Filesystems that do not propagate inotify events (network/virtualized mounts).
# Docker Desktop on Windows exposes bind mounts via fuse.grpcfuse (gRPC-FUSE), 9p, or
# virtiofs depending on the backend; inotify never fires even though Observer starts fine.
# Any fuse.* type is also treated as incompatible since most FUSE drivers don't implement
# inotify, and the specific variant can change across Docker Desktop versions.
_NO_INOTIFY_FS = {"9p", "virtiofs", "vboxsf", "cifs", "smbfs", "nfs", "nfs4"}

def _inotify_supported(path: str) -> bool:
    """Return True if the filesystem hosting *path* supports inotify."""
    try:
        real = os.path.realpath(path)
        best_mp, best_fs = "", ""
        with open("/proc/mounts") as f:
            for line in f:
                parts = line.split()
                if len(parts) >= 3:
                    mp, fs = parts[1], parts[2]
                    if real.startswith(mp) and len(mp) > len(best_mp):
                        best_mp, best_fs = mp, fs
        fs_lower = best_fs.lower()
        if fs_lower in _NO_INOTIFY_FS or fs_lower.startswith("fuse."):
            logging.info(f"Filesystem type '{best_fs}' detected at '{best_mp}' — inotify not supported.")
            return False
    except Exception:
        pass  # /proc/mounts not available (non-Linux); default to True
    return True


class FitsHandler(FileSystemEventHandler):
    def __init__(self, fits_dir, reindex_script, db_params, rescan_interval=5, debug=False, retention_days=30, thumb_size=300):
        self.fits_dir = Path(fits_dir)
        self.reindex_script = Path(reindex_script)
        self.db_params = db_params
        self.debug = debug
        self.retention_days = retention_days
        self.thumb_size = thumb_size
        self.pending_reindex = False
        self.last_reindex = 0
        self.cooldown = 10  # Seconds after a successful reindex
        self.cooldown_max = 300  # Ceiling for the backoff after repeated failures
        self.reindex_failures = 0
        self.reindex_reason = "startup"
        self.rescan_interval = float(rescan_interval)
        self.last_scan = 0.0
        self.known_files = self._initial_scan()
        logging.info(f"Initial known files count: {len(self.known_files)}")

    def _normalize_path(self, p):
        try:
            rp = Path(p).resolve()
        except Exception:
            rp = Path(p).absolute()
        return os.path.normcase(str(rp))

    def _is_valid_file(self, path):
        try:
            return Path(path).suffix.lower() in VALID_EXTS
        except Exception:
            return False

    def _initial_scan(self):
        result = set()
        try:
            for p in self.fits_dir.rglob("*"):
                try:
                    if p.is_file() and p.suffix.lower() in VALID_EXTS:
                        result.add(self._normalize_path(p))
                except Exception:
                    continue
        except Exception as e:
            logging.error(f"Initial scan error: {e}")
        return result

    def dispatch(self, event):
        if not isinstance(event, FileSystemEvent):
            logging.warning(f"Unexpected event type: {type(event)}")
            return
        logging.debug(f"DISPATCH: {event.event_type} | is_dir={event.is_directory} | src={event.src_path}")
        super().dispatch(event)

    def _log_and_schedule(self, src_path, reason):
        try:
            rel_path = Path(src_path).relative_to(self.fits_dir)
        except Exception:
            rel_path = src_path
        logging.info(f"{reason}: {rel_path}")
        self.schedule_reindex(reason)

    def on_created(self, event):
        if event.is_directory: return
        if self._is_valid_file(event.src_path):
            self.known_files.add(self._normalize_path(event.src_path))
            self._log_and_schedule(event.src_path, "file creation")

    def on_modified(self, event):
        if event.is_directory: return
        if self._is_valid_file(event.src_path):
            self._log_and_schedule(event.src_path, "file modification")

    def on_moved(self, event):
        if event.is_directory: return
        src_norm = self._normalize_path(event.src_path)
        dest_norm = self._normalize_path(event.dest_path)
        if self._is_valid_file(src_norm):
            self.known_files.discard(src_norm)
        if self._is_valid_file(dest_norm):
            self.known_files.add(dest_norm)
        self._log_and_schedule(event.dest_path, "file move")

    def on_deleted(self, event):
        if event.is_directory: return
        if self._is_valid_file(event.src_path):
            self.known_files.discard(self._normalize_path(event.src_path))
            self._log_and_schedule(event.src_path, "file deletion")

    def scan_and_detect(self):
        """Periodic safety net for the events inotify missed.

        inotify can drop events when its queue overflows, and the observer only sees
        what it is told: a file created while the queue was full stays invisible
        forever, because nothing else walks the tree. This is that walk, rate limited
        by self.rescan_interval so the main loop can call it every iteration.

        It was dead code with the rescan plumbing fully wired behind it: --rescan-
        interval was parsed, stored and used only here, so an operator setting
        RESCAN_INTERVAL was told by the help text that deletions are detected
        periodically and nothing was checking.
        """
        now = time.time()
        if now - self.last_scan < self.rescan_interval:
            return
        self.last_scan = now
        try:
            current = set()
            for p in self.fits_dir.rglob("*"):
                try:
                    if p.is_file() and p.suffix.lower() in VALID_EXTS:
                        current.add(self._normalize_path(p))
                except Exception:
                    continue
            created = current - self.known_files
            deleted = self.known_files - current
            if created:
                logging.info(f"Scan detected {len(created)} created file(s)")
                self.known_files.update(created)
                self.schedule_reindex("scan creation")
            if deleted:
                logging.info(f"Scan detected {len(deleted)} deleted file(s)")
                for p in deleted: self.known_files.discard(p)
                self.schedule_reindex("scan deletion")
        except Exception as e:
            logging.error(f"Rescan error: {e}")

    def schedule_reindex(self, reason="unknown"):
        self.pending_reindex = True
        self.reindex_reason = reason
        logging.info(f"Reindex scheduled due to {reason}.")

    def check_and_reindex(self):
        if not self.pending_reindex:
            return
        current_time = time.time()
        if current_time - self.last_reindex < self.cooldown:
            return
        logging.info(f"Cooldown elapsed, starting reindex (reason: {self.reindex_reason})")
        try:
            cmd = [
                sys.executable, str(self.reindex_script), str(self.fits_dir),
                "--host", self.db_params["host"], "--user", self.db_params["user"],
                "--password", self.db_params["password"], "--database", self.db_params["database"],
                "--retention-days", str(self.retention_days),
                "--thumb-size", str(self.thumb_size)
            ]
            if self.debug:
                cmd.append("--debug")
            subprocess.run(cmd, check=True)
            logging.info("Reindexing completed successfully")
            # Stamp the attempt either way. On failure pending_reindex stays True,
            # so without this the next second re-launches the whole archive walk
            # and DB scan: reindex.py exits non-zero exactly when the DB is down,
            # which is when hammering it is worst.
            self.last_reindex = current_time
            self.pending_reindex = False
            self.reindex_failures = 0
            self.cooldown = 10
        except (subprocess.CalledProcessError, OSError, subprocess.TimeoutExpired) as e:
            self.last_reindex = current_time
            self.reindex_failures += 1
            # Exponential backoff, capped: a persistent failure costs one attempt
            # per window instead of one per second.
            self.cooldown = min(self.cooldown_max, self.cooldown * 2)
            logging.error(
                f"Reindex failed ({self.reindex_failures} in a row, "
                f"next attempt in {self.cooldown}s): {e}"
            )


def process_suggest_queue(db_params):
    """Claim one pending re-suggest request and run its per-project backfill.

    The web container only enqueues rows (see enqueueSuggestRequest in PHP):
    this watcher owns execution. Runs are per-project and idempotent, so a
    crash simply leaves the row for the stale-running reset below.
    Returns True when a request was processed.

    The connection is cached across calls: this runs about once a second for the
    lifetime of the process, and opening and closing a connection each time meant a
    full handshake per second (~86 400 a day) against a table that is usually empty.
    A cached connection that goes bad is dropped once and reopened on the next call.
    """
    # Both module-level names are rebound below, so without this they would be local
    # to the function and the first read would raise UnboundLocalError.
    global _suggest_conn, _last_stale_reset
    try:
        import mysql.connector
    except ImportError as e:
        logging.error(f"Suggest queue skipped (mysql driver missing): {e}")
        return False
    try:
        from indexer_lib.projects import suggest_projects_backfill
    except ImportError as e:
        logging.error(f"Suggest queue skipped (indexer_lib missing): {e}")
        return False
    conn = _suggest_conn
    if conn is not None:
        try:
            conn.ping(reconnect=True, attempts=1, delay=0)
        except Exception:
            conn = None
            _suggest_conn = None
    if conn is None:
        try:
            conn = mysql.connector.connect(
                host=db_params["host"], user=db_params["user"],
                password=db_params["password"], database=db_params["database"],
            )
        except Exception as e:
            logging.warning(f"Suggest queue skipped (db unreachable): {e}")
            return False
        _suggest_conn = conn
    try:
        cur = conn.cursor(dictionary=True)
        # Rows stuck in running (worker crash) go back to pending after 30 min. Only
        # relevant to a crash, so this runs at most once a minute: as a write on
        # every one-second iteration it was a pointless UPDATE against a quiescent
        # table, and it woke the redo log for nothing.
        now = time.time()
        if now - _last_stale_reset >= _STALE_RESET_EVERY:
            try:
                cur.execute(
                    "UPDATE suggest_requests SET status = 'pending', started_at = NULL "
                    "WHERE status = 'running' AND started_at < (NOW() - INTERVAL 30 MINUTE)"
                )
                conn.commit()
            except Exception:
                # A failed statement leaves the transaction open on a connection that
                # outlives this call, so release it before carrying on.
                try:
                    conn.rollback()
                except Exception:
                    pass
            _last_stale_reset = now
        cur.execute(
            "SELECT id, project_id FROM suggest_requests "
            "WHERE status = 'pending' ORDER BY id ASC LIMIT 1"
        )
        row = cur.fetchone()
        if row is None:
            # Nothing to claim. This is the path that runs once a second, and it returns
            # without committing: whatever transaction was left open on the cached
            # connection (a failed backfill write, for instance) would stay open for the
            # next call, one second later, holding its snapshot and any row locks.
            try:
                conn.rollback()
            except Exception:
                pass
            return False
        req_id, project_id = row['id'], row['project_id']
        cur.execute(
            "UPDATE suggest_requests SET status = 'running', started_at = NOW() "
            "WHERE id = %s AND status = 'pending'",
            (req_id,),
        )
        conn.commit()
        if cur.rowcount != 1:
            return False
        logging.info(f"Suggest backfill starting (request {req_id}, project {project_id})")
        try:
            counts = suggest_projects_backfill(conn, project_id)
        except Exception as e:
            logging.error(f"Suggest backfill failed (request {req_id}): {e}")
            try:
                cur.execute(
                    "UPDATE suggest_requests SET status = 'error', finished_at = NOW(), "
                    "result = %s WHERE id = %s",
                    (json.dumps({'error': str(e)[:500]}), req_id),
                )
                conn.commit()
            except Exception:
                pass
            return True
        try:
            cur.execute(
                "UPDATE suggest_requests SET status = 'done', finished_at = NOW(), "
                "result = %s WHERE id = %s",
                (json.dumps(counts), req_id),
            )
            conn.commit()
        except Exception as e:
            logging.error(f"Suggest queue result write failed (request {req_id}): {e}")
        logging.info(f"Suggest backfill done (request {req_id}, project {project_id}): {counts}")
        return True
    except Exception as e:
        # Same reasoning as the empty-queue return: the connection is cached, so it must
        # not carry this call's failed transaction into the next one.
        try:
            conn.rollback()
        except Exception:
            pass
        # Missing table (migrations not applied yet) is normal on fresh
        # installs: stay quiet instead of spamming the log every second.
        if 'suggest_requests' in str(e) and ('1146' in str(e) or "doesn't exist" in str(e)):
            logging.debug("Suggest queue table missing, skipping.")
        else:
            logging.warning(f"Suggest queue check failed: {e}")
        return False


def main():
    parser = argparse.ArgumentParser(description="Monitor a directory for files and trigger reindex.")
    parser.add_argument("fits_dir", help="Directory to monitor")
    parser.add_argument("--reindex-script", default=os.getenv("REINDEX_SCRIPT", "/opt/scripts/reindex.py"),help="Path to the reindex script")
    parser.add_argument("--db-host", default=os.getenv("DB_HOST", "mariadb"), help="MariaDB host")
    parser.add_argument("--db-user", default=os.getenv("DB_USER", "awi_user"), help="Database username")
    parser.add_argument("--db-password", default=os.getenv("DB_PASSWORD", os.getenv("DB_PASS", "awi_password")), help="Database password")
    parser.add_argument("--db-name", default=os.getenv("DB_NAME", "awi_db"), help="Database name")
    parser.add_argument("--rescan-interval", default=float(os.getenv("RESCAN_INTERVAL", 5)), type=float,help="Interval in seconds for periodic directory rescan to detect deletions (default: 5s)")
    parser.add_argument("--poll-interval", default=float(os.getenv("POLL_INTERVAL", 30)), type=float, help="Polling interval in seconds when using PollingObserver (default: 30s)")
    parser.add_argument("--force-polling", action="store_true", default=os.getenv("FORCE_POLLING", "false").lower() in ("true", "1"), help="Force use of PollingObserver even if inotify is available")
    args = parser.parse_args()

    is_debug = os.getenv('DEBUG', 'false').lower() in ('true', '1')
    retention_days = int(os.getenv('RETENTION_DAYS', 30))
    thumb_size = int(os.getenv('THUMB_SIZE', 300))

    if not os.path.isdir(args.fits_dir):
        logging.error(f"Directory {args.fits_dir} does not exist")
        sys.exit(1)
    if not os.path.isfile(args.reindex_script):
        logging.error(f"Reindex script {args.reindex_script} does not exist")
        sys.exit(1)
    db_params = { "host": args.db_host, "user": args.db_user, "password": args.db_password, "database": args.db_name }

    if PollingObserver is None:
        logging.error("PollingObserver not available. Please reinstall watchdog.")
        sys.exit(1)

    # Use inotify (native Observer) only when the filesystem actually supports it.
    # Docker Desktop on Windows exposes bind mounts via 9p/virtiofs: inotify is present
    # in the kernel but events from the Windows host are never delivered, so we must
    # fall back to PollingObserver in that case.
    use_polling = args.force_polling or not _inotify_supported(args.fits_dir)
    if use_polling:
        observer = PollingObserver(timeout=args.poll_interval)
        reason = "forced via --force-polling" if args.force_polling else "filesystem does not support inotify"
        logging.info(f"Using PollingObserver ({reason}, interval: {args.poll_interval}s).")
    else:
        observer = Observer()
        logging.info("Using native Observer (inotify). Zero CPU overhead in idle.")
    
    event_handler = FitsHandler(
        args.fits_dir, args.reindex_script, db_params,
        rescan_interval=args.rescan_interval, debug=is_debug, retention_days=retention_days, thumb_size=thumb_size
    )
    observer.schedule(event_handler, args.fits_dir, recursive=True)
    observer.start()

    logging.info(f"Started monitoring directory {args.fits_dir}")
    try:
        while True:
            event_handler.check_and_reindex()
            # Catches what inotify dropped. Self-throttled by rescan_interval, so
            # calling it every iteration costs at most one walk per interval.
            event_handler.scan_and_detect()
            # Per-iteration guard: a failure in the queue poll or in the watcher
            # must not take the whole monitor down. Previously only KeyboardInterrupt
            # was handled, so a transient error ended the process silently.
            try:
                process_suggest_queue(db_params)
            except Exception as e:
                logging.error(f"Suggest queue error: {e}")
            time.sleep(1)
    except KeyboardInterrupt:
        observer.stop()
        logging.info("Monitoring interrupted by user")
    observer.join()

if __name__ == "__main__":
    main()

