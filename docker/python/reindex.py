import os
import sys
import time
import mysql.connector
import argparse
import logging
import concurrent.futures
from functools import partial
import multiprocessing

# Corrected imports for the new structure
from indexer_lib import star_analysis
from indexer_lib.frame_extract import (frame_resolution, header_params, metric_params,
                                       metrics_owed, open_frame, preview_params,
                                       stats_params)
from indexer_lib.file_utils import calculate_hash
from indexer_lib.db_utils import soft_delete_missing_files, purge_deleted_files, update_duplicate_counts
from indexer_lib.schema_upgrade import SCHEMA_VERSION_FIELDS, schema_upgrade_worker
from indexer_lib.projects import suggest_projects_for_files, suggest_projects_backfill
from indexer_lib.status import RunStatus
from datetime import datetime

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    stream=sys.stdout
)
logger = logging.getLogger('reindex')


def _phase_mode_default():
    """INDEXER_PHASE_MODE, with a readable answer when it says nonsense."""
    raw = (os.getenv("INDEXER_PHASE_MODE", "two") or "two").strip().lower()
    if raw not in ("two", "single"):
        logger.warning(f"INDEXER_PHASE_MODE='{raw}' is not one of 'two'/'single', using 'two'.")
        return "two"
    return raw


def _profile_default():
    """STAR_METRICS_PROFILE, with a readable answer when it says nonsense.

    The name is resolved here, once, and handed down to the workers: they are
    spawned processes, they do not inherit this one's runtime state, and an
    unknown name must be reported once, not by every worker.
    """
    raw = (os.getenv("STAR_METRICS_PROFILE", "default") or "default").strip().lower()
    resolved = star_analysis.resolve_profile(raw)
    if raw != resolved:
        logger.warning(f"STAR_METRICS_PROFILE='{raw}' is not one of "
                       f"{', '.join(star_analysis.PROFILES)}, using '{resolved}'.")
    return resolved


def ensure_schema(conn):
    """Stop with a readable message when the migrations are not applied yet.

    The python container does not wait for the php one, so the first reindex
    can start before the schema is there. Failing on the first query with an
    "Unknown column" says nothing about what to do; this says it.
    """
    cur = conn.cursor()
    try:
        cur.execute("SHOW COLUMNS FROM files LIKE 'metrics_pending'")
        found = cur.fetchone()
    except mysql.connector.Error:
        found = None
    finally:
        cur.close()
    if not found:
        logger.error(
            "The files table has no metrics_pending column: the database "
            "migrations have not been applied yet (vendor/bin/phinx migrate in "
            "the php container). Stopping here instead of failing on every query."
        )
        sys.exit(1)


def _flush(conn, cur, sql, batch, on_batch=None):
    """Writes one batch and commits it.

    A batch the database rejects raises: the caller rolls it back and drops
    it, so one bad record does not take the pass down with it.
    """
    cur.executemany(sql, batch)
    if on_batch is not None:
        on_batch(batch)
    conn.commit()


def run_pass(conn, cur, worker_func, tasks, sql, build_row, workers=4,
             on_result=None, on_batch=None, on_progress=None, label="files"):
    """Runs a worker over the tasks and writes the results in batches.

    A batch the database rejects is rolled back and dropped, so one bad
    record does not stop the pass: keeping the list meant the offending
    record was replayed in every later flush, so one unreadable file made
    every remaining file in the run fail with it and the final flush at the
    end of the loop failed too.

    ``on_progress(attempted, errors)`` is called after every result, including
    the failed ones: it is the hook the status row uses to show a live counter.
    The publisher throttles the actual writes, so this stays cheap.
    """
    processed = 0
    errors = 0
    attempted = 0
    batch = []
    with concurrent.futures.ProcessPoolExecutor(max_workers=workers) as executor:
        for result in executor.map(worker_func, tasks):
            attempted += 1
            try:
                if result['status'] == 'error':
                    errors += 1
                    logger.error(f"Failed to process {result['path']}: {result['reason']}")
                    continue
                if on_result is not None:
                    on_result(result)
                batch.append(build_row(result))
                if len(batch) >= commit_interval:
                    _flush(conn, cur, sql, batch, on_batch)
                    processed += len(batch)
                    logger.info(f'Progress: {processed} {label} committed.')
                    batch.clear()
            except Exception as e:
                logger.error(f"Error during batch processing for {result.get('path', 'unknown file')}: {e}")
                errors += 1
                try:
                    conn.rollback()
                except Exception:
                    pass
                batch.clear()
            finally:
                if on_progress is not None:
                    on_progress(attempted, errors)
        if batch:
            # The tail flush stays outside the try on purpose: a bad record
            # there fails loudly, after everything else has been committed.
            _flush(conn, cur, sql, batch, on_batch)
            processed += len(batch)
    return processed, errors


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
parser.add_argument("--suggest-projects", action="store_true",
                    help="Propose project links for the whole archive (wizard queue in "
                         "suggest mode, direct links in auto mode), then exit")
parser.add_argument("--phase-mode", choices=("two", "single"),
                    default=_phase_mode_default(),
                    help="How the indexing is split: 'two' (default) writes the file "
                         "first, so it shows up in the frontend, and computes the star "
                         "metrics after; 'single' does everything in one pass")
parser.add_argument("--run-reason", default="manual",
                    help="Stable code recorded in indexer_runs (startup, created, "
                         "modified, moved, deleted, scan, manual, backfill, recompute, "
                         "suggest). watch_fs.py passes the reason that triggered the run.")
parser.add_argument("--star-metrics-profile", choices=list(star_analysis.PROFILES),
                    default=_profile_default(),
                    help="How the star metrics are measured: 'fast' (bright stars only, "
                         "about twice as fast), 'default' (5.0 sigma, min area 9), "
                         "'sensitive' (every faint source counted, about a third slower). "
                         "Overrides STAR_METRICS_PROFILE.")
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


# --- Worker functions for multiprocessing ---
def frame_worker(full_path, fits_root, thumb_size, with_metrics=True, with_thumbs=True,
                 star_metrics=True, star_profile=None):
    """The worker of the fast pass, and of the single-phase mode.

    It produces every column but the star metrics when with_metrics=False:
    that is the fast pass of the two-phase mode, where the file shows up in
    the frontend with its preview while the metrics are still owed.

    The extraction itself lives in indexer_lib.frame_extract, shared with the
    slow pass: the two modes cannot drift apart.
    """
    t_start = time.perf_counter()
    rel_path = os.path.relpath(full_path, fits_root)
    logger.debug(f"Worker[{os.getpid()}] processing: {rel_path}")
    file_name = os.path.basename(full_path)

    try:
        if star_profile:
            # The workers are spawned: they re-import the modules and inherit
            # this process's arguments, not its runtime state. The profile
            # therefore travels with the work and is applied here, to this
            # process's own copy of the knobs.
            star_analysis.apply_profile(star_profile, warn=False)

        stat = os.stat(full_path)
        mtime = stat.st_mtime
        file_size = stat.st_size

        file_hash = calculate_hash(full_path)
        if file_hash is None:
            raise IOError("Could not calculate hash")

        ctx = open_frame(full_path, with_data=True)
        params = header_params(ctx, rel_path, file_hash, file_name, mtime, file_size)

        if with_thumbs:
            params.update(preview_params(ctx, thumb_size))
        else:
            params['thumb'] = None
            params['thumb_crop'] = None

        params.update(stats_params(ctx))

        if with_metrics:
            params.update(metric_params(ctx, params.get('resolution'), star_metrics))
            # The metrics are in this same pass: nothing is owed.
            params['metrics_pending'] = 0
        else:
            # The metrics are not computed here: the columns are written
            # empty and the slow pass fills them in. Only the LIGHT frames
            # have star metrics to compute, so only they are sent there.
            params.update(metric_params(ctx, None, star_metrics=False))
            params['metrics_pending'] = 1 if metrics_owed(ctx, star_metrics) else 0

        elapsed = time.perf_counter() - t_start
        logger.debug(f"Worker[{os.getpid()}] done: {rel_path} in {elapsed:.1f}s")
        return {'status': 'success', 'path': rel_path, 'params': params, 'elapsed': elapsed}

    except Exception as e:
        logger.error(f"Error processing {rel_path} in worker: {e}")
        return {'status': 'error', 'path': rel_path, 'reason': str(e),
                'elapsed': time.perf_counter() - t_start}


def metrics_worker(full_path, fits_root, star_metrics=True, star_profile=None):
    """The worker of the slow pass: the star metrics alone.

    It opens the file again, because the fast pass already closed it: the
    frame is decoded twice in the two-phase mode, and that is the price of
    the file being visible before its metrics are known.
    """
    t_start = time.perf_counter()
    rel_path = os.path.relpath(full_path, fits_root)
    logger.debug(f"Worker[{os.getpid()}] measuring: {rel_path}")

    try:
        if star_profile:
            # See frame_worker: the profile travels with the work, because a
            # spawned worker does not inherit the parent's runtime state.
            star_analysis.apply_profile(star_profile, warn=False)

        ctx = open_frame(full_path, with_data=True)
        resolution = None
        try:
            resolution = frame_resolution(ctx, rel_path)
        except Exception:
            resolution = None
        params = metric_params(ctx, resolution, star_metrics)
        elapsed = time.perf_counter() - t_start
        logger.debug(f"Worker[{os.getpid()}] done: {rel_path} in {elapsed:.1f}s")
        return {'status': 'success', 'path': rel_path, 'params': params, 'elapsed': elapsed}

    except Exception as e:
        logger.error(f"Error measuring {rel_path} in worker: {e}")
        return {'status': 'error', 'path': rel_path, 'reason': str(e),
                'elapsed': time.perf_counter() - t_start}


def main():
    # Defined before the try: an early failure (DB down, unreadable archive)
    # still has to close the status row with a reason.
    status = None
    error_count = 0
    processed_count = 0
    measured_count = 0
    try:
        two_phase = args.phase_mode == 'two'
        mode_label = ('two passes (fast pass, then the star metrics)' if two_phase
                      else 'single pass (everything at once)')
        logger.info(f"Indexing mode: {mode_label}")

        # The measurement profile decides which stars a frame has. It is
        # resolved once here and handed down to the workers, which are
        # spawned and would not see it otherwise.
        profile = star_analysis.apply_profile(args.star_metrics_profile)
        active = star_analysis.active_profile()
        if args.star_metrics:
            logger.info(f"Star metrics profile: {active['name']} "
                        f"(threshold {active['thresh_sigma']:g} sigma, "
                        f"min area {active['min_area']} px)")
        else:
            logger.info("Star metrics profile: not in use (star metrics are off)")

        logger.info(f"Connecting to database {args.database} on {args.host}")
        conn = mysql.connector.connect(host=args.host, user=args.user, password=args.password, database=args.database)
        cur = conn.cursor()

        ensure_schema(conn)

        current_schema_version = 5

        # A manual run that is really a backfill should say so in the card. The
        # watcher always passes its own code, so this only refines the default.
        run_reason = args.run_reason
        if run_reason == 'manual':
            if args.suggest_projects:
                run_reason = 'suggest'
            elif args.recompute_star_metrics:
                run_reason = 'recompute'
            elif args.backfill_star_metrics:
                run_reason = 'backfill'

        status = RunStatus(
            {
                'host': args.host,
                'user': args.user,
                'password': args.password,
                'database': args.database,
            },
            reason=run_reason,
            phase_mode=args.phase_mode,
            config={
                'phase_mode': args.phase_mode,
                'workers': args.workers,
                'star_metrics': bool(args.star_metrics),
                'star_metrics_profile': args.star_metrics_profile,
                'thumb_size': args.thumb_size,
                'retention_days': args.retention_days,
                'force': bool(force_reindex),
                'skip_cleanup': bool(args.skip_cleanup),
                'backfill_star_metrics': bool(args.backfill_star_metrics),
                'recompute_star_metrics': bool(args.recompute_star_metrics),
            },
        )

        if args.suggest_projects:
            logger.info("Suggesting project links for the whole archive...")
            status.phase('projects')
            suggest_projects_backfill(conn)
            status.finish('done', error_count=error_count)
            return

        status.phase('loading')
        logger.info("Loading existing file data from database...")
        cur.execute("SELECT path, file_hash, mtime, file_size, deleted_at, data_schema_version, metrics_pending FROM files")
        db_files = {row[0]: {'hash': row[1], 'mtime': row[2], 'size': row[3], 'deleted_at': row[4],
                             'schema_version': row[5], 'metrics_pending': row[6]} for row in cur.fetchall()}
        logger.info(f"Loaded {len(db_files)} records from the database.")
        status.progress(force=True, db_files=len(db_files))

        tasks = []
        pending_tasks = []
        schema_upgrade_tasks = []
        skipped_count = 0
        disk_files = {}
        # Worker result params for the project suggestion pass (freshly
        # processed files only; unchanged files are covered on demand via
        # --suggest-projects).
        suggest_metas = []

        status.phase('scanning')
        scan_seen = 0
        logger.info("Scanning filesystem and identifying files to process...")
        for root, dirs, files in os.walk(fits_root):
            for file in files:
                file_lower = file.lower()
                if not file_lower.endswith(('.fits', '.fit', '.fz', '.xisf')):
                    continue

                scan_seen += 1
                status.progress(files_scanned=scan_seen)

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
                            # The file did not change, so its header is still
                            # good: only the metrics can be owed. The slow pass
                            # takes it from here.
                            if db_entry.get('metrics_pending'):
                                pending_tasks.append(full_path)
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
                            if db_entry.get('metrics_pending'):
                                pending_tasks.append(full_path)
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
        if pending_tasks:
            logger.info(f"Found {len(pending_tasks)} files still owing their star metrics.")

        start_time = datetime.now()
        processed_count = 0
        measured_count = 0

        # The files that owe a metrics pass: the ones the fast pass has just
        # flagged, and the ones left pending by an earlier run. The order is
        # kept and the duplicates dropped.
        if two_phase:
            fast_tasks = tasks
        else:
            # One pass does everything, so the pending files are processed
            # whole: their metrics are computed and their flag cleared.
            fast_tasks = list(dict.fromkeys(tasks + pending_tasks))
        slow_tasks = []

        status.progress(
            force=True,
            files_scanned=len(disk_files),
            db_files=len(db_files),
            skipped=skipped_count,
            files_total=len(fast_tasks),
            metrics_total=len(pending_tasks),
        )

        if fast_tasks:
            status.phase('headers', total=len(fast_tasks))
            sql = '''
                INSERT INTO files (
                    path, file_hash, name, mtime, file_size, width, height, resolution, fov_w, fov_h,
                    object, objctra, objctdec,
                    imgtype, exptime, date_obs, date_avg, filter,
                    xbinning, ybinning, egain, gain, `offset`, xpixsz, ypixsz, set_temp, ccd_temp,
                    instrume, cameraid, usblimit, fwheel, telescop, focallen, focratio,
                    focname, focpos, focussz, foctemp,
                    ra, `dec`, centalt, centaz, airmass, pierside, objctrot,
                    rotator_angle, rotator_name, readoutm,
                    cloudcvr, dewpoint, humidity, pressure, ambtemp,
                    siteelev, sitelat, sitelong,
                    swcreate, roworder, equinox,
                    thumb, thumb_crop, deleted_at, is_hidden, data_schema_version, metrics_pending,
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
                    %(rotator_angle)s, %(rotator_name)s, %(readoutm)s,
                    %(cloudcvr)s, %(dewpoint)s, %(humidity)s, %(pressure)s, %(ambtemp)s,
                    %(siteelev)s, %(sitelat)s, %(sitelong)s,
                    %(swcreate)s, %(roworder)s, %(equinox)s,
                    %(thumb)s, %(thumb_crop)s, NULL, 0, 5, %(metrics_pending)s,
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
                    rotator_angle=VALUES(rotator_angle), rotator_name=VALUES(rotator_name), readoutm=VALUES(readoutm),
                    cloudcvr=VALUES(cloudcvr), dewpoint=VALUES(dewpoint), humidity=VALUES(humidity), pressure=VALUES(pressure), ambtemp=VALUES(ambtemp),
                    siteelev=VALUES(siteelev), sitelat=VALUES(sitelat), sitelong=VALUES(sitelong),
                    swcreate=VALUES(swcreate), roworder=VALUES(roworder), equinox=VALUES(equinox),
                    thumb=COALESCE(VALUES(thumb), thumb),
                    thumb_crop=COALESCE(VALUES(thumb_crop), thumb_crop),
                    deleted_at=NULL, is_hidden=is_hidden, data_schema_version=VALUES(data_schema_version),
                    metrics_pending=VALUES(metrics_pending),
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
            worker_func = partial(frame_worker, fits_root=fits_root, thumb_size=thumb_size,
                                   with_metrics=not two_phase, with_thumbs=True,
                                   star_metrics=args.star_metrics, star_profile=profile)

            hashes_to_update = set()

            def on_result(result, db_files=db_files, hashes_to_update=hashes_to_update,
                          fits_root=fits_root):
                """Keep the in-memory view of the archive in step with the writes."""
                params = result['params']
                rel_path = result['path']
                # The suggestion pass works on the header fields, which the
                # fast pass has just produced.
                suggest_metas.append(params)
                old_hash = db_files.get(rel_path, {}).get('hash')
                new_hash = params['file_hash']
                if old_hash and old_hash != new_hash:
                    hashes_to_update.add(old_hash)
                if new_hash:
                    hashes_to_update.add(new_hash)
                entry = db_files.setdefault(rel_path, {})
                entry.update({'hash': new_hash, 'mtime': params['mtime'],
                              'size': params['file_size'], 'deleted_at': None,
                              'metrics_pending': params.get('metrics_pending', 0)})
                if params.get('metrics_pending'):
                    # Only the frames that owe a metrics pass go to the slow
                    # one: a DARK, a FLAT or a BIAS has no star metrics, and
                    # decoding it again for nothing is the one cost the split
                    # would not pay back.
                    slow_tasks.append(os.path.join(fits_root, rel_path))

            def on_batch(batch, conn=conn, cur=cur, hashes_to_update=hashes_to_update):
                """The duplicate counts follow the hashes that just landed."""
                for file_hash in hashes_to_update:
                    update_duplicate_counts(conn, cur, file_hash)
                hashes_to_update.clear()

            base_errors = error_count
            processed_count, errors = run_pass(
                conn, cur, worker_func, fast_tasks, sql,
                build_row=lambda r: r['params'],
                on_result=on_result, on_batch=on_batch,
                on_progress=lambda done, errs: status.progress(
                    phase_done=done, error_count=base_errors + errs),
                workers=args.workers, label='files')
            error_count += errors
            status.progress(force=True, phase_done=len(fast_tasks),
                            files_done=processed_count, error_count=error_count)

        conn.commit()

        # Project suggestions for freshly processed files (suggest/manual
        # modes queue wizard rows; auto mode links directly; frozen skipped).
        # They run on the header fields, so they do not wait for the metrics.
        if suggest_metas:
            status.phase('projects', total=len(suggest_metas))
        try:
            suggest_projects_for_files(conn, suggest_metas)
            if suggest_metas:
                status.progress(force=True, phase_done=len(suggest_metas))
        except Exception as e:
            logger.error(f"Project suggestion pass failed (indexing is unaffected): {e}")

        # The slow pass: the star metrics of the files that owe them. It runs
        # after the fast pass has been committed, so the archive is already
        # browsable while this one grinds.
        if two_phase:
            slow_tasks = list(dict.fromkeys(slow_tasks + pending_tasks))
        if slow_tasks:
            status.phase('metrics', total=len(slow_tasks), metrics_total=len(slow_tasks))
            metrics_sql = (
                "UPDATE files SET hfr=%s, fwhm=%s, hfr_sd=%s, eccentricity=%s, star_count=%s, "
                "snr_weight=%s, psf_signal=%s, metrics_pending=0 WHERE path=%s"
            )
            metrics_worker_func = partial(metrics_worker, fits_root=fits_root,
                                           star_metrics=args.star_metrics,
                                           star_profile=profile)
            base_errors = error_count
            measured_count, errors = run_pass(
                conn, cur, metrics_worker_func, slow_tasks, metrics_sql,
                build_row=lambda r: (r['params']['hfr'], r['params']['fwhm'],
                                     r['params']['hfr_sd'], r['params']['eccentricity'],
                                     r['params']['star_count'], r['params']['snr_weight'],
                                     r['params']['psf_signal'], r['path']),
                on_progress=lambda done, errs: status.progress(
                    phase_done=done, metrics_done=done, error_count=base_errors + errs),
                label='measurements')
            error_count += errors
            status.progress(force=True, phase_done=len(slow_tasks),
                            metrics_done=measured_count, error_count=error_count)

        conn.commit()

        schema_upgraded_count = 0
        if schema_upgrade_tasks:
            status.phase('schema', total=len(schema_upgrade_tasks))
            logger.info(f"Performing lightweight schema upgrade for {len(schema_upgrade_tasks)} files (header-only, no thumbnail regeneration)...")
            upgrade_worker_func = partial(schema_upgrade_worker, fits_root=fits_root)
            schema_attempted = 0
            with concurrent.futures.ProcessPoolExecutor(max_workers=args.workers) as executor:
                for result in executor.map(upgrade_worker_func, schema_upgrade_tasks):
                    schema_attempted += 1
                    if result['status'] == 'error':
                        error_count += 1
                        logger.error(f"Schema upgrade failed for {result['path']}: {result['reason']}")
                        status.progress(phase_done=schema_attempted, error_count=error_count)
                        continue
                    # A file whose header could not be read has extracted nothing. Advancing
                    # data_schema_version anyway would mark it up to date with every new
                    # column NULL, and reindex.py:383 would then skip it forever, so the
                    # missing values would never be filled and nothing would say so. Leave
                    # the version alone so the next run retries it.
                    if result.get('header_read') is False:
                        error_count += 1
                        logger.error(
                            f"Schema upgrade deferred for {result['path']}: header not "
                            f"readable, version left at {result['old_version']}"
                        )
                        status.progress(phase_done=schema_attempted, error_count=error_count)
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
                    status.progress(phase_done=schema_attempted, error_count=error_count)
            conn.commit()
            status.progress(force=True, phase_done=len(schema_upgrade_tasks),
                            error_count=error_count)
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
                        "SELECT path FROM files WHERE imgtype = 'LIGHT' AND deleted_at IS NULL"
                    )
                else:
                    logger.info("Backfilling star metrics for LIGHT frames with no computed values...")
                    # The rows that still owe a metrics pass (metrics_pending=1)
                    # are in there too: the slow pass of a run interrupted
                    # halfway left them pending.
                    cur.execute(
                        "SELECT path FROM files WHERE deleted_at IS NULL AND ("
                        "metrics_pending = 1 OR (thumb IS NOT NULL AND ("
                        "(imgtype = 'LIGHT' AND hfr IS NULL) OR background_mean IS NULL)))"
                    )
                backfill_tasks = [os.path.join(fits_root, row[0]) for row in cur.fetchall()
                                  if os.path.isfile(os.path.join(fits_root, row[0]))]
                if args.recompute_star_metrics:
                    logger.info(f"Found {len(backfill_tasks)} LIGHT files to recompute.")
                else:
                    logger.info(f"Found {len(backfill_tasks)} files missing metrics.")
                if backfill_tasks:
                    status.phase('backfill', total=len(backfill_tasks))
                    backfill_sql = (
                        "UPDATE files SET hfr=%s, fwhm=%s, hfr_sd=%s, eccentricity=%s, star_count=%s, "
                        "snr_weight=%s, psf_signal=%s, background_mean=%s, min_pixel=%s, max_pixel=%s, "
                        "mean_pixel=%s, median_pixel=%s, bit_depth=%s, image_channels=%s, "
                        "image_color_type=%s, bayer_pattern=%s, data_schema_version=%s, "
                        "metrics_pending=0 WHERE path=%s"
                    )
                    backfill_start = datetime.now()
                    backfill_func = partial(frame_worker, fits_root=fits_root,
                                            thumb_size=thumb_size, with_metrics=True,
                                            with_thumbs=False, star_metrics=True,
                                            star_profile=profile)
                    backfill_attempted = 0
                    with concurrent.futures.ProcessPoolExecutor(max_workers=args.workers) as executor:
                        for result in executor.map(backfill_func, backfill_tasks):
                            backfill_attempted += 1
                            try:
                                if result['status'] == 'error':
                                    error_count += 1
                                    logger.error(f"Backfill failed for {result['path']}: {result['reason']}")
                                    status.progress(phase_done=backfill_attempted, error_count=error_count)
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
                                status.progress(phase_done=backfill_attempted, error_count=error_count)
                            except Exception as e:
                                logger.error(f"Error during backfill for {result.get('path', 'unknown file')}: {e}")
                                error_count += 1
                                status.progress(phase_done=backfill_attempted, error_count=error_count)
                    conn.commit()
                    status.progress(force=True, phase_done=len(backfill_tasks),
                                    error_count=error_count)
                    logger.info(f"Backfill complete: {backfilled_count} files updated.")

        soft_deleted_count = 0
        purged_count = 0
        if not args.skip_cleanup:
            status.phase('cleanup')
            soft_deleted_count = soft_delete_missing_files(conn, cur, db_files, disk_files)
            purged_count = purge_deleted_files(conn, cur, args.retention_days)

        duration = datetime.now() - start_time
        logger.info("=== Indexing Complete ===")
        logger.info(f"Duration: {duration}")
        logger.info(f"Files fully processed: {processed_count}")
        logger.info(f"Files measured (star metrics): {measured_count}")
        logger.info(f"Files schema-upgraded (header-only): {schema_upgraded_count}")
        logger.info(f"Files backfilled (star metrics): {backfilled_count}")
        logger.info(f"Files skipped: {skipped_count}")
        logger.info(f"Files soft-deleted: {soft_deleted_count}")
        logger.info(f"Files purged: {purged_count}")
        logger.info(f"Errors encountered: {error_count}")

        status.finish(
            'done',
            error_count=error_count,
            files_done=processed_count,
            metrics_done=measured_count,
            skipped=skipped_count,
            files_scanned=len(disk_files),
            db_files=len(db_files),
        )

    except KeyboardInterrupt:
        # Ctrl+C: don't just crash with a traceback and lose the current batch.
        # The ProcessPoolExecutor context managers above shut down on the way
        # out (spawned workers also receive SIGINT and terminate); commit
        # whatever was already executed so the next run resumes where we stopped.
        logger.warning("Interrupted by user (Ctrl+C): committing partial progress and exiting...")
        if status is not None:
            status.finish('interrupted', error_count=error_count,
                          last_error='Interrupted by user')
        try:
            conn.commit()
            logger.info("Partial progress committed.")
        except Exception as e:
            logger.error(f"Could not commit partial progress: {e}")
        sys.exit(130)
    except mysql.connector.Error as err:
        logger.error(f"Database error: {err}")
        if status is not None:
            status.finish('error', error_count=error_count, last_error=str(err))
        sys.exit(1)
    except Exception as e:
        logger.error(f"Unexpected error: {e}")
        if status is not None:
            status.finish('error', error_count=error_count, last_error=str(e))
        sys.exit(1)
    finally:
        # Every path out closes the row: done/error/interrupted set above, and a
        # BaseException (a signal, a spawned worker dying hard) leaves it as the
        # last phase with an honest label instead of a phantom "running" row.
        if status is not None:
            status.finish('interrupted', error_count=error_count,
                          last_error='Indexer stopped before finishing')
            status.close()
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
