#!/usr/bin/env python3
import os
import sys
import mysql.connector
from astropy.io import fits
import numpy as np
from PIL import Image
import argparse
from io import BytesIO
import logging
from datetime import datetime

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    stream=sys.stdout  # Ensure logs go to stdout for Docker logging
)
logger = logging.getLogger('reindex')

# --- Parameter parser ---
parser = argparse.ArgumentParser(
    description="Reindex FITS files in MariaDB and generate thumbnails."
)
parser.add_argument("fits_root", help="Root directory containing FITS files")
parser.add_argument("--host", default=os.getenv("DB_HOST", "mariadb"), help="MariaDB host")
parser.add_argument("--user", default=os.getenv("DB_USER", "awi_user"), help="Database username")
parser.add_argument("--password", default=os.getenv("DB_PASS", "awi_password"), help="Database password")
parser.add_argument("--database", default=os.getenv("DB_NAME", "awi_db"), help="Database name")
parser.add_argument("--force", action="store_true", help="Force reindexing of existing files")
parser.add_argument("--thumb-size", default="300x300", help="Thumbnail size WxH, e.g. 400x400")
parser.add_argument("--skip-cleanup", action="store_true", help="Skip removal of non-existing files")
args = parser.parse_args()

fits_root = args.fits_root
force_reindex = args.force

try:
    thumb_w, thumb_h = map(int, args.thumb_size.lower().split("x"))
    thumb_size = (thumb_w, thumb_h)
except Exception:
    logger.error("Error: --thumb-size format must be WxH (e.g. 400x400)")
    sys.exit(1)

if not os.path.isdir(fits_root):
    logger.error(f"Error: directory {fits_root} does not exist")
    sys.exit(1)

commit_interval = 50  # commit every N files

# --- Funzione thumbnail ---
def make_thumbnail(data, size=thumb_size):
    data = np.nan_to_num(data)
    p_low, p_high = np.percentile(data, [0.5, 99.5])
    stretched = np.clip((data - p_low) / (p_high - p_low), 0, 1)
    img = (stretched * 255).astype(np.uint8)
    image = Image.fromarray(img)
    image.thumbnail(size)
    
    buf = BytesIO()
    image.save(buf, format='PNG')
    return buf.getvalue()

# --- Database cleanup function ---
def cleanup_missing_files(conn, cur, fits_root):
    logger.info("Starting cleanup of missing files...")
    cur.execute("SELECT path FROM files")
    db_files = cur.fetchall()
    removed_count = 0
    
    for (rel_path,) in db_files:
        full_path = os.path.join(fits_root, rel_path)
        if not os.path.exists(full_path):
            logger.info(f"Removing from DB: {rel_path} (file not found)")
            cur.execute("DELETE FROM files WHERE path = %s", (rel_path,))
            removed_count += 1
            
            if removed_count % commit_interval == 0:
                conn.commit()
                logger.info(f"Committed removal of {removed_count} files")
    
    conn.commit()
    logger.info(f"Cleanup complete. Removed {removed_count} entries for missing files.")

# --- Main execution ---
try:
    logger.info(f"Connecting to database {args.database} on {args.host}")
    conn = mysql.connector.connect(
        host=args.host,
        user=args.user,
        password=args.password,
        database=args.database
    )
    cur = conn.cursor()

    # Cleanup phase
    if not args.skip_cleanup:
        cleanup_missing_files(conn, cur, fits_root)

# --- Indexing phase ---
    logger.info("Starting file indexing...")
    start_time = datetime.now()
    processed_count = 0
    error_count = 0
    skipped_count = 0

    for root, dirs, files in os.walk(fits_root):
        for file in files:
            if file.lower().endswith(('.fits', '.fit')):
                full_path = os.path.join(root, file)
                rel_path = os.path.relpath(full_path, fits_root)
                mtime = os.path.getmtime(full_path)

                cur.execute("SELECT updated_at FROM files WHERE path=%s", (rel_path,))
                row = cur.fetchone()
                if row and not force_reindex:
                    logger.debug(f'Skipping {rel_path}, already exists.')
                    skipped_count += 1
                    continue

                try:
                    with fits.open(full_path) as hdul:
                        header = hdul[0].header
                        data = hdul[0].data

                        object_name = header.get('OBJECT', '').strip() or 'Unknown'
                        date_obs = header.get('DATE-OBS', '')
                        exptime = header.get('EXPTIME', 0)
                        filt = header.get('FILTER', '')
                        imgtype = header.get('IMAGETYP', '').upper() or 'UNKNOWN'

                        thumb = make_thumbnail(data) if data is not None else None

                        cur.execute('''
                            INSERT INTO files
                            (path, name, object, date_obs, exptime, filter, imgtype, thumb)
                            VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
                            ON DUPLICATE KEY UPDATE
                            name=%s, object=%s, date_obs=%s, exptime=%s, 
                            filter=%s, imgtype=%s, thumb=%s
                        ''', (rel_path, file, object_name, date_obs, exptime, filt, imgtype, thumb,
                              file, object_name, date_obs, exptime, filt, imgtype, thumb))
                        
                        processed_count += 1
                        if processed_count % commit_interval == 0:
                            conn.commit()
                            logger.info(f'Progress: {processed_count} files processed.')

                        logger.debug(f'Processed: {rel_path}')

                except Exception as e:
                    logger.error(f'Error processing {rel_path}: {e}')
                    error_count += 1

    conn.commit()

    # Final statistics
    duration = datetime.now() - start_time
    logger.info("=== Indexing Complete ===")
    logger.info(f"Duration: {duration}")
    logger.info(f"Files processed: {processed_count}")
    logger.info(f"Files skipped: {skipped_count}")
    logger.info(f"Errors encountered: {error_count}")

except mysql.connector.Error as err:
    logger.error(f"Database error: {err}")
    sys.exit(1)
except Exception as e:
    logger.error(f"Unexpected error: {e}")
    sys.exit(1)
finally:
    if 'conn' in locals():
        conn.close()