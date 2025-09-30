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
    level=logging.DEBUG,
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
def cleanup_missing_files(conn, cur, db_files, disk_files):
    logger.info("Starting cleanup of missing files...")
    db_paths = set(db_files.keys())
    disk_paths = set(disk_files.keys())

    missing_paths = db_paths - disk_paths
    removed_count = 0

    if not missing_paths:
        logger.info("Cleanup complete. No files to remove.")
        return

    # Use a temporary table for efficient deletion
    cur.execute("""
        CREATE TEMPORARY TABLE temp_delete_paths (
            path VARCHAR(1024) NOT NULL,
            PRIMARY KEY (path)
        )
    """)

    # Batch insert paths to be deleted
    batch_size = 500
    missing_paths_list = list(missing_paths)
    for i in range(0, len(missing_paths_list), batch_size):
        batch = [(path,) for path in missing_paths_list[i:i+batch_size]]
        cur.executemany("INSERT INTO temp_delete_paths (path) VALUES (%s)", batch)

    # Perform the deletion using a JOIN
    cur.execute("""
        DELETE f FROM files f
        JOIN temp_delete_paths t ON f.path = t.path
    """)
    removed_count = cur.rowcount
    
    cur.execute("DROP TEMPORARY TABLE temp_delete_paths")

    conn.commit()
    logger.info(f"Cleanup complete. Removed {removed_count} entries for missing files.")


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
    cur.execute("SELECT path, file_hash, mtime, file_size FROM files")
    db_files = {
        row[0]: {'hash': row[1], 'mtime': row[2], 'size': row[3]}
        for row in cur.fetchall()
    }
    db_hashes = {rec['hash']: path for path, rec in db_files.items()}
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
                        if db_entry['mtime'] == mtime and db_entry['size'] == file_size:
                            skipped_count += 1
                            continue

                    # File is new, modified, or we are forcing reindex
                    file_hash = calculate_hash(full_path)

                    # Check if it's a moved file (hash exists but path is different)
                    if not force_reindex and file_hash in db_hashes and db_hashes[file_hash] != rel_path:
                        old_path = db_hashes[file_hash]
                        logger.info(f"File moved: '{old_path}' -> '{rel_path}'")
                        cur.execute(
                            "UPDATE files SET path = %s, name = %s, mtime = %s, file_size = %s WHERE file_hash = %s",
                            (rel_path, file, mtime, file_size, file_hash)
                        )
                        moved_count +=1
                        
                        # Update our in-memory state to prevent re-processing
                        db_hashes[file_hash] = rel_path
                        db_files[rel_path] = db_files.pop(old_path)
                        continue

                    # Process as a new or modified file
                    with fits.open(full_path) as hdul:
                        header = hdul[0].header
                        data = hdul[0].data

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
                        
                        thumb = make_thumbnail(data) if data is not None else None

                        # --- Database insertion ---
                        sql = '''
                            INSERT INTO files (
                                file_hash, path, name, mtime, file_size, object, date_obs, exptime, filter, imgtype,
                                xbinning, ybinning, egain, `offset`, xpixsz, ypixsz, instrume,
                                set_temp, ccd_temp, telescop, focallen, focratio, ra, `dec`,
                                centalt, centaz, airmass, pierside, siteelev, sitelat, sitelong,
                                focpos, thumb
                            ) VALUES (
                                %(file_hash)s, %(path)s, %(name)s, %(mtime)s, %(file_size)s, %(object)s, %(date_obs)s, %(exptime)s, %(filter)s, %(imgtype)s,
                                %(xbinning)s, %(ybinning)s, %(egain)s, %(offset)s, %(xpixsz)s, %(ypixsz)s, %(instrume)s,
                                %(set_temp)s, %(ccd_temp)s, %(telescop)s, %(focallen)s, %(focratio)s, %(ra)s, %(dec)s,
                                %(centalt)s, %(centaz)s, %(airmass)s, %(pierside)s, %(siteelev)s, %(sitelat)s, %(sitelong)s,
                                %(focpos)s, %(thumb)s
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
                                thumb=COALESCE(VALUES(thumb), thumb)
                        '''

                        params = {
                            'file_hash': file_hash, 'path': rel_path, 'name': file, 'mtime': mtime, 'file_size': file_size,
                            'object': object_name, 'date_obs': date_obs, 'exptime': exptime, 'filter': filt, 'imgtype': imgtype,
                            'xbinning': xbinning, 'ybinning': ybinning, 'egain': egain, 'offset': offset,
                            'xpixsz': xpixsz, 'ypixsz': ypixsz, 'instrume': instrume, 'set_temp': set_temp,
                            'ccd_temp': ccd_temp, 'telescop': telescop, 'focallen': focallen,
                            'focratio': focratio, 'ra': ra, 'dec': dec, 'centalt': centalt,
                            'centaz': centaz, 'airmass': airmass, 'pierside': pierside,
                            'siteelev': siteelev, 'sitelat': sitelat, 'sitelong': sitelong,
                            'focpos': focpos, 'thumb': thumb
                        }

                        cur.execute(sql, params)
                        
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
        cleanup_missing_files(conn, cur, db_files, disk_files)

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
