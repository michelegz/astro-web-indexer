#!/usr/bin/env python3
"""End-to-end: a record the database rejects must not stop the rest of the pass.

This is the gap declared in the d4ac075 message: «Not covered by an end-to-end
test». It fills it, and doing so requires writing synthetic rows into the live
`files` table, because awi_user cannot create an isolated schema (1044 on
CREATE DATABASE).

The mechanism of the defect (the all-or-nothing of executemany) was already
proven by executemany_atomicity.py. Here the REAL LOOP of reindex.py is tested
against the real table, end to end without HTTP but with the real script.

Why 'files' accepts the poison
------------------------------
`filter` is varchar(50) and sql_mode contains STRICT_TRANS_TABLES, so a FITS header
with an 80-character FILTER makes the INSERT fail with 1406 Data too long. Verified before
writing this test: a record with an 80-character FILTER raises
`DataError 1406 (22001): Data too long for column 'filter'`, and an executemany with one
good record, one poisonous and one good raises and leaves nothing.

Why 4 subdirectories of 50 files
--------------------------------
reindex.py has `commit_interval = 50` hardcoded and empties batch_params only AFTER the
flush has succeeded. So every flush covers 50 records. With 4 subdirectories of 50 files the
worker result arrives in blocks of 50, and the poisonous record is by construction
in one of those blocks: exactly ONE flush fails and the other three pass.

The expected shape is therefore deterministic and independent of the order in which os.walk
returns the directories: 3 flushes out of 4 succeed, 150 of the 199 good files end up in
the database, the poisonous one does not.

The pre-fix case, which is the proof that the test discriminates:

  poisonous record in a NON-final block -> the flush failed and the list was NOT emptied,
  so every subsequent flush repeated the poisonous record and failed the same way: 0 files
  committed, and the final tail failed taking the script out with code 1.

  poisonous record in the LAST block -> the first three flushes passed (150 committed), but the
  tail flush, which is OUTSIDE the try, repeated the poisonous record and propagated: exit 1.

In both pre-fix cases the test fails: on the number of files, or on the exit
code. That is why the test asserts both and not just the count: the
count alone would be ambiguous in the unlucky ordering.

The tail flush stays unguarded on purpose (see the d4ac075 message): a bad
record in the last block fails loudly *after* everything else has already been
committed. This test does not prove that it gets fixed: it proves the rest holds up.

Hygiene (learned by losing the service)
---------------------------------------
A previous version of this probe used TWO connections on the same table and
left a transaction open: the commit hung for 246 seconds and the site
answered 504 until the restart. And a later version read 0 rows after the
reindex had written 4, because mysql.connector has autocommit off by default and
the implicit transaction held a stale REPEATABLE READ snapshot. Hence the rules,
not optional:

  * a single connection for all the test work, with autocommit=True
  * rollback and close ALWAYS in the finally, even if the script dies halfway
  * low innodb_lock_wait_timeout: contention must raise, not wait
  * a unique path prefix per run, and DELETE on that exact prefix
  * a final check that the site answers, and verification that the counts went back
    to the initial state

Usage:
  docker cp tmp/reindex_batch_continue_check.py awi-python:/tmp/
  docker exec -e AWI_PROJECTS_LIB=/opt/scripts awi-python \
    sh -c 'cd /tmp && python reindex_batch_continue_check.py'
"""

import glob
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


def write_fits(path, filt, exptime=60.0, obj='BATCHTARGET'):
    """A minimal but valid FITS, with the header that process_file_worker reads."""
    hdu = fits.PrimaryHDU(data=np.zeros((64, 64), dtype=np.uint16))
    h = hdu.header
    h['OBJECT'] = obj
    h['DATE-OBS'] = '2026-03-04T05:06:07'
    h['DATE-AVG'] = '2026-03-04T05:07:07'
    h['EXPTIME'] = exptime
    h['FILTER'] = filt
    h['IMAGETYP'] = 'LIGHT'
    h['XBINNING'] = 1
    h['YBINNING'] = 1
    h['INSTRUME'] = 'BATCHPROBE'
    h['TELESCOP'] = 'BATCHPROBE'
    h['SET-TEMP'] = -10.0
    h['CCD-TEMP'] = -10.0
    h['XPIXSZ'] = 3.76
    h['GAIN'] = 1.5
    h['OFFSET'] = 10
    h['SWCREATE'] = 'batch_probe'
    hdu.writeto(path, overwrite=True)


def build_tree(root, per_dir, dirs):
    """4 subdirectories of `per_dir` files. The poisonous one is file 0 of the first."""
    poison_rel = None
    total = 0
    for d in range(dirs):
        sub = os.path.join(root, f"d{d}")
        os.makedirs(sub, exist_ok=True)
        for i in range(per_dir):
            total += 1
            is_poison = (d == 0 and i == 0)
            # 80-character FILTER: varchar(50) with STRICT_TRANS_TABLES -> 1406.
            filt = 'P' * 80 if is_poison else 'R'
            name = f"{total:04d}.fits"
            write_fits(os.path.join(sub, name), filt)
            if is_poison:
                poison_rel = f"d{d}/{name}"
    return total, poison_rel


REINDEX_PY = os.environ.get('REINDEX_PY', '/opt/scripts/reindex.py')
LIB_DIR = os.environ.get('AWI_PROJECTS_LIB', '/opt/scripts')


def run_index(root):
    """Runs reindex.py as a separate process, with the same cwd and the same start
    method as line 1 of /opt/scripts, so that the worker `spawn` is the real one.

    REINDEX_PY and LIB_DIR can point elsewhere to try the pre-fix version without
    writing on the /opt/scripts mount: that one is grpcfuse and a write inside ends up in
    the repo (trap #38).
    """
    cmd = [sys.executable, REINDEX_PY, root,
           '--force', '--skip-cleanup', '--workers', '2', '--no-star-metrics']
    env = dict(os.environ, AWI_PROJECTS_LIB=LIB_DIR)
    print(f"  reindex: {REINDEX_PY}")
    print(f"  lib    : {LIB_DIR}")
    p = subprocess.run(cmd, capture_output=True, text=True, env=env, timeout=900)
    return p.returncode, p.stdout + p.stderr


def site_answers():
    """Does the site answer? A probe that leaves the database locked can have all its
    assertions green and the site down, so it has to be verified separately.

    No `docker exec`: this script runs INSIDE awi-python, where the docker CLI does not
    exist. `http://nginx` does resolve from the Docker network, so the request is
    made from here with urllib.
    """
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
        # autocommit=True is NOT the mysql.connector default (which is False), and it is the
        # reason why this version of the test read 0 rows after the reindex
        # had written 4. With autocommit off, the implicit transaction opened by the
        # first SELECT holds a REPEATABLE READ snapshot: subsequent SELECTs see the
        # database as it was at that moment, not the one updated by the process under test.
        # A DELETE instead sees them updated, because locking reads always use
        # the last committed version: so the same prefix query counted 0 and
        # deleted 4, which is the kind of contradiction that makes a diagnosis go crazy.
        # With autocommit every statement is immediately visible to everyone, and the cleanup
        # cannot forget a commit.
        autocommit=True)

    # Low timeouts on purpose. The InnoDB default is 50 seconds: if by mistake
    # this script touched contended rows, a long wait would block the site too
    # instead of failing the test. An exception is better than a service down.
    cur = CONN.cursor()
    cur.execute("SET SESSION innodb_lock_wait_timeout = 5")
    # max_statement_time, not max_execution_time: they are the same concept with
    # different names, and `max_execution_time` on MariaDB answers
    # "1193 Unknown system variable". In seconds.
    cur.execute("SET SESSION max_statement_time = 120")
    cur.close()

    before = snapshot()
    print("=== initial state ===")
    for t in TABLES:
        print(f"  {t:<20} {before[t]}")

    code_before = site_answers()
    check('the site answers before starting', code_before in ('302', '200'),
          f'HTTP {code_before} ')

    root = tempfile.mkdtemp(prefix='batchprobe_')
    # `fits_root` is the root the paths are relative to, so the OUTER root must be
    # passed and the files kept in a subdirectory: it is that subdirectory that
    # appears in the `path` field, and it is the prefix the cleanup queries must use.
    # Passing the subdirectory instead, `path` would have been just the file name and the
    # expected prefix would never have matched: the cleanup would have left everything
    # inside and the final count would have failed (I found out with a 4-file smoke,
    # which also deposited 4 spurious rows to clean up by hand).
    tree_root = os.path.join(root, 'archive')
    os.makedirs(tree_root, exist_ok=True)
    rel_prefix = 'archive'
    fits_root = root

    try:
        total, poison_rel = build_tree(tree_root, 50, 4)
        good = total - 1
        n_disk = len(glob.glob(os.path.join(tree_root, '**', '*.fits'), recursive=True))
        print("\n=== test tree ===")
        check('the files on disk are the expected ones', n_disk == total,
              f'{n_disk} files, expected {total}')
        check('the poisonous one exists and is an overlong FILTER',
              os.path.exists(os.path.join(tree_root, poison_rel)),
              poison_rel)

        print("\n=== reindex.py on the test tree ===")
        code, out = run_index(fits_root)
        tail = [ln for ln in out.splitlines() if 'Progress' in ln or 'Error' in ln
                or 'Complete' in ln or 'errors' in ln.lower()][-6:]
        for ln in tail:
            print(f"  | {ln.strip()[:150]}")
        print(f"  exit code: {code}")

        cur = CONN.cursor()
        cur.execute("SELECT path FROM files WHERE path LIKE %s", (rel_prefix + '/%',))
        indexed = {r[0] for r in cur.fetchall()}
        cur.close()

        n_present = len(indexed)
        n_good = sum(1 for p in indexed if not p.endswith(poison_rel))
        poison_present = any(p.endswith(poison_rel) for p in indexed)

        print("\n=== what ended up in the database ===")
        check('the run ends without exceptions', code == 0, f'exit {code}')
        check('the poisonous file was NOT inserted', not poison_present,
              f'present={poison_present}')
        check('exactly 3 flushes out of 4 committed', n_present == 150,
              f'{n_present} rows, expected 150 (3 blocks of 50)')
        check('all present rows are good files', n_good == n_present,
              f'{n_good} good out of {n_present}')
        # The 50 records of the lost block are 49 good ones and the poisonous one: the
        # absent among the good ones are therefore 49, not 50. Saying 50 would have been
        # a false positive, and the version of this check that said 50 was the one that failed.
        absent_good = good - n_good
        check('the lost block contains 49 good ones plus the poisonous one',
              absent_good == 49 and absent_good + 1 == 50,
              f'{absent_good} good absent, +1 poisonous = {absent_good + 1} records lost')
        check('no good one is missing outside the lost block',
              absent_good == 49,
              f'{absent_good} good absent, all in the lost block')

        # The shape matters more than the number: the 50 absent ones must all sit in ONE
        # subdirectory, and not disappear at random across the whole tree.
        #
        # WHICH block gets lost is NOT an invariant: `os.walk` returns the
        # directories in filesystem order, which changes from one temporary directory
        # to the next, so the poisonous one can land in d0 in one run and in d1 in the
        # next. A version of this check asserted `lost == [0]` and passed
        # because in that run the case was d0: it is a statement true only by
        # chance, so it is reported as information and not verified. The stable
        # invariants are the numbers: only one flush fails, three pass, and the lost block
        # disappears entirely.
        per_dir_present = {
            d: sum(1 for p in indexed if p.startswith(f'archive/d{d}/'))
            for d in range(4)
        }
        intact = [d for d, n in per_dir_present.items() if n == 50]
        lost = [d for d, n in per_dir_present.items() if n == 0]
        check('only one block lost, the other three are intact',
              len(intact) == 3 and len(lost) == 1,
              f'intact={intact}, lost={lost}, per block={per_dir_present}')
        check('no block is half done',
              all(n in (0, 50) for n in per_dir_present.values()),
              f'per block={per_dir_present}')
        print(f"  note: lost blocks = {lost} (the poisonous one was in just one of these). "
              f"Out of 4 flushes the one with the poisonous record fails and that is all; "
              f"on the pre-fix two were lost, because the list was not emptied.")

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
          if FAILED else 'a record rejected by the DB does not stop the rest of the pass'))
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
