#!/usr/bin/env python3
"""End-to-end: the star metrics profile.

The indexer measures the star metrics with a profile, chosen by the
environment variable STAR_METRICS_PROFILE or by the --star-metrics-profile
flag. A profile sets the two knobs that decide which stars a frame has: the
detection threshold and the minimum area.

What is verified here:

  1. the three profiles really measure different things (the fixture is
     checked to discriminate, or the rest of the test would prove nothing);
  2. the environment variable picks the profile;
  3. the flag beats the environment variable;
  4. an unknown name warns and falls back to the default, and never fails;
  5. the default profile still measures what it always measured (5.0 sigma,
     minimum area 9 px): the numbers already in the archives must not move.

Why the comparison is exact
---------------------------
The expectation is computed here, in this process, through the same functions
the worker uses (open_frame + analyze_frame), and the measurement that lands
in the database is compared with it. The measurement is deterministic, so the
two agree to the last bit - and the profiles differ by about 2% of the HFR,
which is six orders of magnitude more than the tolerance. A flag that did not
reach the spawned workers would leave the default measurement in the
database, and the test would say so.

Hygiene (the rules of reindex_batch_continue_check.py apply here too):
a single connection for all the test work, a unique path prefix per run,
DELETE on that exact prefix, and a final check that the counts went back.

Usage:
  docker cp tests/python/star_profile_check.py awi-python:/tmp/
  docker exec -e AWI_PROJECTS_LIB=/opt/scripts awi-python \
    sh -c 'cd /tmp && python star_profile_check.py'
"""

import os
import shutil
import subprocess
import sys
import tempfile

import mysql.connector
import numpy as np
from astropy.io import fits

sys.path.insert(0, os.environ.get('AWI_PROJECTS_LIB', '/opt/scripts'))
from indexer_lib import star_analysis as sa
from indexer_lib.frame_extract import open_frame

FAILED = []
CONN = None
TABLES = ('files', 'project_suggestions', 'project_files', 'project_setups',
          'projects', 'project_panels', 'project_sessions')

REINDEX_PY = os.environ.get('REINDEX_PY', '/opt/scripts/reindex.py')
# The relative tolerance of the comparison with the expected measurement. The
# measurement is deterministic, so this only has to absorb the storage in a
# DOUBLE column: the profiles differ by ~2% of the HFR, six orders of
# magnitude more than this.
TOL = 1e-6


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


def write_frame(path, n_stars=300, size=1024, seed=3):
    """A field where the faint detections are the bloated ones.

    The bright stars are narrow and the faint ones wide, so dropping the faint
    and marginally resolved ones (what the fast profile does) moves the mean
    HFR by a couple of percent - the same way it moves on a real frame. The
    test needs a fixture the profiles can be told apart on, and this is it.
    """
    rng = np.random.default_rng(seed)
    img = rng.normal(1000.0, 12.0, (size, size))
    ys = rng.integers(30, size - 30, n_stars)
    xs = rng.integers(30, size - 30, n_stars)
    flux = 10.0 ** rng.uniform(2.3, 3.3, n_stars)
    order = np.argsort(flux)
    r = 14
    yy, xx = np.mgrid[-r:r+1, -r:r+1]
    for i in order:
        # the fainter the star, the wider its profile
        t = (flux[i] - flux.min()) / (flux.max() - flux.min())
        w = 1.5 + 1.8 * (1.0 - t)
        g = np.exp(-((xx**2 + yy**2) / (2.0 * w**2)))
        y, x = ys[i], xs[i]
        img[y - r:y + r + 1, x - r:x + r + 1] += flux[i] * g
    data = np.clip(img, 0, 65535).astype(np.uint16)

    hdu = fits.PrimaryHDU(data=data)
    h = hdu.header
    h['OBJECT'] = 'PROFILE'
    h['DATE-OBS'] = '2026-03-04T05:06:07'
    h['DATE-AVG'] = '2026-03-04T05:07:07'
    h['EXPTIME'] = 60.0
    h['FILTER'] = 'R'
    h['IMAGETYP'] = 'LIGHT'
    h['XBINNING'] = 1
    h['YBINNING'] = 1
    h['XPIXSZ'] = 3.76
    h['FOCALLEN'] = 500.0
    hdu.writeto(path, overwrite=True)


def expected(path, profile):
    """What the analyzer measures for this frame under this profile.

    Computed in this process, through the same functions the worker uses, so
    the comparison with what lands in the database is exact.
    """
    sa.apply_profile(profile)
    ctx = open_frame(path)
    return sa.analyze_frame(ctx.data)


def run_index(root, extra_args=None, env_extra=None):
    """Runs reindex.py as a separate process.

    The caller's STAR_METRICS_PROFILE is dropped: the test decides what the
    run sees, and a profile left over in the environment would decide for it.
    """
    cmd = [sys.executable, REINDEX_PY, root, '--skip-cleanup', '--workers', '1']
    if extra_args:
        cmd += list(extra_args)
    env = dict(os.environ)
    env.pop('STAR_METRICS_PROFILE', None)
    if env_extra:
        env.update(env_extra)
    p = subprocess.run(cmd, capture_output=True, text=True, env=env, timeout=900)
    return p.returncode, p.stdout + p.stderr


def fetch(path):
    cur = CONN.cursor(dictionary=True)
    cur.execute("SELECT hfr, fwhm, star_count, metrics_pending FROM files "
                "WHERE path = %s", (path,))
    row = cur.fetchone()
    cur.close()
    return row


def close_to(measured, expected_value):
    if measured is None or expected_value is None:
        return False
    return abs(measured - expected_value) <= TOL * max(abs(expected_value), 1e-9)


def cleanup(prefix):
    cur = CONN.cursor()
    try:
        cur.execute("DELETE FROM files WHERE path LIKE %s", (prefix + '/%',))
        n = cur.rowcount
        CONN.commit()
        return n
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

    before = snapshot()
    print("=== initial state ===")
    for t in TABLES:
        print(f"  {t:<20} {before[t]}")

    # A unique prefix per run: the cleanup deletes on it, and a prefix that
    # could match the real archive would be a very bad idea.
    prefix = f'profile_probe_{os.getpid()}'
    root = tempfile.mkdtemp(prefix='starprofile_')
    tree_root = os.path.join(root, prefix)
    os.makedirs(tree_root, exist_ok=True)
    frame_path = os.path.join(tree_root, 'frame.fits')
    rel_path = f'{prefix}/frame.fits'

    try:
        write_frame(frame_path)

        print("\n=== the profiles must measure different things ===")
        expectations = {}
        for profile in ('fast', 'default', 'sensitive'):
            expectations[profile] = expected(frame_path, profile)
            r = expectations[profile]
            check(f'the {profile} profile measures the frame',
                  r is not None and r['hfr_avg'] is not None,
                  f"hfr={r['hfr_avg']:.4f} stars={r['star_count']}")
        hfrs = {p: expectations[p]['hfr_avg'] for p in expectations}
        spread = (max(hfrs.values()) - min(hfrs.values())) / max(hfrs.values())
        check('the fixture tells the profiles apart', spread > 1e-3,
              f'{spread*100:.2f}% of HFR between them')

        print("\n=== the default profile still measures what it always did ===")
        check('the default is 5.0 sigma, minimum area 9 px',
              sa.PROFILES['default'] == {'thresh_sigma': 5.0, 'min_area': 9},
              str(sa.PROFILES['default']))

        print("\n=== the environment variable picks the profile ===")
        code, out = run_index(root, env_extra={'STAR_METRICS_PROFILE': 'fast'})
        check('the run ends without exceptions', code == 0, f'exit {code}')
        check('the profile is named in the log',
              'Star metrics profile: fast' in out, '')
        row = fetch(rel_path)
        check('the frame is indexed', row is not None, '')
        check('it was measured with the fast profile',
              close_to(row['hfr'], expectations['fast']['hfr_avg']),
              f"hfr={row['hfr']} expected={expectations['fast']['hfr_avg']:.6f}")
        check('and not with the default one',
              not close_to(row['hfr'], expectations['default']['hfr_avg']), '')

        print("\n=== the flag beats the environment variable ===")
        # --force: the file did not change on disk, and without it the run
        # would skip it and keep the measurement of the previous run.
        code, out = run_index(root, extra_args=['--force', '--star-metrics-profile',
                                                 'default'],
                               env_extra={'STAR_METRICS_PROFILE': 'fast'})
        check('the run ends without exceptions', code == 0, f'exit {code}')
        check('the profile is named in the log',
              'Star metrics profile: default' in out, '')
        row = fetch(rel_path)
        check('it was measured with the default profile',
              close_to(row['hfr'], expectations['default']['hfr_avg']),
              f"hfr={row['hfr']} expected={expectations['default']['hfr_avg']:.6f}")
        check('and not with the fast one',
              not close_to(row['hfr'], expectations['fast']['hfr_avg']), '')

        print("\n=== an unknown name warns and falls back ===")
        code, out = run_index(root, extra_args=['--force'],
                              env_extra={'STAR_METRICS_PROFILE': 'nonsense'})
        check('the run ends without exceptions', code == 0, f'exit {code}')
        check('the unknown name is reported',
              "STAR_METRICS_PROFILE='nonsense'" in out, '')
        check('and the default profile is used',
              'Star metrics profile: default' in out, '')
        row = fetch(rel_path)
        check('it was measured with the default profile',
              close_to(row['hfr'], expectations['default']['hfr_avg']),
              f"hfr={row['hfr']}")

        print("\n=== an unknown name on the flag is refused, not guessed ===")
        code, out = run_index(root, extra_args=['--star-metrics-profile', 'nonsense'])
        check('the run stops with an error', code != 0, f'exit {code}')
        check('and says which names it knows', 'sensitive' in out, '')

        print("\n=== the sensitive profile is reachable from the flag too ===")
        code, out = run_index(root, extra_args=['--force', '--star-metrics-profile',
                                                 'sensitive'])
        check('the run ends without exceptions', code == 0, f'exit {code}')
        row = fetch(rel_path)
        check('it was measured with the sensitive profile',
              close_to(row['hfr'], expectations['sensitive']['hfr_avg']),
              f"hfr={row['hfr']} expected={expectations['sensitive']['hfr_avg']:.6f}")

    finally:
        print("\n=== cleanup ===")
        try:
            n = cleanup(prefix)
            print(f"  removed {n} files rows")
        except Exception as e:
            print(f"  CLEANUP FAILED: {type(e).__name__}: {e}")
            FAILED.append('cleanup')
        shutil.rmtree(root, ignore_errors=True)

        after = snapshot()
        print("\n=== the counts must have gone back ===")
        for t in TABLES:
            check(f'  {t} unchanged ({before[t]})', after[t] == before[t],
                  '' if after[t] == before[t] else f'now {after[t]}')

    print('\nRESULT: ' + ('FAILED: ' + ', '.join(FAILED)
          if FAILED else 'the profile reaches the workers, whichever way it was chosen'))
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
