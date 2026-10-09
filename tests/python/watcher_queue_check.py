#!/usr/bin/env python3
"""Check 13.28, 13.29, 13.10 — watcher and AstroBin.

  13.28 scan_and_detect() was dead code, but --rescan-interval was parsed,
         stored and used only there: an operator setting RESCAN_INTERVAL
         saw from --help that deletions were detected periodically,
         and nothing was actually checking for them.
  13.29 process_suggest_queue() opened and closed a connection on every
         iteration (~1/s, ~86,400 handshakes a day) and ran a write UPDATE
         every second on a table that is almost always empty.
  13.10 the file ids went into the request line: a few thousand files
         exceed 8 KB and the request died with 414 before reaching the
         application code. Verified on the PHP side in export_ids_check.php.

Usage:  docker cp tmp/watcher_queue_check.py awi-python:/tmp/
        docker exec awi-python sh -c 'cd /tmp && python3 watcher_queue_check.py'
"""

import importlib.util
import os
import sys
import types

sys.path.insert(0, '/opt/scripts')

WATCH = os.environ.get('AWI_WATCH_FS', '/opt/scripts/watch_fs.py')
spec = importlib.util.spec_from_file_location('watch_fs', WATCH)
watch_fs = importlib.util.module_from_spec(spec)
spec.loader.exec_module(watch_fs)

FAILED = []


def check(label, cond, detail=''):
    suffix = 'OK' if cond else '<<< FAILED'
    print('  %-58s %s%s' % (label, detail, suffix))
    if not cond:
        FAILED.append(label)


# ---------------------------------------------------------------- 13.28
print('\n=== 13.28: the periodic rescan must be wired up ===\n')

src = open(WATCH, encoding='utf-8').read()

check('scan_and_detect exists', hasattr(watch_fs.FitsHandler, 'scan_and_detect'), '')

# The point is not that the function exists, but that something calls it: before,
# nothing did and the --rescan-interval flag was inert.
main_loop = src.split('logging.info(f"Started monitoring directory', 1)
main_loop = main_loop[1] if len(main_loop) > 1 else ''
check('the main loop calls scan_and_detect',
      'event_handler.scan_and_detect()' in main_loop, '')

# And it must be self-limiting, otherwise calling it every iteration means
# a recursive scan every second.
check('it self-limits with rescan_interval',
      'self.last_scan < self.rescan_interval' in src, '')

# The flag must still exist and be passed to the handler, otherwise "wiring up"
# the function means reshuffling the CLI contract.
check('--rescan-interval still exists', '--rescan-interval' in src, '')
check('  and it reaches the handler', 'rescan_interval=args.rescan_interval' in src, '')

# The function must walk the tree: it is the only check for missed events.
fn = src.split('def scan_and_detect', 1)[1].split('\n    def ', 1)[0]
check('the scan compares created and deleted',
      'created = current - self.known_files' in fn
      and 'deleted = self.known_files - current' in fn, '')

# ---------------------------------------------------------------- 13.29
print('\n=== 13.29: one connection, not one per second ===\n')

# Behavioural: two calls must reuse the same connection.
connects = []
polls = []


class FakeConn:
    def __init__(self):
        self.closed = False
        self.commits = 0

    def ping(self, reconnect=False, attempts=1, delay=0):
        if self.closed:
            raise RuntimeError('MySQL Connection not available')

    def cursor(self, dictionary=False):
        return FakeCursor()

    def commit(self):
        self.commits += 1

    def close(self):
        self.closed = True


class FakeCursor:
    def __init__(self):
        self._rows = []

    def execute(self, sql, params=None):
        polls.append(sql)
        if 'SELECT id, project_id' in sql:
            self._rows = []
        else:
            self._rows = []

    def fetchone(self):
        return None

    @property
    def rowcount(self):
        return 0


fake_mysql = types.ModuleType('mysql.connector')


def fake_connect(**kwargs):
    connects.append(kwargs)
    return FakeConn()


fake_mysql.connect = fake_connect
fake_pkg = types.ModuleType('mysql')
fake_pkg.connector = fake_mysql
sys.modules['mysql'] = fake_pkg
sys.modules['mysql.connector'] = fake_mysql

# suggest_projects_backfill must not be reached: no pending row, so it is not
# called, but the module must still import.
fake_lib = types.ModuleType('indexer_lib.projects')
fake_lib.suggest_projects_backfill = lambda conn, pid: {}
sys.modules.setdefault('indexer_lib', types.ModuleType('indexer_lib'))
sys.modules['indexer_lib.projects'] = fake_lib

watch_fs._suggest_conn = None
watch_fs._last_stale_reset = 0.0

params = {'host': 'h', 'user': 'u', 'password': 'p', 'database': 'd'}
for _ in range(5):
    watch_fs.process_suggest_queue(params)

check('five calls -> a single connection', len(connects) == 1,
      'connections opened=%d' % len(connects))

# The reset of orphaned running rows: once, not five times.
resets = [s for s in polls if "status = 'pending', started_at = NULL" in s]
check('the orphaned running reset runs once only', len(resets) == 1,
      'resets run=%d over 5 calls' % len(resets))

# And the connection is not closed on every call: closing means going back
# into the reconnect branch and repeating the handshake.
src_fn = src.split('def process_suggest_queue', 1)[1].split('\ndef ', 1)[0]
check('no conn.close() per call', 'conn.close()' not in src_fn, '')
check('  it uses the module-level cache', '_suggest_conn' in src_fn, '')

# A dropped connection must be recovered, not kept as valid.
c = FakeConn()
c.close()
watch_fs._suggest_conn = c
watch_fs._last_stale_reset = watch_fs.time.time()
watch_fs.process_suggest_queue(params)
check('dropped connection -> reopened', len(connects) == 2,
      'connections opened=%d' % len(connects))
watch_fs._suggest_conn = None

# The connector must not be imported on every call with just the module in
# sys.modules: that is fine, but the import must not sit inside a loop running every
# second. I only check that it is not gone.
check('the driver import stays guarded', 'ImportError' in src_fn, '')

print('\nRESULT: ' + ('FAILED: ' + ', '.join(FAILED) if FAILED
                     else 'watcher wired up and queue on a single connection'))
# The exit code is what run.sh records. Without it the script falls off the end and
# returns 0 even when it printed FAILURES.
sys.exit(1 if FAILED else 0)