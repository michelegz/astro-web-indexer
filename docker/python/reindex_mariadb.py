#!/usr/bin/env python3
import os
import sys
import mysql.connector
import xxhash
from astropy.io import fits
from astropy.time import Time
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
    stream=sys.stdout
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
parser.add_argument("--retention-days", type=int, default=os.getenv("RETENTION_DAYS", 30), help="Days to keep soft-deleted files before permanent removal")
parser.add_argument("--debug", action="store_true", help="Enable debug logging")
args = parser.parse_args()

if args.debug:
    logger.setLevel(logging.DEBUG)

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

# --- Hash function ---
def calculate_hash(filepath, block_size=65536):
    """Calculates the xxHash of a file."""
    hasher = xxhash.xxh64()
    with open(filepath, 'rb') as f:
        while True:
            buf = f.read(block_size)
            if not buf:
                break
            hasher.update(buf)
    return hasher.hexdigest()


# --- Funzione thumbnail ---
def make_thumbnail(data, size=thumb_size):
    try:
        data = np.nan_to_num(data)
        p_low, p_high = np.nanpercentile(data, [0.5, 99.5])
        if p_high <= p_low:
            stretched = np.zeros_like(data, dtype=float)
        else:
            stretched = (data - p_low) / (p_high - p_low)
        stretched = np.clip(stretched, 0, 1)
        img = (stretched * 255).astype(np.uint8)
        image = Image.fromarray(img)
        image.thumbnail(size)

        buf = BytesIO()
        image.save(buf, format='PNG')
        return buf.getvalue()
    except Exception as e:
        logger.warning(f"Thumbnail generation failed: {e}")
        return None

# --- Database cleanup functions ---
def soft_delete_missing_files(conn, cur, db_files, disk_files):
    logger.info("Marking missing files as deleted (soft delete)...")
    db_paths = {p for p, f in db_files.items() if f['deleted_at'] is None}
    disk_paths = set(disk_files.keys())

    missing_paths = db_paths - disk_paths
    
    if not missing_paths:
        logger.info("Soft delete complete. No missing files to mark.")
        return

        # Batch update paths to be marked as deleted
    batch_size = 500
    missing_paths_list = list(missing_paths)
    update_time = datetime.now()
    hashes_to_update = set()

    # Get hashes of files to be soft-deleted
    for i in range(0, len(missing_paths_list), batch_size):
        batch_paths = missing_paths_list[i:i+batch_size]
        format_strings = ','.join(['%s'] * len(batch_paths))
        cur.execute(f"SELECT file_hash FROM files WHERE path IN ({format_strings})", tuple(batch_paths))
        for row in cur.fetchall():
            hashes_to_update.add(row[0])

    for i in range(0, len(missing_paths_list), batch_size):
        batch = [(update_time, path) for path in missing_paths_list[i:i+batch_size]]
        cur.executemany("UPDATE files SET deleted_at = %s WHERE path = %s", batch)

    # Update duplicate counts for all affected hashes
    if hashes_to_update:
        logger.info(f"Updating duplicate counts for {len(hashes_to_update)} unique hashes...")
        for file_hash in hashes_to_update:
            update_duplicate_counts(conn, cur, file_hash)

    conn.commit()
    logger.info(f"Soft delete complete. Marked {len(missing_paths)} files as deleted.")

def purge_deleted_files(conn, cur, retention_days):
    if retention_days <= 0:
        logger.info("Purge skipped as retention_days is zero or less.")
        return
        
    logger.info(f"Purging files deleted more than {retention_days} days ago...")

    # First, get the hashes of the files that will be purged
    cur.execute("SELECT DISTINCT file_hash FROM files WHERE deleted_at < NOW() - INTERVAL %s DAY", (retention_days,))
    hashes_to_update = [row[0] for row in cur.fetchall()]

    if not hashes_to_update:
        logger.info("Purge complete. No old files to remove.")
        return

    # Now, delete the files
    cur.execute("DELETE FROM files WHERE deleted_at < NOW() - INTERVAL %s DAY", (retention_days,))
    removed_count = cur.rowcount
    
    if removed_count > 0:
        logger.info(f"Permanently removed {removed_count} files. Updating duplicate counts...")
        
        # Update duplicate counts for the affected hashes
        for file_hash in hashes_to_update:
            update_duplicate_counts(conn, cur, file_hash)
        
        conn.commit()
        logger.info(f"Duplicate counts updated for {len(hashes_to_update)} unique hashes.")
    else:
        logger.info("Purge complete. No old files to remove.")


def update_duplicate_counts(conn, cur, file_hash):
    """Updates the total and visible duplicate counts for all files with the given hash."""
    if not file_hash:
        return

    try:
        # Count total active files with the same hash
        cur.execute(
            "SELECT COUNT(*) FROM files WHERE file_hash = %s AND deleted_at IS NULL",
            (file_hash,)
        )
        total_count = cur.fetchone()[0]

        # Count active (not soft-deleted) and visible files
        cur.execute(
            "SELECT COUNT(*) FROM files WHERE file_hash = %s AND deleted_at IS NULL AND is_hidden = 0",
            (file_hash,)
        )
        visible_count = cur.fetchone()[0]

        # Update all files with this hash
        cur.execute(
            "UPDATE files SET total_duplicate_count = %s, visible_duplicate_count = %s WHERE file_hash = %s",
            (total_count, visible_count, file_hash)
        )

    except mysql.connector.Error as err:
        logger.error(f"Error updating duplicate count for hash {file_hash}: {err}")




def get_header_value(header, key, default=None, type_func=None):
    """Safely get a value from a FITS header, with optional type conversion."""
    val = header.get(key, default)
    if val is None or val == '':
        return default
    if type_func:
        try:
            return type_func(val)
        except (ValueError, TypeError):
            return default
    return val

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

    # --- Pre-load database state ---
    logger.info("Loading existing file data from database...")
    cur.execute("SELECT path, file_hash, mtime, file_size, deleted_at FROM files")
    db_files = {
        row[0]: {'hash': row[1], 'mtime': row[2], 'size': row[3], 'deleted_at': row[4]}
        for row in cur.fetchall()
    }
    db_hashes = {}
    for path, rec in db_files.items():
        db_hashes.setdefault(rec['hash'], []).append(path)

    logger.info(f"Loaded {len(db_files)} records from the database.")


    # --- Indexing phase ---
    logger.info("Starting file indexing...")
    start_time = datetime.now()
    processed_count = 0
    error_count = 0
    skipped_count = 0
    moved_count = 0
    
    disk_files = {}

    for root, dirs, files in os.walk(fits_root):
        for file in files:
            if file.lower().endswith(('.fits', '.fit')):
                full_path = os.path.join(root, file)
                rel_path = os.path.relpath(full_path, fits_root)
                disk_files[rel_path] = True # Mark as seen
                
                try:
                    stat = os.stat(full_path)
                    mtime = stat.st_mtime
                    file_size = stat.st_size

                    # Check if file is unchanged (path, mtime, size)
                    if not force_reindex and rel_path in db_files:
                        db_entry = db_files[rel_path]

                        logger.debug(f"Checking {rel_path}...")
                        logger.debug(f"  - Disk mtime: {mtime}, DB mtime: {db_entry['mtime']}")
                        logger.debug(f"  - Disk size:  {file_size}, DB size:  {db_entry['size']}")

                        mtime_match = False
                        if db_entry['mtime'] is not None:
                            # Cast both to float before taking int to handle Decimal type from DB
                            mtime_match = int(float(db_entry['mtime'])) == int(mtime)

                        size_match = db_entry['size'] == file_size
                        is_deleted = db_entry['deleted_at'] is not None

                        if not is_deleted and mtime_match and size_match:
                            logger.debug(f"  --> SKIPPING")
                            skipped_count += 1
                            continue
                        else:
                            logger.debug(f"  --> PROCESSING")

                                        # File is new, modified, or we are forcing reindex
                    file_hash = calculate_hash(full_path)

                    # Process as a new or modified file
                    with fits.open(full_path) as hdul:
                        header = hdul[0].header
                        data = hdul[0].data

                        # --- Handle FITS data safely ---
                        thumb = None
                        if data is not None:
                            data = np.squeeze(data)
                            if data.ndim > 2:
                                data = data[0]
                            if data.ndim >= 2:
                                thumb = make_thumbnail(data)

                        # --- Metadata extraction ---
                        object_name = get_header_value(header, 'OBJECT', 'Unknown', str).strip()
                        date_obs_str = get_header_value(header, 'DATE-OBS', None, str)
                        date_obs = None
                        if date_obs_str:
                            try:
                                date_obs = Time(date_obs_str).to_datetime()
                            except Exception:
                                logger.warning(f"Unparsable DATE-OBS: {date_obs_str}")

                        exptime = get_header_value(header, 'EXPTIME', 0, float)
                        filt = get_header_value(header, 'FILTER', '', str)
                        imgtype = get_header_value(header, 'IMAGETYP', 'UNKNOWN', str).upper()
                        xbinning = get_header_value(header, 'XBINNING', None, int)
                        ybinning = get_header_value(header, 'YBINNING', None, int)
                        egain = get_header_value(header, 'EGAIN', None, float)
                        offset = get_header_value(header, 'OFFSET', None, float)
                        xpixsz = get_header_value(header, 'XPIXSZ', None, float)
                        ypixsz = get_header_value(header, 'YPIXSZ', None, float)
                        instrume = get_header_value(header, 'INSTRUME', None, str)
                        set_temp = get_header_value(header, 'SET-TEMP', None, float)
                        ccd_temp = get_header_value(header, 'CCD-TEMP', None, float)
                        telescop = get_header_value(header, 'TELESCOP', None, str)
                        focallen = get_header_value(header, 'FOCALLEN', None, float)
                        focratio = get_header_value(header, 'FOCRATIO', None, float)
                        ra = get_header_value(header, 'RA', None, float)
                        dec = get_header_value(header, 'DEC', None, float)
                        centalt = get_header_value(header, 'CENTALT', None, float)
                        centaz = get_header_value(header, 'CENTAZ', None, float)
                        airmass = get_header_value(header, 'AIRMASS', None, float)
                        pierside = get_header_value(header, 'PIERSIDE', None, str)
                        siteelev = get_header_value(header, 'SITEELEV', None, float)
                        sitelat = get_header_value(header, 'SITELAT', None, float)
                        sitelong = get_header_value(header, 'SITELONG', None, float)

                        focpos = get_header_value(header, 'FOCPOS', None, int)
                        if focpos is None:
                            focpos = get_header_value(header, 'FOCUSPOS', None, int)

                                                # --- Database insertion ---
                        # The ON DUPLICATE KEY UPDATE clause relies on the 'path' column
                        # having a UNIQUE index.
                        sql = '''
                            INSERT INTO files (
                                path, file_hash, name, mtime, file_size, object, date_obs, exptime, filter, imgtype,
                                xbinning, ybinning, egain, `offset`, xpixsz, ypixsz, instrume,
                                set_temp, ccd_temp, telescop, focallen, focratio, ra, `dec`,
                                centalt, centaz, airmass, pierside, siteelev, sitelat, sitelong,
                                                                focpos, thumb, deleted_at, is_hidden
                            ) VALUES (
                                %(path)s, %(file_hash)s, %(name)s, %(mtime)s, %(file_size)s, %(object)s, %(date_obs)s, %(exptime)s, %(filter)s, %(imgtype)s,
                                %(xbinning)s, %(ybinning)s, %(egain)s, %(offset)s, %(xpixsz)s, %(ypixsz)s, %(instrume)s,
                                %(set_temp)s, %(ccd_temp)s, %(telescop)s, %(focallen)s, %(focratio)s, %(ra)s, %(dec)s,
                                %(centalt)s, %(centaz)s, %(airmass)s, %(pierside)s, %(siteelev)s, %(sitelat)s, %(sitelong)s,
                                %(focpos)s, %(thumb)s, NULL, 0
                            )
                            ON DUPLICATE KEY UPDATE
                                file_hash=VALUES(file_hash), mtime=VALUES(mtime), file_size=VALUES(file_size),
                                name=VALUES(name), object=VALUES(object), date_obs=VALUES(date_obs),
                                exptime=VALUES(exptime), filter=VALUES(filter), imgtype=VALUES(imgtype),
                                xbinning=VALUES(xbinning), ybinning=VALUES(ybinning), egain=VALUES(egain),
                                `offset`=VALUES(`offset`), xpixsz=VALUES(xpixsz), ypixsz=VALUES(ypixsz),
                                instrume=VALUES(instrume), set_temp=VALUES(set_temp), ccd_temp=VALUES(ccd_temp),
                                telescop=VALUES(telescop), focallen=VALUES(focallen), focratio=VALUES(focratio),
                                ra=VALUES(ra), `dec`=VALUES(`dec`), centalt=VALUES(centalt), centaz=VALUES(centaz),
                                airmass=VALUES(airmass), pierside=VALUES(pierside), siteelev=VALUES(siteelev),
                                sitelat=VALUES(sitelat), sitelong=VALUES(sitelong), focpos=VALUES(focpos),
                                thumb=COALESCE(VALUES(thumb), thumb),
                                deleted_at=NULL,
                                is_hidden=is_hidden
                        '''

                        params = {
                            'path': rel_path, 'file_hash': file_hash, 'name': file, 'mtime': mtime, 'file_size': file_size,
                            'object': object_name, 'date_obs': date_obs, 'exptime': exptime, 'filter': filt, 'imgtype': imgtype,
                            'xbinning': xbinning, 'ybinning': ybinning, 'egain': egain, 'offset': offset,
                            'xpixsz': xpixsz, 'ypixsz': ypixsz, 'instrume': instrume, 'set_temp': set_temp,
                            'ccd_temp': ccd_temp, 'telescop': telescop, 'focallen': focallen,
                            'focratio': focratio, 'ra': ra, 'dec': dec, 'centalt': centalt,
                            'centaz': centaz, 'airmass': airmass, 'pierside': pierside,
                                                        'siteelev': siteelev, 'sitelat': sitelat, 'sitelong': sitelong,
                            'focpos': focpos, 'thumb': thumb
                        }

                        logger.debug(f"Executing DB write for {rel_path} with mtime={params['mtime']}")
                        cur.execute(sql, params)

                        # Update duplicate counts for the inserted/updated file's hash
                        update_duplicate_counts(conn, cur, file_hash)

                        # Check if a previous file entry was updated and had a different hash
                        if rel_path in db_files and db_files[rel_path]['hash'] != file_hash:
                            old_hash = db_files[rel_path]['hash']
                            update_duplicate_counts(conn, cur, old_hash)

                        # Update in-memory state
                        db_files[rel_path] = {
                            'hash': file_hash,
                            'mtime': mtime,
                            'size': file_size,
                            'deleted_at': None
                        }
                        db_hashes.setdefault(file_hash, []).append(rel_path)
                        
                        processed_count += 1
                        if (processed_count + moved_count) % commit_interval == 0:
                            conn.commit()
                            logger.info(f'Progress: {processed_count} files processed, {moved_count} moved, {skipped_count} skipped.')

                        logger.debug(f'Processed: {rel_path}')
                
                except Exception as e:
                    logger.error(f'Error processing {rel_path}: {e}')
                    error_count += 1

    conn.commit()

    # Cleanup phase
    if not args.skip_cleanup:
        soft_delete_missing_files(conn, cur, db_files, disk_files)
        purge_deleted_files(conn, cur, args.retention_days)

    # Final statistics
    duration = datetime.now() - start_time
    logger.info("=== Indexing Complete ===")
    logger.info(f"Duration: {duration}")
    logger.info(f"Files processed: {processed_count}")
    logger.info(f"Files moved: {moved_count}")
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
