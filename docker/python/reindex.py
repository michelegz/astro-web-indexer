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
import concurrent.futures
from functools import partial
import multiprocessing

# Corrected imports for the new structure
from indexer_lib.image_processing import make_thumbnail, make_crop_preview
from indexer_lib.file_utils import calculate_hash, get_header_value, get_xisf_header_value
from indexer_lib.db_utils import soft_delete_missing_files, purge_deleted_files, update_duplicate_counts
from indexer_lib.ephemeris import get_moon_ephemeris
from datetime import datetime, timezone

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
# Read the default from the environment variable, with a final fallback to 4.
default_workers = int(os.getenv("INDEXER_WORKERS", 4))

parser.add_argument("--workers", type=int, default=default_workers, help="Number of worker processes for parallel indexing")
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
from indexer_lib.file_utils import calculate_hash, get_header_value, get_xisf_header_value

# --- Thumbnail function ---
from indexer_lib.image_processing import make_thumbnail, make_crop_preview

# --- Database cleanup functions ---
from indexer_lib.db_utils import soft_delete_missing_files, purge_deleted_files, update_duplicate_counts




# --- Worker function for multiprocessing ---
def process_file_worker(full_path, fits_root, thumb_size):
    rel_path = os.path.relpath(full_path, fits_root)
    logger.debug(f"Worker[{os.getpid()}] processing: {rel_path}")
    file_lower = full_path.lower()
    file_name = os.path.basename(full_path)

    try:
        stat = os.stat(full_path)
        mtime = stat.st_mtime
        file_size = stat.st_size

        file_hash = calculate_hash(full_path)
        if file_hash is None:
            raise IOError("Could not calculate hash")
            
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
                return {'status': 'error', 'path': rel_path, 'reason': 'No image metadata in XISF'}
            header = images_meta[0].get('FITSKeywords', {})
            data = xisf_file.read_image(0)
            get_value = get_xisf_header_value
        
        thumb, thumb_crop = None, None
        width, height = None, None
        resolution, fov_w, fov_h = None, None, None
        if data is not None:
            data = np.squeeze(data)
            thumb_data = data
            if thumb_data.ndim > 2 and thumb_data.shape[0] in [3, 4]:
                 thumb_data = thumb_data[0]

            if thumb_data.ndim >= 2:
                height, width = thumb_data.shape[:2]
                thumb = make_thumbnail(thumb_data, thumb_size)
                thumb_crop = make_crop_preview(data, thumb_size)

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
        if exptime == 0:
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

        if xpixsz and focallen and width and height:
            if xpixsz > 0 and focallen > 0:
                resolution = (xpixsz / focallen) * 206.265
                fov_w = (width * resolution) / 60
                fov_h = (height * resolution) / 60

        moon_phase = None
        moon_angle = None
        if date_obs:
            date_obs_aware = date_obs.replace(tzinfo=timezone.utc)
            timestamp = date_obs_aware.timestamp()
            moon_phase, moon_angle = get_moon_ephemeris(timestamp)

        params = {
            'path': rel_path, 'file_hash': file_hash, 'name': file_name, 'mtime': int(mtime), 'file_size': file_size,
            'width': width, 'height': height, 'resolution': resolution, 'fov_w': fov_w, 'fov_h': fov_h,
            'object': object_name, 'objctra': objct_ra, 'objctdec': objct_dec,
            'imgtype': imgtype, 'exptime': exptime, 'date_obs': date_obs, 'date_avg': date_avg, 'filter': filt,
            'xbinning': xbinning, 'ybinning': ybinning, 'egain': egain, 'offset': offset, 'xpixsz': xpixsz, 'ypixsz': ypixsz, 'set_temp': set_temp, 'ccd_temp': ccd_temp,
            'instrume': instrume, 'cameraid': camera_id, 'usblimit': usb_limit, 'fwheel': fwheel, 'telescop': telescop, 'focallen': focallen, 'focratio': focratio,
            'focname': foc_name, 'focpos': focpos, 'focussz': focus_sz, 'foctemp': foc_temp,
            'ra': ra, 'dec': dec, 'centalt': centalt, 'centaz': centaz, 'airmass': airmass, 'pierside': pierside, 'objctrot': objctrot,
            'siteelev': siteelev, 'sitelat': sitelat, 'sitelong': sitelong,
            'swcreate': swcreate, 'roworder': roworder, 'equinox': equinox,
            'thumb': thumb, 'thumb_crop': thumb_crop,
            'moon_phase': moon_phase, 'moon_angle': moon_angle
        }
        return {'status': 'success', 'path': rel_path, 'params': params}

    except Exception as e:
        logger.error(f"Error processing {rel_path} in worker: {e}")
        return {'status': 'error', 'path': rel_path, 'reason': str(e)}


def main():
    try:
        logger.info(f"Connecting to database {args.database} on {args.host}")
        conn = mysql.connector.connect(host=args.host, user=args.user, password=args.password, database=args.database)
        cur = conn.cursor()

        logger.info("Loading existing file data from database...")
        cur.execute("SELECT path, file_hash, mtime, file_size, deleted_at FROM files")
        db_files = {row[0]: {'hash': row[1], 'mtime': row[2], 'size': row[3], 'deleted_at': row[4]} for row in cur.fetchall()}
        logger.info(f"Loaded {len(db_files)} records from the database.")

        tasks = []
        skipped_count = 0
        error_count = 0
        disk_files = {}
        
        logger.info("Scanning filesystem and identifying files to process...")
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
                        is_deleted = db_entry['deleted_at'] is not None

                        mtime_from_db = db_entry.get('mtime')
                        size_from_db = db_entry.get('size')
                        
                        mtime_match = mtime_from_db is not None and int(mtime_from_db) == int(mtime)
                        size_match = size_from_db is not None and int(size_from_db) == file_size

                        if not is_deleted and mtime_match and size_match:
                            skipped_count += 1
                            continue
                        
                        file_hash = calculate_hash(full_path)
                        
                        if db_entry.get('hash') == file_hash:
                            logger.debug(f"Content hash match for '{rel_path}'. Performing lightweight metadata update.")
                            cur.execute(
                                "UPDATE files SET mtime = %s, file_size = %s, deleted_at = NULL WHERE path = %s",
                                (int(mtime), file_size, rel_path)
                            )
                            db_files[rel_path].update({'mtime': mtime, 'size': file_size, 'deleted_at': None})
                            skipped_count += 1
                            continue
                    
                    reason = "new file" if rel_path not in db_files else "content hash changed"
                    logger.debug(f"Queueing '{rel_path}' for processing. Reason: {reason}.")
                    tasks.append(full_path)

                except Exception as e:
                    logger.error(f'Error evaluating file {rel_path}: {e}')
                    error_count += 1
        
        conn.commit() # Commit any lightweight updates
        
        logger.info(f"Found {len(tasks)} files for full processing.")
        
        start_time = datetime.now()
        processed_count = 0

        if tasks:
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
                    thumb, thumb_crop, deleted_at, is_hidden, data_schema_version,
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
                    %(thumb)s, %(thumb_crop)s, NULL, 0, 1,
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
                    thumb_crop=COALESCE(VALUES(thumb_crop), thumb_crop),
                    deleted_at=NULL, is_hidden=is_hidden,
                    moon_phase=VALUES(moon_phase),
                    moon_angle=VALUES(moon_angle)
            '''
            worker_func = partial(process_file_worker, fits_root=fits_root, thumb_size=thumb_size)
            batch_params = []
            hashes_to_update = set()
            
            with concurrent.futures.ProcessPoolExecutor(max_workers=args.workers) as executor:
                for result in executor.map(worker_func, tasks):
                    try:
                        if result['status'] == 'error':
                            error_count += 1
                            logger.error(f"Failed to process {result['path']}: {result['reason']}")
                            continue

                        params = result['params']
                        rel_path = result['path']
                        
                        logger.debug(f"Adding '{rel_path}' to batch (current size: {len(batch_params)+1}).")
                        batch_params.append(params)

                        new_hash = params['file_hash']
                        old_hash = db_files.get(rel_path, {}).get('hash')
                        if old_hash and old_hash != new_hash:
                            hashes_to_update.add(old_hash)
                        hashes_to_update.add(new_hash)
                        
                        db_files[rel_path] = {'hash': new_hash, 'mtime': params['mtime'], 'size': params['file_size'], 'deleted_at': None}

                        if len(batch_params) >= commit_interval:
                            logger.debug(f"Executing batch of {len(batch_params)} records.")
                            cur.executemany(sql, batch_params)
                            for file_hash in hashes_to_update:
                                update_duplicate_counts(conn, cur, file_hash)
                            conn.commit()
                            processed_count += len(batch_params)
                            logger.info(f'Progress: {processed_count} files committed, {skipped_count} skipped.')
                            batch_params.clear()
                            hashes_to_update.clear()

                    except Exception as e:
                        logger.error(f"Error during batch processing for {result.get('path', 'unknown file')}: {e}")
                        error_count += 1

                if batch_params:
                    cur.executemany(sql, batch_params)
                    for file_hash in hashes_to_update:
                        update_duplicate_counts(conn, cur, file_hash)
                    processed_count += len(batch_params)
        
        conn.commit()

        soft_deleted_count = 0
        purged_count = 0
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

if __name__ == "__main__":
    # Set the start method to 'spawn' for reliability, especially on Linux/macOS
    # This must be done inside the __name__ == '__main__' block.
    try:
        multiprocessing.set_start_method('spawn')
    except RuntimeError:
        # The start method can only be set once.
        pass
    main()

