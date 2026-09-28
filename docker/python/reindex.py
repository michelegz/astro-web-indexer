import os
import sys
import time
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
from indexer_lib.image_processing import make_thumbnail, make_crop_preview, frame_statistics
from indexer_lib.star_analysis import analyze_frame
from indexer_lib.file_utils import calculate_hash, get_header_value, get_xisf_header_value
from indexer_lib.db_utils import soft_delete_missing_files, purge_deleted_files, update_duplicate_counts
from indexer_lib.ephemeris import get_moon_ephemeris
from indexer_lib.schema_upgrade import SCHEMA_VERSION_FIELDS, schema_upgrade_worker
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
parser.add_argument("--password", default=os.getenv("DB_PASSWORD", os.getenv("DB_PASS", "awi_password")), help="Database password")
parser.add_argument("--database", default=os.getenv("DB_NAME", "awi_db"), help="Database name")
parser.add_argument("--force", action="store_true", help="Force reindexing of existing files")
parser.add_argument("--thumb-size", type=int, default=thumb_size_default, help="Thumbnail size in pixels (e.g., 300)")
parser.add_argument("--skip-cleanup", action="store_true", help="Skip removal of non-existing files")
parser.add_argument("--retention-days", type=int, default=os.getenv("RETENTION_DAYS", 30), help="Days to keep soft-deleted files before permanent removal")
# Read the default from the environment variable, with a final fallback to 4.
default_workers = int(os.getenv("INDEXER_WORKERS", 4))

parser.add_argument("--workers", type=int, default=default_workers, help="Number of worker processes for parallel indexing")
parser.add_argument("--debug", action="store_true", help="Enable debug logging")
# Master switch for star/quality metrics (HFR, FWHM, ...). Default from env;
# watch_fs.py inherits the environment when it respawns this script.
parser.add_argument("--star-metrics", dest="star_metrics",
                    action=argparse.BooleanOptionalAction,
                    default=os.getenv("STAR_METRICS_ENABLED", "true").lower() in ("true", "1"),
                    help="Compute star metrics for LIGHT frames (default from STAR_METRICS_ENABLED)")
parser.add_argument("--backfill-star-metrics", action="store_true",
                    help="Reprocess pixel data of LIGHT frames missing star metrics, then exit the backfill phase")
parser.add_argument("--recompute-star-metrics", action="store_true",
                    help="Recompute star metrics for ALL LIGHT frames (e.g. after a formula change), "
                         "not just the ones missing them")
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

# Files slower than this (seconds, full worker incl. hash + thumbnails + metrics)
# get a WARNING during backfill so slow outliers are visible without --debug.
SLOW_FILE_SECONDS = 60.0


# --- Worker function for multiprocessing ---
def process_file_worker(full_path, fits_root, thumb_size, star_metrics=True, with_thumbs=True):
    t_start = time.perf_counter()
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
                return {'status': 'error', 'path': rel_path, 'reason': 'No image metadata in XISF',
                        'elapsed': time.perf_counter() - t_start}
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
                # Thumbnails are skipped in backfill mode: the backfill UPDATE
                # never writes them, and STF-stretching full frames is expensive.
                if with_thumbs:
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
        gain = get_value(header, 'GAIN', None, float)

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

        # Star/quality metrics (LIGHT frames only). analyze_frame() never raises:
        # None means "not computed" (all DB columns stay NULL), while a result
        # with star_count == 0 means "computed, no stars found".
        # Column names follow the initial schema (hfr in pixels, fwhm in arcsec).
        star = {'hfr': None, 'fwhm': None, 'hfr_sd': None, 'eccentricity': None,
                'star_count': None, 'snr_weight': None, 'psf_signal': None}
        if star_metrics and imgtype == 'LIGHT' and data is not None:
            computed = analyze_frame(data)
            if computed is not None:
                star['hfr'] = computed['hfr_avg']
                if computed['fwhm_avg'] is not None and resolution:
                    star['fwhm'] = computed['fwhm_avg'] * resolution
                star['hfr_sd'] = computed['hfr_sd']
                star['eccentricity'] = computed['ecc_avg']
                star['star_count'] = computed['star_count']
                star['snr_weight'] = computed['snr_weight']
                star['psf_signal'] = computed['psf_signal']

        # Frame-level pixel statistics and sensor metadata (all frame types,
        # lights and calibrations alike: bias level, flat exposure, Bayer...).
        fstats = {'background_mean': None, 'min_pixel': None, 'max_pixel': None,
                  'mean_pixel': None, 'median_pixel': None}
        if data is not None:
            fstats.update(frame_statistics(data))

        bitpix = get_value(header, 'BITPIX', None, int)
        bit_depth = abs(bitpix) if bitpix is not None else None
        sq = np.squeeze(data) if data is not None else None
        channels = None
        if sq is not None:
            if sq.ndim == 2:
                channels = 1
            elif sq.ndim == 3:
                if sq.shape[0] in (3, 4):
                    channels = int(sq.shape[0])
                elif sq.shape[-1] in (3, 4):
                    channels = int(sq.shape[-1])
        bayer = get_value(header, 'BAYERPAT', None, str)
        bayer = bayer.strip().upper() if bayer else None
        if bayer:
            color_type = 'OSC'
        elif channels == 3:
            color_type = 'RGB'
        elif channels == 1:
            color_type = 'MONO'
        else:
            color_type = None

        params = {
            'path': rel_path, 'file_hash': file_hash, 'name': file_name, 'mtime': int(mtime), 'file_size': file_size,
            'width': width, 'height': height, 'resolution': resolution, 'fov_w': fov_w, 'fov_h': fov_h,
            'object': object_name, 'objctra': objct_ra, 'objctdec': objct_dec,
            'imgtype': imgtype, 'exptime': exptime, 'date_obs': date_obs, 'date_avg': date_avg, 'filter': filt,
            'xbinning': xbinning, 'ybinning': ybinning, 'egain': egain, 'gain': gain, 'offset': offset, 'xpixsz': xpixsz, 'ypixsz': ypixsz, 'set_temp': set_temp, 'ccd_temp': ccd_temp,
            'instrume': instrume, 'cameraid': camera_id, 'usblimit': usb_limit, 'fwheel': fwheel, 'telescop': telescop, 'focallen': focallen, 'focratio': focratio,
            'focname': foc_name, 'focpos': focpos, 'focussz': focus_sz, 'foctemp': foc_temp,
            'ra': ra, 'dec': dec, 'centalt': centalt, 'centaz': centaz, 'airmass': airmass, 'pierside': pierside, 'objctrot': objctrot,
            'siteelev': siteelev, 'sitelat': sitelat, 'sitelong': sitelong,
            'swcreate': swcreate, 'roworder': roworder, 'equinox': equinox,
            'thumb': thumb, 'thumb_crop': thumb_crop,
            'moon_phase': moon_phase, 'moon_angle': moon_angle,
            'hfr': star['hfr'], 'fwhm': star['fwhm'], 'hfr_sd': star['hfr_sd'],
            'eccentricity': star['eccentricity'], 'star_count': star['star_count'],
            'snr_weight': star['snr_weight'], 'psf_signal': star['psf_signal'],
            'background_mean': fstats['background_mean'], 'min_pixel': fstats['min_pixel'],
            'max_pixel': fstats['max_pixel'], 'mean_pixel': fstats['mean_pixel'],
            'median_pixel': fstats['median_pixel'], 'bit_depth': bit_depth,
            'image_channels': channels, 'image_color_type': color_type,
            'bayer_pattern': bayer
        }
        elapsed = time.perf_counter() - t_start
        logger.debug(f"Worker[{os.getpid()}] done: {rel_path} in {elapsed:.1f}s")
        return {'status': 'success', 'path': rel_path, 'params': params, 'elapsed': elapsed}

    except Exception as e:
        logger.error(f"Error processing {rel_path} in worker: {e}")
        return {'status': 'error', 'path': rel_path, 'reason': str(e),
                'elapsed': time.perf_counter() - t_start}


def main():
    try:
        logger.info(f"Connecting to database {args.database} on {args.host}")
        conn = mysql.connector.connect(host=args.host, user=args.user, password=args.password, database=args.database)
        cur = conn.cursor()

        current_schema_version = 4

        logger.info("Loading existing file data from database...")
        cur.execute("SELECT path, file_hash, mtime, file_size, deleted_at, data_schema_version FROM files")
        db_files = {row[0]: {'hash': row[1], 'mtime': row[2], 'size': row[3], 'deleted_at': row[4], 'schema_version': row[5]} for row in cur.fetchall()}
        logger.info(f"Loaded {len(db_files)} records from the database.")

        tasks = []
        schema_upgrade_tasks = []
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

                    should_process = False
                    reason = "skipped (no changes)"

                    if force_reindex:
                        should_process = True
                        reason = "forced reindex"
                    elif rel_path not in db_files:
                        should_process = True
                        reason = "new file"
                    else:
                        db_entry = db_files[rel_path]
                        is_deleted = db_entry['deleted_at'] is not None

                        mtime_match = db_entry.get('mtime') is not None and int(db_entry.get('mtime')) == int(mtime)
                        size_match = db_entry.get('size') is not None and int(db_entry.get('size')) == file_size

                        if not is_deleted and mtime_match and size_match:
                            if db_entry.get('schema_version') == current_schema_version:
                                skipped_count += 1
                                continue
                            else:
                                logger.debug(f"Queueing '{rel_path}' for schema upgrade. Reason: schema version changed.")
                                schema_upgrade_tasks.append((full_path, db_entry.get('schema_version') or 1))
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
                        else:
                            should_process = True
                            reason = "content hash changed"

                    if should_process:
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
                    xbinning, ybinning, egain, gain, `offset`, xpixsz, ypixsz, set_temp, ccd_temp,
                    instrume, cameraid, usblimit, fwheel, telescop, focallen, focratio, 
                    focname, focpos, focussz, foctemp,
                    ra, `dec`, centalt, centaz, airmass, pierside, objctrot,
                    siteelev, sitelat, sitelong,
                    swcreate, roworder, equinox,
                    thumb, thumb_crop, deleted_at, is_hidden, data_schema_version,
                    moon_phase, moon_angle,
                    hfr, fwhm, hfr_sd, eccentricity, star_count, snr_weight, psf_signal,
                    background_mean, min_pixel, max_pixel, mean_pixel, median_pixel,
                    bit_depth, image_channels, image_color_type, bayer_pattern
                ) VALUES (
                    %(path)s, %(file_hash)s, %(name)s, %(mtime)s, %(file_size)s, %(width)s, %(height)s, %(resolution)s, %(fov_w)s, %(fov_h)s,
                    %(object)s, %(objctra)s, %(objctdec)s,
                    %(imgtype)s, %(exptime)s, %(date_obs)s, %(date_avg)s, %(filter)s,
                    %(xbinning)s, %(ybinning)s, %(egain)s, %(gain)s, %(offset)s, %(xpixsz)s, %(ypixsz)s, %(set_temp)s, %(ccd_temp)s,
                    %(instrume)s, %(cameraid)s, %(usblimit)s, %(fwheel)s, %(telescop)s, %(focallen)s, %(focratio)s,
                    %(focname)s, %(focpos)s, %(focussz)s, %(foctemp)s,
                    %(ra)s, %(dec)s, %(centalt)s, %(centaz)s, %(airmass)s, %(pierside)s, %(objctrot)s,
                    %(siteelev)s, %(sitelat)s, %(sitelong)s,
                    %(swcreate)s, %(roworder)s, %(equinox)s,
                    %(thumb)s, %(thumb_crop)s, NULL, 0, 3,
                    %(moon_phase)s, %(moon_angle)s,
                    %(hfr)s, %(fwhm)s, %(hfr_sd)s, %(eccentricity)s, %(star_count)s, %(snr_weight)s, %(psf_signal)s,
                    %(background_mean)s, %(min_pixel)s, %(max_pixel)s, %(mean_pixel)s, %(median_pixel)s,
                    %(bit_depth)s, %(image_channels)s, %(image_color_type)s, %(bayer_pattern)s
                )
                ON DUPLICATE KEY UPDATE
                    file_hash=VALUES(file_hash), mtime=VALUES(mtime), file_size=VALUES(file_size), width=VALUES(width), height=VALUES(height), resolution=VALUES(resolution), fov_w=VALUES(fov_w), fov_h=VALUES(fov_h), name=VALUES(name),
                    object=VALUES(object), objctra=VALUES(objctra), objctdec=VALUES(objctdec),
                    imgtype=VALUES(imgtype), exptime=VALUES(exptime), date_obs=VALUES(date_obs), date_avg=VALUES(date_avg), filter=VALUES(filter),
                    xbinning=VALUES(xbinning), ybinning=VALUES(ybinning), egain=VALUES(egain), gain=VALUES(gain), `offset`=VALUES(`offset`), xpixsz=VALUES(xpixsz), ypixsz=VALUES(ypixsz), set_temp=VALUES(set_temp), ccd_temp=VALUES(ccd_temp),
                    instrume=VALUES(instrume), cameraid=VALUES(cameraid), usblimit=VALUES(usblimit), fwheel=VALUES(fwheel), telescop=VALUES(telescop), focallen=VALUES(focallen), focratio=VALUES(focratio),
                    focname=VALUES(focname), focpos=VALUES(focpos), focussz=VALUES(focussz), foctemp=VALUES(foctemp),
                    ra=VALUES(ra), `dec`=VALUES(`dec`), centalt=VALUES(centalt), centaz=VALUES(centaz), airmass=VALUES(airmass), pierside=VALUES(pierside), objctrot=VALUES(objctrot),
                    siteelev=VALUES(siteelev), sitelat=VALUES(sitelat), sitelong=VALUES(sitelong),
                    swcreate=VALUES(swcreate), roworder=VALUES(roworder), equinox=VALUES(equinox),
                    thumb=COALESCE(VALUES(thumb), thumb),
                    thumb_crop=COALESCE(VALUES(thumb_crop), thumb_crop),
                    deleted_at=NULL, is_hidden=is_hidden, data_schema_version=VALUES(data_schema_version),
                    moon_phase=VALUES(moon_phase),
                    moon_angle=VALUES(moon_angle),
                    hfr=VALUES(hfr), fwhm=VALUES(fwhm), hfr_sd=VALUES(hfr_sd),
                    eccentricity=VALUES(eccentricity), star_count=VALUES(star_count),
                    snr_weight=VALUES(snr_weight), psf_signal=VALUES(psf_signal),
                    background_mean=VALUES(background_mean), min_pixel=VALUES(min_pixel),
                    max_pixel=VALUES(max_pixel), mean_pixel=VALUES(mean_pixel),
                    median_pixel=VALUES(median_pixel), bit_depth=VALUES(bit_depth),
                    image_channels=VALUES(image_channels), image_color_type=VALUES(image_color_type),
                    bayer_pattern=VALUES(bayer_pattern)
            '''
            worker_func = partial(process_file_worker, fits_root=fits_root, thumb_size=thumb_size,
                                   star_metrics=args.star_metrics)
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

        schema_upgraded_count = 0
        if schema_upgrade_tasks:
            logger.info(f"Performing lightweight schema upgrade for {len(schema_upgrade_tasks)} files (header-only, no thumbnail regeneration)...")
            upgrade_worker_func = partial(schema_upgrade_worker, fits_root=fits_root)
            with concurrent.futures.ProcessPoolExecutor(max_workers=args.workers) as executor:
                for result in executor.map(upgrade_worker_func, schema_upgrade_tasks):
                    if result['status'] == 'error':
                        error_count += 1
                        logger.error(f"Schema upgrade failed for {result['path']}: {result['reason']}")
                        continue
                    # Build the SET clause only for fields added between old_version and current
                    old_v = result['old_version']
                    set_parts = []
                    vals = []
                    for step_v in sorted(SCHEMA_VERSION_FIELDS.keys()):
                        if step_v > old_v:
                            for field in SCHEMA_VERSION_FIELDS[step_v]:
                                if field in result:
                                    set_parts.append(f"`{field}` = %s")
                                    vals.append(result[field])
                    set_parts.append("`data_schema_version` = %s")
                    vals.append(current_schema_version)
                    vals.append(result['path'])
                    cur.execute(f"UPDATE files SET {', '.join(set_parts)} WHERE path = %s", tuple(vals))
                    schema_upgraded_count += 1
                    if schema_upgraded_count % commit_interval == 0:
                        conn.commit()
                        logger.info(f"Schema upgrade progress: {schema_upgraded_count} files updated.")
            conn.commit()
            logger.info(f"Schema upgrade complete: {schema_upgraded_count} files updated.")

        backfilled_count = 0
        if args.backfill_star_metrics or args.recompute_star_metrics:
            # NOTE: star metrics need pixel data, so they cannot go through the
            # lightweight header-only schema_upgrade_worker above. This dedicated
            # pass reprocesses the pixel data of LIGHT frames still missing them.
            if not args.star_metrics:
                logger.warning("Backfill requested but star metrics are disabled (STAR_METRICS_ENABLED=false), skipping.")
            else:
                if args.recompute_star_metrics:
                    logger.info("Recomputing star metrics for ALL LIGHT frames...")
                    cur.execute(
                        "SELECT path FROM files WHERE imgtype = 'LIGHT' AND deleted_at IS NULL AND thumb IS NOT NULL"
                    )
                else:
                    logger.info("Backfilling star metrics for LIGHT frames with no computed values...")
                    cur.execute(
                        "SELECT path FROM files WHERE deleted_at IS NULL AND thumb IS NOT NULL AND ("
                        "(imgtype = 'LIGHT' AND hfr IS NULL) OR background_mean IS NULL)"
                    )
                backfill_tasks = [os.path.join(fits_root, row[0]) for row in cur.fetchall()
                                  if os.path.isfile(os.path.join(fits_root, row[0]))]
                if args.recompute_star_metrics:
                    logger.info(f"Found {len(backfill_tasks)} LIGHT files to recompute.")
                else:
                    logger.info(f"Found {len(backfill_tasks)} files missing metrics.")
                if backfill_tasks:
                    backfill_sql = (
                        "UPDATE files SET hfr=%s, fwhm=%s, hfr_sd=%s, eccentricity=%s, star_count=%s, "
                        "snr_weight=%s, psf_signal=%s, background_mean=%s, min_pixel=%s, max_pixel=%s, "
                        "mean_pixel=%s, median_pixel=%s, bit_depth=%s, image_channels=%s, "
                        "image_color_type=%s, bayer_pattern=%s, data_schema_version=%s WHERE path=%s"
                    )
                    backfill_start = datetime.now()
                    backfill_func = partial(process_file_worker, fits_root=fits_root,
                                            thumb_size=thumb_size, star_metrics=True,
                                            with_thumbs=False)
                    with concurrent.futures.ProcessPoolExecutor(max_workers=args.workers) as executor:
                        for result in executor.map(backfill_func, backfill_tasks):
                            try:
                                if result['status'] == 'error':
                                    error_count += 1
                                    logger.error(f"Backfill failed for {result['path']}: {result['reason']}")
                                    continue
                                elapsed = result.get('elapsed')
                                if elapsed is not None and elapsed > SLOW_FILE_SECONDS:
                                    logger.warning(f"Slow file ({elapsed:.0f}s): {result['path']}")
                                p = result['params']
                                cur.execute(backfill_sql, (p['hfr'], p['fwhm'], p['hfr_sd'],
                                                           p['eccentricity'], p['star_count'],
                                                           p['snr_weight'], p['psf_signal'],
                                                           p['background_mean'], p['min_pixel'],
                                                           p['max_pixel'], p['mean_pixel'],
                                                           p['median_pixel'], p['bit_depth'],
                                                           p['image_channels'], p['image_color_type'],
                                                           p['bayer_pattern'],
                                                           current_schema_version, result['path']))
                                backfilled_count += 1
                                if backfilled_count % commit_interval == 0:
                                    conn.commit()
                                    mins = max((datetime.now() - backfill_start).total_seconds() / 60, 1e-6)
                                    logger.info(f"Backfill progress: {backfilled_count} files updated "
                                                f"({backfilled_count / mins:.1f} files/min).")
                            except Exception as e:
                                logger.error(f"Error during backfill for {result.get('path', 'unknown file')}: {e}")
                                error_count += 1
                    conn.commit()
                    logger.info(f"Backfill complete: {backfilled_count} files updated.")

        soft_deleted_count = 0
        purged_count = 0
        if not args.skip_cleanup:
            soft_deleted_count = soft_delete_missing_files(conn, cur, db_files, disk_files)
            purged_count = purge_deleted_files(conn, cur, args.retention_days)

        duration = datetime.now() - start_time
        logger.info("=== Indexing Complete ===")
        logger.info(f"Duration: {duration}")
        logger.info(f"Files fully processed: {processed_count}")
        logger.info(f"Files schema-upgraded (header-only): {schema_upgraded_count}")
        logger.info(f"Files backfilled (star metrics): {backfilled_count}")
        logger.info(f"Files skipped: {skipped_count}")
        logger.info(f"Files soft-deleted: {soft_deleted_count}")
        logger.info(f"Files purged: {purged_count}")
        logger.info(f"Errors encountered: {error_count}")

    except KeyboardInterrupt:
        # Ctrl+C: don't just crash with a traceback and lose the current batch.
        # The ProcessPoolExecutor context managers above shut down on the way
        # out (spawned workers also receive SIGINT and terminate); commit
        # whatever was already executed so the next run resumes where we stopped.
        logger.warning("Interrupted by user (Ctrl+C): committing partial progress and exiting...")
        try:
            conn.commit()
            logger.info("Partial progress committed.")
        except Exception as e:
            logger.error(f"Could not commit partial progress: {e}")
        sys.exit(130)
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

