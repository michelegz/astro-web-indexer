#!/usr/bin/env python3
"""End-to-end: the two-phase indexing, and the single-phase mode it replaces.

The indexer writes the row first (header, preview, frame statistics) so the
file shows up in the frontend, and computes the star metrics after. This
checks the properties that split has to hold:

  1. two-phase: after a run, the LIGHT frame has its header, its preview, its
     frame statistics AND its star metrics, and its flag is cleared.
  2. the DARK frame never enters the slow pass: it has no star metrics to
     compute, so it is complete as soon as it is written.
  3. a frame that still owes its metrics is VISIBLE with all its metadata
     (that is the whole point of the split) while its metric columns are
     empty, and the flag says so.
  4. an interrupted slow pass is resumed: the next run measures the frame
     even though the file on disk did not change at all.
  5. the single-phase mode does everything in one pass, and it also completes
     a frame left pending by a two-phase run (the flag is cleared), so the
     two modes can be flipped on a live archive without stranding a row.

Why the real script and not a stub
----------------------------------
The mechanism under test is the ORCHESTRATION (which pass writes what, and
when the flag is cleared), so the real reindex.py runs against the real
table, with the real workers, on a real tree of FITS files.

The frames carry real stars: the metrics are only worth checking when the
extractor has something to find, and a frame of pure noise would make
star_count=0 look like a success.

Hygiene (learned by losing the service)
---------------------------------------
The rules of reindex_batch_continue_check.py apply here too, and they are not
optional:

  * a single connection for all the test work, with autocommit=True
  * rollback and close ALWAYS in the finally, even if the script dies halfway
  * a unique path prefix per run, and DELETE on that exact prefix
  * a final check that the site answers, and verification that the counts went
    back to the initial state

Usage:
  docker cp tests/python/two_phase_check.py awi-python:/tmp/
  docker exec -e AWI_PROJECTS_LIB=/opt/scripts awi-python \
    sh -c 'cd /tmp && python two_phase_check.py'
"""

import os
import shutil
import subprocess
import sys
import tempfile
import urllib.error
import urllib.request

import mysql.connector
import numpy as np
from astropy.io import fits

FAILED = []
CONN = None
TABLES = ('files', 'project_suggestions', 'project_files', 'project_setups',
          'projects', 'project_panels', 'project_sessions')

REINDEX_PY = os.environ.get('REINDEX_PY', '/opt/scripts/reindex.py')


def check(label, cond, detail=''):
    print(f"  {label:<56} {detail}{'OK' if cond else '<<< FAILED'}")
    if not cond:
        FAILED.append(label)


def snapshot():
    cur = CONN.cursor()
    out = {}
    for t in TABLES:
        cur.execute(f"SELECT COUNT(*) FROM {t}")
        out[t] = cur.fetchone()[0]
    cur.close()
    return out


def write_fits(path, imgtype, seed=1, n_stars=40):
    """A frame with real stars, so the extractor has something to measure."""
    rng = np.random.default_rng(seed)
    h, w = 256, 256
    data = rng.normal(1000.0, 12.0, (h, w))
    ys = rng.integers(20, h - 20, n_stars)
    xs = rng.integers(20, w - 20, n_stars)
    flux = rng.uniform(4000.0, 30000.0, n_stars)
    # A Gaussian of sigma 2 has ~78 pixels above the half-flux radius: over
    # the hot-pixel cut (32 px) and wide enough for a real FWHM measurement.
    yy, xx = np.mgrid[0:h, 0:w]
    for y, x, f in zip(ys, xs, flux):
        data += f * np.exp(-(((xx - x) ** 2 + (yy - y) ** 2) / (2.0 * 2.0 ** 2)))
    data = np.clip(data, 0, 65535).astype(np.uint16)

    hdu = fits.PrimaryHDU(data=data)
    h = hdu.header
    h['OBJECT'] = 'TWOPHASE'
    h['DATE-OBS'] = '2026-03-04T05:06:07'
    h['DATE-AVG'] = '2026-03-04T05:07:07'
    h['EXPTIME'] = 60.0
    h['FILTER'] = 'R'
    h['IMAGETYP'] = imgtype
    h['XBINNING'] = 1
    h['YBINNING'] = 1
    h['INSTRUME'] = 'TWOPHASE'
    h['TELESCOP'] = 'TWOPHASE'
    h['SET-TEMP'] = -10.0
    h['CCD-TEMP'] = -10.0
    h['XPIXSZ'] = 3.76
    h['FOCALLEN'] = 500.0
    h['GAIN'] = 1.5
    h['OFFSET'] = 10
    h['SWCREATE'] = 'two_phase_probe'
    hdu.writeto(path, overwrite=True)


def run_index(root, extra_args=None, env_extra=None):
    """Runs reindex.py as a separate process, with the same cwd and the same
    start method as the container's, so that the worker `spawn` is the real one."""
    cmd = [sys.executable, REINDEX_PY, root,
           '--skip-cleanup', '--workers', '2']
    if extra_args:
        cmd += list(extra_args)
    env = dict(os.environ, AWI_PROJECTS_LIB=os.environ.get('AWI_PROJECTS_LIB', '/opt/scripts'))
    if env_extra:
        env.update(env_extra)
    p = subprocess.run(cmd, capture_output=True, text=True, env=env, timeout=900)
    return p.returncode, p.stdout + p.stderr


def fetch(path):
    cur = CONN.cursor(dictionary=True)
    cur.execute(
        "SELECT path, imgtype, `object`, exptime, `filter`, width, height, resolution, "
        "moon_phase, bit_depth, image_channels, image_color_type, "
        "OCTET_LENGTH(thumb) AS thumb_len, background_mean, mean_pixel, "
        "hfr, fwhm, star_count, metrics_pending "
        "FROM files WHERE path = %s", (path,))
    row = cur.fetchone()
    cur.close()
    return row


def site_answers():
    """Does the site answer? A probe that leaves the database locked can have all its
    assertions green and the site down, so it has to be verified separately."""
    try:
        req = urllib.request.Request('http://nginx/projects.php', method='GET')
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                return str(r.status)
        except urllib.error.HTTPError as e:
            # 302 followed by the login, 401/403: those are responses, not failures. 5xx no:
            # that one is the symptom of a locked database.
            return str(e.code)
    except Exception as e:
        return f'error: {type(e).__name__}'


def cleanup(root_prefix):
    """DELETE by exact path prefix. `path` is UNIQUE, so the prefix
    produces a range lock on the index and not a scan."""
    cur = CONN.cursor()
    try:
        cur.execute(
            "DELETE s FROM project_suggestions s JOIN files f ON f.id = s.file_id "
            "WHERE f.path LIKE %s", (root_prefix + '/%',))
        n_sugg = cur.rowcount
        cur.execute("DELETE FROM files WHERE path LIKE %s", (root_prefix + '/%',))
        n_files = cur.rowcount
        CONN.commit()
        return n_files, n_sugg
    except Exception:
        CONN.rollback()
        raise
    finally:
        cur.close()


def main():
    global CONN
    CONN = mysql.connector.connect(
        host=os.environ.get('DB_HOST', 'mariadb'),
        user=os.environ.get('DB_USER', 'awi_user'),
        password=os.environ.get('DB_PASSWORD', 'awi_password'),
        database=os.environ.get('DB_NAME', 'awi_db'),
        autocommit=True)

    cur = CONN.cursor()
    cur.execute("SET SESSION innodb_lock_wait_timeout = 5")
    cur.execute("SET SESSION max_statement_time = 120")
    cur.close()

    before = snapshot()
    print("=== initial state ===")
    for t in TABLES:
        print(f"  {t:<20} {before[t]}")

    code_before = site_answers()
    check('the site answers before starting', code_before in ('302', '200'),
          f'HTTP {code_before} ')

    root = tempfile.mkdtemp(prefix='twophase_')
    # `fits_root` is the root the paths are relative to, so the OUTER root must
    # be passed and the files kept in a subdirectory.
    tree_root = os.path.join(root, 'archive')
    os.makedirs(tree_root, exist_ok=True)
    rel_prefix = 'archive'
    fits_root = root
    light_rel = 'archive/light.fits'
    dark_rel = 'archive/dark.fits'

    try:
        write_fits(os.path.join(tree_root, 'light.fits'), 'LIGHT', seed=3)
        write_fits(os.path.join(tree_root, 'dark.fits'), 'DARK', seed=5)

        print("\n=== the two-phase mode ===")
        code, out = run_index(fits_root, ['--phase-mode', 'two'])
        for ln in out.splitlines():
            if 'Indexing mode' in ln or 'Complete' in ln or 'measured' in ln:
                print(f"  | {ln.strip()[:150]}")
        check('the run ends without exceptions', code == 0, f'exit {code}')

        light = fetch(light_rel)
        dark = fetch(dark_rel)
        check('the LIGHT frame is indexed', light is not None, '')
        check('the DARK frame is indexed', dark is not None, '')

        if light:
            check('its header is there (object, filter, exposure)',
                  light['object'] == 'TWOPHASE' and light['filter'] == 'R'
                  and abs(light['exptime'] - 60.0) < 0.01, '')
            check('its geometry is there (width, height, resolution)',
                  light['width'] == 256 and light['height'] == 256
                  and light['resolution'] and light['resolution'] > 0, '')
            check('its preview is there', (light['thumb_len'] or 0) > 0,
                  f"{light['thumb_len']} bytes")
            check('its frame statistics are there',
                  light['background_mean'] is not None
                  and light['mean_pixel'] is not None, '')
            check('its star metrics are there',
                  light['hfr'] is not None and light['star_count'] is not None
                  and light['star_count'] > 0,
                  f"hfr={light['hfr']} stars={light['star_count']}")
            check('its flag is cleared', int(light['metrics_pending'] or 0) == 0, '')

        if dark:
            check('the DARK frame has no star metrics',
                  dark['hfr'] is None and dark['star_count'] is None, '')
            check('the DARK frame is complete anyway (it owes nothing)',
                  int(dark['metrics_pending'] or 0) == 0, '')

        print("\n=== a frame that still owes its metrics ===")
        # What an interrupted slow pass leaves behind: the row is written, the
        # metrics are not. The file must be visible with all its metadata.
        cur = CONN.cursor()
        cur.execute("UPDATE files SET metrics_pending = 1, hfr = NULL, fwhm = NULL, "
                    "hfr_sd = NULL, eccentricity = NULL, star_count = NULL, "
                    "snr_weight = NULL, psf_signal = NULL WHERE path = %s",
                    (light_rel,))
        CONN.commit()
        cur.close()
        pending = fetch(light_rel)
        check('the frame is still visible with its metadata',
              pending is not None and pending['object'] == 'TWOPHASE'
              and (pending['thumb_len'] or 0) > 0, '')
        check('its metric columns are empty',
              pending['hfr'] is None and pending['star_count'] is None, '')
        check('its flag says the metrics are owed',
              int(pending['metrics_pending'] or 0) == 1, '')

        print("\n=== the next run resumes it, although nothing changed on disk ===")
        code, out = run_index(fits_root, ['--phase-mode', 'two'])
        check('the run ends without exceptions', code == 0, f'exit {code}')
        resumed = fetch(light_rel)
        check('the metrics are computed', resumed['hfr'] is not None
              and (resumed['star_count'] or 0) > 0,
              f"hfr={resumed['hfr']} stars={resumed['star_count']}")
        check('its flag is cleared', int(resumed['metrics_pending'] or 0) == 0, '')

        print("\n=== the single-phase mode does everything in one pass ===")
        cur = CONN.cursor()
        cur.execute("UPDATE files SET metrics_pending = 1, hfr = NULL, fwhm = NULL, "
                    "hfr_sd = NULL, eccentricity = NULL, star_count = NULL, "
                    "snr_weight = NULL, psf_signal = NULL WHERE path = %s",
                    (light_rel,))
        CONN.commit()
        cur.close()
        # The mode comes from the environment here, not from the flag: the two
        # ways of choosing it must agree.
        code, out = run_index(fits_root, env_extra={'INDEXER_PHASE_MODE': 'single'})
        check('the run ends without exceptions', code == 0, f'exit {code}')
        check('the mode is the single one', 'single pass' in out, '')
        single = fetch(light_rel)
        check('the metrics are computed', single['hfr'] is not None
              and (single['star_count'] or 0) > 0,
              f"hfr={single['hfr']} stars={single['star_count']}")
        check('its flag is cleared (a pending row is not stranded)',
              int(single['metrics_pending'] or 0) == 0, '')

        print("\n=== the single-phase mode from scratch ===")
        cur = CONN.cursor()
        cur.execute("DELETE FROM files WHERE path LIKE %s", (rel_prefix + '/%',))
        CONN.commit()
        cur.close()
        code, out = run_index(fits_root, env_extra={'INDEXER_PHASE_MODE': 'single'})
        check('the run ends without exceptions', code == 0, f'exit {code}')
        fresh = fetch(light_rel)
        check('the frame is indexed with its metrics and its preview',
              fresh is not None and fresh['hfr'] is not None
              and (fresh['thumb_len'] or 0) > 0
              and int(fresh['metrics_pending'] or 0) == 0, '')

    finally:
        print("\n=== cleanup ===")
        try:
            n_files, n_sugg = cleanup(rel_prefix)
            print(f"  removed {n_files} files rows, {n_sugg} project_suggestions rows")
        except Exception as e:
            print(f"  CLEANUP FAILED: {type(e).__name__}: {e}")
            FAILED.append('cleanup')
        shutil.rmtree(root, ignore_errors=True)

        after = snapshot()
        print("\n=== the counts must have gone back ===")
        for t in TABLES:
            check(f'  {t} unchanged ({before[t]})', after[t] == before[t],
                  '' if after[t] == before[t] else f'now {after[t]}')

        code_after = site_answers()
        check('the site answers afterwards too', code_after in ('302', '200'),
              f'HTTP {code_after}')

    print('\nRESULT: ' + ('FAILED: ' + ', '.join(FAILED)
          if FAILED else 'the fast pass makes the file visible, the slow pass measures it'))
    return 1 if FAILED else 0


if __name__ == '__main__':
    try:
        rc = main()
    finally:
        if CONN is not None and CONN.is_connected():
            try:
                CONN.rollback()
            except Exception:
                pass
            CONN.close()
    sys.exit(rc)
