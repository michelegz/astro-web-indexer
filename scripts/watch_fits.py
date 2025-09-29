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
    level=logging.WARNING,
    format='%(asctime)s - %(levelname)s - %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)

VALID_EXTS = {".fits", ".fit"}  # estensioni accettate

class FitsHandler(FileSystemEventHandler):
    def __init__(self, fits_dir, reindex_script, db_params, rescan_interval=5):
        self.fits_dir = Path(fits_dir)
        self.reindex_script = Path(reindex_script)
        self.db_params = db_params
        self.pending_reindex = False
        self.last_reindex = 0
        self.cooldown = 30  # seconds between reindexes
        self.reindex_reason = "startup"

        # scanning state
        self.rescan_interval = float(rescan_interval)
        self.last_scan = 0.0
        self.known_files = self._initial_scan()
        logging.info(f"Initial known files count: {len(self.known_files)}")

    # ---------- utilities ----------
    def _normalize_path(self, p):
        """Return a normalized absolute path string suitable for cross-platform comparison."""
        try:
            rp = Path(p).resolve()
        except Exception:
            rp = Path(p).absolute()
        # normcase lowers case on Windows, leaves unchanged on POSIX
        return os.path.normcase(str(rp))

    def _is_valid_file(self, path):
        try:
            return Path(path).suffix.lower() in VALID_EXTS
        except Exception:
            return False

    def _initial_scan(self):
        """Scan fits_dir and return set of normalized file paths matching VALID_EXTS."""
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

    # ---------- watchdog overrides ----------
    def dispatch(self, event):
        """Log all events before delegating"""
        if not isinstance(event, FileSystemEvent):
            logging.warning(f"Unexpected event type: {type(event)}")
            return

        logging.debug(f"DISPATCH: {event.event_type} | {event.__class__.__name__} | "
                      f"is_dir={event.is_directory} | src={event.src_path} "
                      f"{'-> ' + getattr(event, 'dest_path', '') if hasattr(event, 'dest_path') else ''}")

        super().dispatch(event)

    def handle_directory_event(self, event, action):
        try:
            rel_path = Path(event.src_path).relative_to(self.fits_dir)
        except Exception:
            rel_path = event.src_path
        logging.info(f"Directory {action}: {rel_path}")
        self.schedule_reindex(f"directory {action}")

    def on_created(self, event):
        if event.is_directory:
            self.handle_directory_event(event, "creation")
            return
        if self._is_valid_file(event.src_path):
            norm = self._normalize_path(event.src_path)
            self.known_files.add(norm)
            self._log_and_schedule(event.src_path, "FITS file creation")

    def on_modified(self, event):
        if event.is_directory:
            self.handle_directory_event(event, "modification")
            return
        if self._is_valid_file(event.src_path):
            # update known_files (in case it was missing)
            norm = self._normalize_path(event.src_path)
            self.known_files.add(norm)
            self._log_and_schedule(event.src_path, "FITS file modification")

    def on_moved(self, event):
        src_ext = Path(event.src_path).suffix.lower()
        dest_ext = Path(event.dest_path).suffix.lower() if hasattr(event, "dest_path") else ""

        try:
            # se la destinazione è ancora dentro fits_dir → normale move
            src_rel = Path(event.src_path).relative_to(self.fits_dir)
            dest_rel = Path(event.dest_path).relative_to(self.fits_dir)
            if dest_ext in VALID_EXTS:
                # update known_files: replace src with dest
                try:
                    self.known_files.discard(self._normalize_path(event.src_path))
                    self.known_files.add(self._normalize_path(event.dest_path))
                except Exception:
                    pass
                logging.info(f"FITS file moved inside fits_dir: {src_rel} -> {dest_rel}")
                self.schedule_reindex("FITS file move inside fits_dir")
        except ValueError:
            # move verso fuori della directory monitorata -> treat as deletion of src
            if src_ext in VALID_EXTS:
                logging.info(f"FITS file moved out (treated as deletion): {event.src_path}")
                try:
                    self.known_files.discard(self._normalize_path(event.src_path))
                except Exception:
                    pass
                self.schedule_reindex("FITS file moved out (deletion equivalent)")

    def on_deleted(self, event):
        if event.is_directory:
            self.handle_directory_event(event, "deletion")
            return
        if self._is_valid_file(event.src_path):
            norm = self._normalize_path(event.src_path)
            self.known_files.discard(norm)
            self._log_and_schedule(event.src_path, "FITS file deletion")

    def _log_and_schedule(self, src_path, reason):
        try:
            rel_path = Path(src_path).relative_to(self.fits_dir)
        except Exception:
            rel_path = src_path
        logging.info(f"{reason}: {rel_path}")
        self.schedule_reindex(reason)

    # ---------- scanning fallback ----------
    def scan_and_detect(self):
        """Periodically rescan the directory to detect creations/deletions missed by watchdog."""
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
        except Exception as e:
            logging.error(f"Rescan error: {e}")
            return

        created = current - self.known_files
        deleted = self.known_files - current

        if created:
            logging.info(f"Scan detected {len(created)} created file(s)")
            for p in created:
                logging.debug(f"  + {p}")
            # update known files and schedule (single schedule is enough)
            self.known_files.update(created)
            self.schedule_reindex("scan creation")

        if deleted:
            logging.info(f"Scan detected {len(deleted)} deleted file(s)")
            for p in deleted:
                logging.debug(f"  - {p}")
            # remove deleted entries and schedule
            for p in deleted:
                self.known_files.discard(p)
            self.schedule_reindex("scan deletion")

    # ---------- reindex ----------
    def schedule_reindex(self, reason="unknown"):
        self.pending_reindex = True
        self.reindex_reason = reason
        logging.info(f"Reindex scheduled due to {reason}. Cooldown: {self.cooldown}s, "
                     f"Last reindex: {time.time() - self.last_reindex:.1f}s ago")

    def check_and_reindex(self):
        if not self.pending_reindex:
            return

        current_time = time.time()
        if current_time - self.last_reindex < self.cooldown:
            logging.debug(f"Waiting cooldown: {current_time - self.last_reindex:.1f}s/{self.cooldown}s")
            return

        logging.info(f"Cooldown elapsed, starting reindex (reason: {self.reindex_reason})")

        try:
            cmd = [
                sys.executable,
                str(self.reindex_script),
                str(self.fits_dir),
                "--host", self.db_params["host"],
                "--user", self.db_params["user"],
                "--password", self.db_params["password"],
                "--database", self.db_params["database"]
            ]
            subprocess.run(cmd, check=True)
            logging.info("Reindexing completed successfully")
            self.last_reindex = current_time
            self.pending_reindex = False
        except subprocess.CalledProcessError as e:
            logging.error(f"Error during reindexing: {e}")

def main():
    parser = argparse.ArgumentParser(description="Monitor a directory for FITS files and trigger reindex when needed")
    parser.add_argument("fits_dir", help="Directory to monitor for FITS files")
    parser.add_argument("--reindex-script", default="/opt/scripts/reindex_mariadb.py",
                      help="Path to the reindex script")
    parser.add_argument("--db-host", default="mariadb", help="MariaDB host")
    parser.add_argument("--db-user", default="awi_user", help="Database username")
    parser.add_argument("--db-password", default="awi_password", help="Database password")
    parser.add_argument("--db-name", default="awi_db", help="Database name")
    parser.add_argument("--rescan-interval", default=5, type=float,
                        help="Interval in seconds for periodic directory rescan to detect deletions (default: 5s)")
    args = parser.parse_args()

    if not os.path.isdir(args.fits_dir):
        logging.error(f"Directory {args.fits_dir} does not exist")
        sys.exit(1)
    if not os.path.isfile(args.reindex_script):
        logging.error(f"Reindex script {args.reindex_script} does not exist")
        sys.exit(1)

    db_params = {
        "host": args.db_host,
        "user": args.db_user,
        "password": args.db_password,
        "database": args.db_name
    }

    # Choose observer: native when possible, polling as optional fallback on Windows
    if platform.system() == "Windows" and PollingObserver is not None:
        observer = PollingObserver(timeout=1)
        logging.info("Using PollingObserver (Windows)")
    else:
        observer = Observer()
        logging.info("Using native Observer")

    event_handler = FitsHandler(args.fits_dir, args.reindex_script, db_params, rescan_interval=args.rescan_interval)
    observer.schedule(event_handler, args.fits_dir, recursive=True)
    observer.start()

    logging.info(f"Started monitoring directory {args.fits_dir} (rescan interval {args.rescan_interval}s)")
    try:
        while True:
            # first handle any scheduled reindex via FS events
            event_handler.check_and_reindex()
            # then run the periodic scan fallback to catch missed deletions
            event_handler.scan_and_detect()
            time.sleep(1)
    except KeyboardInterrupt:
        observer.stop()
        logging.info("Monitoring interrupted by user")

    observer.join()


if __name__ == "__main__":
    main()