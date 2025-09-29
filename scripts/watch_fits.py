#!/usr/bin/env python3
import time
import sys
import os
import argparse
import logging
import subprocess
from watchdog.observers import Observer
from watchdog.events import FileSystemEventHandler
from pathlib import Path

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)

class FitsHandler(FileSystemEventHandler):
    def __init__(self, fits_dir, reindex_script, db_params):
        self.fits_dir = Path(fits_dir)
        self.reindex_script = Path(reindex_script)
        self.db_params = db_params
        self.pending_reindex = False
        self.last_reindex = 0
        self.cooldown = 30  # Seconds to wait between reindexes

    def on_created(self, event):
        if event.is_directory:
            return
        if event.src_path.lower().endswith('.fits'):
            self.schedule_reindex()

    def on_modified(self, event):
        if event.is_directory:
            return
        if event.src_path.lower().endswith('.fits'):
            self.schedule_reindex()

    def on_moved(self, event):
        if event.is_directory:
            return
        if event.dest_path.lower().endswith('.fits'):
            self.schedule_reindex()

    def schedule_reindex(self):
        """Schedule a reindex if none is currently pending."""
        self.pending_reindex = True

    def check_and_reindex(self):
        """Execute reindex if needed and enough time has passed since last reindex."""
        if not self.pending_reindex:
            return

        current_time = time.time()
        if current_time - self.last_reindex < self.cooldown:
            return

        try:
            logging.info("Starting reindexing...")
            cmd = [
                "python",
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
            # Keep pending_reindex = True to retry in next cycle

def main():
    parser = argparse.ArgumentParser(description="Monitor a directory for FITS files and trigger reindex when needed")
    parser.add_argument("fits_dir", help="Directory to monitor for FITS files")
    parser.add_argument("--reindex-script", default="/opt/scripts/reindex_mariadb.py",
                      help="Path to the reindex script")
    parser.add_argument("--db-host", default="mariadb",
                      help="MariaDB host")
    parser.add_argument("--db-user", default="awi_user",
                      help="Database username")
    parser.add_argument("--db-password", default="awi_password",
                      help="Database password")
    parser.add_argument("--db-name", default="awi_db",
                      help="Database name")
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

    event_handler = FitsHandler(args.fits_dir, args.reindex_script, db_params)
    observer = Observer()
    observer.schedule(event_handler, args.fits_dir, recursive=True)
    observer.start()

    logging.info(f"Started monitoring directory {args.fits_dir}")
    
    try:
        while True:
            event_handler.check_and_reindex()
            time.sleep(1)
    except KeyboardInterrupt:
        observer.stop()
        logging.info("Monitoring interrupted by user")
    
    observer.join()

if __name__ == "__main__":
    main()