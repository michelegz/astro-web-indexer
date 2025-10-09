import os
import sys
import mysql.connector
import xxhash
from astropy.io import fits
from astropy.time import Time
from xisf import XISF
import numpy as np
from PIL import Image
import argparse
from io import BytesIO
import logging
from datetime import datetime, timezone
from stretch import stf_autostretch_color
from ephemeris import get_moon_ephemeris

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    stream=sys.stdout
)
logger = logging.getLogger('reindex')

# --- Parameter parser ---
thumb_size_default = int(os.getenv("THUMB_SIZE", 300))

parser = argparse.ArgumentParser(
    description="Reindex FITS/XISF files in MariaDB and generate thumbnails."
)
parser.add_argument("fits_root", help="Root directory containing image files")
parser.add_argument("--host", default=os.getenv("DB_HOST", "mariadb"), help="MariaDB host")
parser.add_argument("--user", default=os.getenv("DB_USER", "awi_user"), help="Database username")
parser.add_argument("--password", default=os.getenv("DB_PASS", "awi_password"), help="Database password")
parser.add_argument("--database", default=os.getenv("DB_NAME", "awi_db"), help="Database name")
parser.add_argument("--force", action="store_true", help="Force reindexing of existing files")
parser.add_argument("--thumb-size", type=int, default=thumb_size_default, help="Thumbnail size in pixels (e.g., 300)")
parser.add_argument("--skip-cleanup", action="store_true", help="Skip removal of non-existing files")
parser.add_argument("--retention-days", type=int, default=os.getenv("RETENTION_DAYS", 30), help="Days to keep soft-deleted files before permanent removal")
parser.add_argument("--debug", action="store_true", help="Enable debug logging")
args = parser.parse_args()

if args.debug:
    logger.setLevel(logging.DEBUG)

fits_root = args.fits_root
force_reindex = args.force
thumb_size = (args.thumb_size, args.thumb_size)

if not os.path.isdir(fits_root):
    logger.error(f"Error: directory {fits_root} does not exist")
    sys.exit(1)

commit_interval = 50

# --- Hash function ---
def calculate_hash(filepath, block_size=65536):
    hasher = xxhash.xxh64()
    with open(filepath, 'rb') as f:
        while True:
            buf = f.read(block_size)
            if not buf:
                break
            hasher.update(buf)
    return hasher.hexdigest()

# --- Thumbnail function ---
def make_thumbnail(data, size):
    try:
        # Ensure data is float32 and clean
        data = np.nan_to_num(data).astype(np.float32)
        
                # Apply the STF Autostretch (works for both mono and color)
        stretched, _ = stf_autostretch_color(data)
        
        # Convert to 8-bit image for display
        img = (stretched * 255).astype(np.uint8)
        
        # Create thumbnail with Pillow
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
        return 0

    batch_size = 500
    missing_paths_list = list(missing_paths)
    update_time = datetime.now()
    hashes_to_update = set()

    for i in range(0, len(missing_paths_list), batch_size):
        batch_paths = missing_paths_list[i:i+batch_size]
        format_strings = ','.join(['%s'] * len(batch_paths))
        cur.execute(f"SELECT file_hash FROM files WHERE path IN ({format_strings})", tuple(batch_paths))
        for row in cur.fetchall():
            hashes_to_update.add(row[0])

    for i in range(0, len(missing_paths_list), batch_size):
        batch = [(update_time, path) for path in missing_paths_list[i:i+batch_size]]
        cur.executemany("UPDATE files SET deleted_at = %s WHERE path = %s", batch)

    if hashes_to_update:
        logger.info(f"Updating duplicate counts for {len(hashes_to_update)} unique hashes...")
        for file_hash in hashes_to_update:
            update_duplicate_counts(conn, cur, file_hash)

    conn.commit()
    logger.info(f"Soft delete complete. Marked {len(missing_paths)} files as deleted.")
    return len(missing_paths)

def purge_deleted_files(conn, cur, retention_days):
    if retention_days <= 0:
        logger.info("Purge skipped as retention_days is zero or less.")
        return 0
        
    logger.info(f"Purging files deleted more than {retention_days} days ago...")
    cur.execute("SELECT DISTINCT file_hash FROM files WHERE deleted_at < NOW() - INTERVAL %s DAY", (retention_days,))
    hashes_to_update = [row[0] for row in cur.fetchall()]

    if not hashes_to_update:
        logger.info("Purge complete. No old files to remove.")
        return 0

    cur.execute("DELETE FROM files WHERE deleted_at < NOW() - INTERVAL %s DAY", (retention_days,))
    removed_count = cur.rowcount
    
    if removed_count > 0:
        logger.info(f"Permanently removed {removed_count} files. Updating duplicate counts...")
        for file_hash in hashes_to_update:
            update_duplicate_counts(conn, cur, file_hash)
        conn.commit()
        logger.info(f"Duplicate counts updated for {len(hashes_to_update)} unique hashes.")
    else:
        logger.info("Purge complete. No old files to remove.")
    
    return removed_count

def update_duplicate_counts(conn, cur, file_hash):
    if not file_hash:
        return
    try:
        cur.execute("SELECT COUNT(*) FROM files WHERE file_hash = %s AND deleted_at IS NULL", (file_hash,))
        total_count = cur.fetchone()[0]
        cur.execute("SELECT COUNT(*) FROM files WHERE file_hash = %s AND deleted_at IS NULL AND is_hidden = 0", (file_hash,))
        visible_count = cur.fetchone()[0]
        cur.execute("UPDATE files SET total_duplicate_count = %s, visible_duplicate_count = %s WHERE file_hash = %s", (total_count, visible_count, file_hash))
    except mysql.connector.Error as err:
        logger.error(f"Error updating duplicate count for hash {file_hash}: {err}")

def get_header_value(header, key, default=None, type_func=None):
    val = header.get(key, default)
    if val is None or val == '':
        return default
    if type_func:
        try:
            return type_func(val)
        except (ValueError, TypeError):
            return default
    return val

def get_xisf_header_value(header, key, default=None, type_func=None):
    if key in header and header[key]:
        val = header[key][0].get('value', default)
    else:
        val = default
    if val is None or val == '':
        return default
    if type_func:
        try:
            if type_func == bool and isinstance(val, str):
                if val.lower() == 'true': return True
                if val.lower() == 'false': return False
            return type_func(val)
        except (ValueError, TypeError):
            return default
    return val

# --- Main execution ---
try:
    logger.info(f"Connecting to database {args.database} on {args.host}")
    conn = mysql.connector.connect(host=args.host, user=args.user, password=args.password, database=args.database)
    cur = conn.cursor()

    logger.info("Loading existing file data from database...")
    cur.execute("SELECT path, file_hash, mtime, file_size, deleted_at FROM files")
    db_files = {row[0]: {'hash': row[1], 'mtime': row[2], 'size': row[3], 'deleted_at': row[4]} for row in cur.fetchall()}
    db_hashes = {}
    for path, rec in db_files.items():
        db_hashes.setdefault(rec['hash'], []).append(path)
    logger.info(f"Loaded {len(db_files)} records from the database.")

    logger.info("Starting file indexing...")
    start_time = datetime.now()
    processed_count = 0
    error_count = 0
    skipped_count = 0
    soft_deleted_count = 0
    purged_count = 0
    disk_files = {}

    for root, dirs, files in os.walk(fits_root):
        for file in files:
            file_lower = file.lower()
            if not file_lower.endswith(('.fits', '.fit', '.xisf')):
                continue

            full_path = os.path.join(root, file)
            rel_path = os.path.relpath(full_path, fits_root)
            disk_files[rel_path] = True

            try:
                stat = os.stat(full_path)
                mtime = stat.st_mtime
                file_size = stat.st_size

                if not force_reindex and rel_path in db_files:
                    db_entry = db_files[rel_path]
                    mtime_match = False
                    if db_entry['mtime'] is not None:
                        mtime_match = int(float(db_entry['mtime'])) == int(mtime)
                    size_match = db_entry['size'] == file_size
                    is_deleted = db_entry['deleted_at'] is not None
                    if not is_deleted and mtime_match and size_match:
                        skipped_count += 1
                        continue

                file_hash = calculate_hash(full_path)
                header, data, get_value = {}, None, None

                if file_lower.endswith(('.fits', '.fit')):
                    with fits.open(full_path, ignore_missing_end=True) as hdul:
                        header = hdul[0].header
                        data = hdul[0].data
                        get_value = get_header_value
                elif file_lower.endswith('.xisf'):
                    xisf_file = XISF(full_path)
                    images_meta = xisf_file.get_images_metadata()
                    if not images_meta:
                        logger.warning(f"No image metadata in XISF file: {rel_path}")
                        continue
                    header = images_meta[0].get('FITSKeywords', {})
                    data = xisf_file.read_image(0)
                    get_value = get_xisf_header_value
                
                thumb = None
                width, height = None, None
                resolution, fov_w, fov_h = None, None, None
                if data is not None:
                    data = np.squeeze(data)
                    if data.ndim > 2 and data.shape[0] < 5:
                        data = data[0]
                    if data.ndim >= 2:
                        height, width = data.shape[:2]
                        thumb = make_thumbnail(data, thumb_size)

                object_name = get_value(header, 'OBJECT', 'Unknown', str).strip()
                date_obs_str = get_value(header, 'DATE-OBS', None, str)
                date_obs = None
                if date_obs_str:
                    try:
                        normalized_date_str = date_obs_str.replace('/', '-')
                        date_obs = Time(normalized_date_str, format='isot' if 'T' in normalized_date_str else 'iso').to_datetime()
                    except Exception:
                        logger.warning(f"Unparsable DATE-OBS: '{date_obs_str}' in {rel_path}")

                exptime = get_value(header, 'EXPTIME', 0, float)
                if exptime == 0: # Fallback to EXPOSURE if EXPTIME is not present or zero
                    exptime = get_value(header, 'EXPOSURE', 0, float)
                
                filt = get_value(header, 'FILTER', '', str)
                imgtype = get_value(header, 'IMAGETYP', 'UNKNOWN', str).upper()
                xbinning = get_value(header, 'XBINNING', None, int)
                ybinning = get_value(header, 'YBINNING', None, int)
                egain = get_value(header, 'EGAIN', None, float)
                offset = get_value(header, 'OFFSET', None, float)
                xpixsz = get_value(header, 'XPIXSZ', None, float)
                ypixsz = get_value(header, 'YPIXSZ', None, float)
                instrume = get_value(header, 'INSTRUME', None, str)
                set_temp = get_value(header, 'SET-TEMP', None, float)
                ccd_temp = get_value(header, 'CCD-TEMP', None, float)
                telescop = get_value(header, 'TELESCOP', None, str)
                focallen = get_value(header, 'FOCALLEN', None, float)
                focratio = get_value(header, 'FOCRATIO', None, float)
                ra = get_value(header, 'RA', None, float)
                dec = get_value(header, 'DEC', None, float)
                centalt = get_value(header, 'CENTALT', None, float)
                centaz = get_value(header, 'CENTAZ', None, float)
                airmass = get_value(header, 'AIRMASS', None, float)
                pierside = get_value(header, 'PIERSIDE', None, str)
                siteelev = get_value(header, 'SITEELEV', None, float)
                sitelat = get_value(header, 'SITELAT', None, float)
                sitelong = get_value(header, 'SITELONG', None, float)
                focpos = get_value(header, 'FOCPOS', None, int)
                if focpos is None:
                    focpos = get_value(header, 'FOCUSPOS', None, int)
                
                # New fields from header
                date_avg_str = get_value(header, 'DATE-AVG', None, str)
                date_avg = None
                if date_avg_str:
                    try:
                        normalized_date_avg_str = date_avg_str.replace('/', '-')
                        date_avg = Time(normalized_date_avg_str, format='isot' if 'T' in normalized_date_avg_str else 'iso').to_datetime()
                    except Exception:
                        logger.warning(f"Unparsable DATE-AVG: '{date_avg_str}' in {rel_path}")

                swcreate = get_value(header, 'SWCREATE', None, str)
                objct_ra = get_value(header, 'OBJCTRA', None, str)
                objct_dec = get_value(header, 'OBJCTDEC', None, str)
                camera_id = get_value(header, 'CAMERAID', None, str)
                usb_limit = get_value(header, 'USBLIMIT', None, int)
                fwheel = get_value(header, 'FWHEEL', None, str)
                foc_name = get_value(header, 'FOCNAME', None, str)
                focus_sz = get_value(header, 'FOCUSSZ', None, float)
                foc_temp = get_value(header, 'FOCTEMP', None, float)
                if foc_temp is None:
                    foc_temp = get_value(header, 'FOCUSTEM', None, float)
                objctrot = get_value(header, 'OBJCTROT', None, float)
                roworder = get_value(header, 'ROWORDER', None, str)
                equinox = get_value(header, 'EQUINOX', None, float)

                # --- Calculated fields ---
                # Calculate resolution and FOV if possible
                if xpixsz and focallen and width and height:
                    if xpixsz > 0 and focallen > 0:
                        # Resolution in arcsec/pixel
                        resolution = (xpixsz / focallen) * 206.265
                        
                        # FOV in arcminutes
                        fov_w = (width * resolution) / 60
                        fov_h = (height * resolution) / 60

                # --- Ephemeris data ---
                moon_phase = None
                moon_angle = None
                if date_obs:
                    # Make the datetime object timezone-aware (UTC) before creating a timestamp.
                    # This prevents dependency on the system's local timezone.
                    date_obs_aware = date_obs.replace(tzinfo=timezone.utc)
                    timestamp = date_obs_aware.timestamp()
                    moon_phase, moon_angle = get_moon_ephemeris(timestamp)

                sql = '''
                    INSERT INTO files (
                        path, file_hash, name, mtime, file_size, width, height, resolution, fov_w, fov_h,
                        object, objctra, objctdec,
                        imgtype, exptime, date_obs, date_avg, filter,
                        xbinning, ybinning, egain, `offset`, xpixsz, ypixsz, set_temp, ccd_temp,
                        instrume, cameraid, usblimit, fwheel, telescop, focallen, focratio, 
                        focname, focpos, focussz, foctemp,
                        ra, `dec`, centalt, centaz, airmass, pierside, objctrot,
                        siteelev, sitelat, sitelong,
                                                swcreate, roworder, equinox,
                        thumb, deleted_at, is_hidden, data_schema_version,
                        moon_phase, moon_angle
                                                            ) VALUES (
                        %(path)s, %(file_hash)s, %(name)s, %(mtime)s, %(file_size)s, %(width)s, %(height)s, %(resolution)s, %(fov_w)s, %(fov_h)s,
                        %(object)s, %(objctra)s, %(objctdec)s,
                        %(imgtype)s, %(exptime)s, %(date_obs)s, %(date_avg)s, %(filter)s,
                        %(xbinning)s, %(ybinning)s, %(egain)s, %(offset)s, %(xpixsz)s, %(ypixsz)s, %(set_temp)s, %(ccd_temp)s,
                        %(instrume)s, %(cameraid)s, %(usblimit)s, %(fwheel)s, %(telescop)s, %(focallen)s, %(focratio)s,
                        %(focname)s, %(focpos)s, %(focussz)s, %(foctemp)s,
                        %(ra)s, %(dec)s, %(centalt)s, %(centaz)s, %(airmass)s, %(pierside)s, %(objctrot)s,
                        %(siteelev)s, %(sitelat)s, %(sitelong)s,
                        %(swcreate)s, %(roworder)s, %(equinox)s,
                        %(thumb)s, NULL, 0, 1,
                        %(moon_phase)s, %(moon_angle)s
                    )
                                                            ON DUPLICATE KEY UPDATE
                        file_hash=VALUES(file_hash), mtime=VALUES(mtime), file_size=VALUES(file_size), width=VALUES(width), height=VALUES(height), resolution=VALUES(resolution), fov_w=VALUES(fov_w), fov_h=VALUES(fov_h), name=VALUES(name),
                        object=VALUES(object), objctra=VALUES(objctra), objctdec=VALUES(objctdec),
                        imgtype=VALUES(imgtype), exptime=VALUES(exptime), date_obs=VALUES(date_obs), date_avg=VALUES(date_avg), filter=VALUES(filter),
                        xbinning=VALUES(xbinning), ybinning=VALUES(ybinning), egain=VALUES(egain), `offset`=VALUES(`offset`), xpixsz=VALUES(xpixsz), ypixsz=VALUES(ypixsz), set_temp=VALUES(set_temp), ccd_temp=VALUES(ccd_temp),
                        instrume=VALUES(instrume), cameraid=VALUES(cameraid), usblimit=VALUES(usblimit), fwheel=VALUES(fwheel), telescop=VALUES(telescop), focallen=VALUES(focallen), focratio=VALUES(focratio),
                        focname=VALUES(focname), focpos=VALUES(focpos), focussz=VALUES(focussz), foctemp=VALUES(foctemp),
                        ra=VALUES(ra), `dec`=VALUES(`dec`), centalt=VALUES(centalt), centaz=VALUES(centaz), airmass=VALUES(airmass), pierside=VALUES(pierside), objctrot=VALUES(objctrot),
                        siteelev=VALUES(siteelev), sitelat=VALUES(sitelat), sitelong=VALUES(sitelong),
                        swcreate=VALUES(swcreate), roworder=VALUES(roworder), equinox=VALUES(equinox),
                        thumb=COALESCE(VALUES(thumb), thumb),
                        deleted_at=NULL, is_hidden=is_hidden,
                        moon_phase=VALUES(moon_phase),
                        moon_angle=VALUES(moon_angle)
                        -- data_schema_version is intentionally omitted to preserve its value on update
                '''
                params = {
                    'path': rel_path, 'file_hash': file_hash, 'name': file, 'mtime': mtime, 'file_size': file_size,
                    'width': width, 'height': height, 'resolution': resolution, 'fov_w': fov_w, 'fov_h': fov_h,
                    'object': object_name, 'objctra': objct_ra, 'objctdec': objct_dec,
                    'imgtype': imgtype, 'exptime': exptime, 'date_obs': date_obs, 'date_avg': date_avg, 'filter': filt,
                    'xbinning': xbinning, 'ybinning': ybinning, 'egain': egain, 'offset': offset, 'xpixsz': xpixsz, 'ypixsz': ypixsz, 'set_temp': set_temp, 'ccd_temp': ccd_temp,
                    'instrume': instrume, 'cameraid': camera_id, 'usblimit': usb_limit, 'fwheel': fwheel, 'telescop': telescop, 'focallen': focallen, 'focratio': focratio,
                    'focname': foc_name, 'focpos': focpos, 'focussz': focus_sz, 'foctemp': foc_temp,
                    'ra': ra, 'dec': dec, 'centalt': centalt, 'centaz': centaz, 'airmass': airmass, 'pierside': pierside, 'objctrot': objctrot,
                    'siteelev': siteelev, 'sitelat': sitelat, 'sitelong': sitelong,
                    'swcreate': swcreate, 'roworder': roworder, 'equinox': equinox,
                    'thumb': thumb,
                    'moon_phase': moon_phase,
                    'moon_angle': moon_angle
                }
                cur.execute(sql, params)
                update_duplicate_counts(conn, cur, file_hash)

                if rel_path in db_files and db_files[rel_path]['hash'] != file_hash:
                    old_hash = db_files[rel_path]['hash']
                    update_duplicate_counts(conn, cur, old_hash)

                db_files[rel_path] = {'hash': file_hash, 'mtime': mtime, 'size': file_size, 'deleted_at': None}
                db_hashes.setdefault(file_hash, []).append(rel_path)
                
                processed_count += 1
                if processed_count % commit_interval == 0:
                    conn.commit()
                    logger.info(f'Progress: {processed_count} files processed, {skipped_count} skipped.')

            except Exception as e:
                logger.error(f'Error processing {rel_path}: {e}')
                error_count += 1
    
    conn.commit()

    if not args.skip_cleanup:
        soft_deleted_count = soft_delete_missing_files(conn, cur, db_files, disk_files)
        purged_count = purge_deleted_files(conn, cur, args.retention_days)

    duration = datetime.now() - start_time
    logger.info("=== Indexing Complete ===")
    logger.info(f"Duration: {duration}")
    logger.info(f"Files processed: {processed_count}")
    logger.info(f"Files skipped: {skipped_count}")
    logger.info(f"Files soft-deleted: {soft_deleted_count}")
    logger.info(f"Files purged: {purged_count}")
    logger.info(f"Errors encountered: {error_count}")

except mysql.connector.Error as err:
    logger.error(f"Database error: {err}")
    sys.exit(1)
except Exception as e:
    logger.error(f"Unexpected error: {e}")
    sys.exit(1)
finally:
    if 'conn' in locals() and conn.is_connected():
        conn.close()

