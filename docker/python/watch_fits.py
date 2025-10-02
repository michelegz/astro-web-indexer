#!/usr/bin/env python3
import time
import sys
import os
import argparse
import logging
import subprocess
import platform
from pathlib import Path
from watchdog.observers import Observer
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

VALID_EXTS = {".fits", ".fit", ".xisf"}

class FitsHandler(FileSystemEventHandler):
    def __init__(self, fits_dir, reindex_script, db_params, rescan_interval=5, debug=False, retention_days=30):
        self.fits_dir = Path(fits_dir)
        self.reindex_script = Path(reindex_script)
        self.db_params = db_params
        self.debug = debug
        self.retention_days = retention_days
        self.pending_reindex = False
        self.last_reindex = 0
        self.cooldown = 10  # Reduced cooldown
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
        if not self.pending_reindex: return
        current_time = time.time()
        if current_time - self.last_reindex < self.cooldown:
            return
        logging.info(f"Cooldown elapsed, starting reindex (reason: {self.reindex_reason})")
        try:
            cmd = [
                sys.executable, str(self.reindex_script), str(self.fits_dir),
                "--host", self.db_params["host"], "--user", self.db_params["user"],
                "--password", self.db_params["password"], "--database", self.db_params["database"],
                "--retention-days", str(self.retention_days)
            ]
            if self.debug: cmd.append("--debug")
            subprocess.run(cmd, check=True)
            logging.info("Reindexing completed successfully")
            self.last_reindex = current_time
            self.pending_reindex = False
        except subprocess.CalledProcessError as e:
            logging.error(f"Error during reindexing: {e}")

def main():
    parser = argparse.ArgumentParser(description="Monitor a directory for files and trigger reindex.")
    parser.add_argument("fits_dir", help="Directory to monitor")
    parser.add_argument("--reindex-script", default=os.getenv("REINDEX_SCRIPT", "/opt/scripts/reindex_mariadb.py"),help="Path to the reindex script")
    parser.add_argument("--db-host", default=os.getenv("DB_HOST", "mariadb"), help="MariaDB host")
    parser.add_argument("--db-user", default=os.getenv("DB_USER", "awi_user"), help="Database username")
    parser.add_argument("--db-password", default=os.getenv("DB_PASS", "awi_password"), help="Database password")
    parser.add_argument("--db-name", default=os.getenv("DB_NAME", "awi_db"), help="Database name")
    parser.add_argument("--rescan-interval", default=float(os.getenv("RESCAN_INTERVAL", 5)), type=float,help="Interval in seconds for periodic directory rescan to detect deletions (default: 5s)")
    args = parser.parse_args()

    is_debug = os.getenv('DEBUG', 'false').lower() in ('true', '1')
    retention_days = int(os.getenv('RETENTION_DAYS', 30))

    if not os.path.isdir(args.fits_dir):
        logging.error(f"Directory {args.fits_dir} does not exist")
        sys.exit(1)
    if not os.path.isfile(args.reindex_script):
        logging.error(f"Reindex script {args.reindex_script} does not exist")
        sys.exit(1)
    db_params = { "host": args.db_host, "user": args.db_user, "password": args.db_password, "database": args.db_name }

    # Force PollingObserver for reliability in Docker environments
    if PollingObserver is None:
        logging.error("PollingObserver not available. Please install watchdog.")
        sys.exit(1)
    observer = PollingObserver()
    logging.info("Using PollingObserver for reliability across all platforms.")
    
    event_handler = FitsHandler(
        args.fits_dir, args.reindex_script, db_params,
        rescan_interval=args.rescan_interval, debug=is_debug, retention_days=retention_days
    )
    observer.schedule(event_handler, args.fits_dir, recursive=True)
    observer.start()

    logging.info(f"Started monitoring directory {args.fits_dir}")
    try:
        while True:
            event_handler.check_and_reindex()
            # The polling observer handles this, but we keep the scan for safety
            event_handler.scan_and_detect()
            time.sleep(1)
    except KeyboardInterrupt:
        observer.stop()
        logging.info("Monitoring interrupted by user")
    observer.join()

if __name__ == "__main__":
    main()

