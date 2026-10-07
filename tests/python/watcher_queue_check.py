#!/usr/bin/env python3
"""Verifica 13.28, 13.29, 13.10 — watcher e AstroBin.

  13.28 scan_and_detect() era dead code, ma --rescan-interval era analizzato,
         salvato e usato solo li': un operatore che impostava RESCAN_INTERVAL
         vedeva dal --help che le cancellazioni venivano rilevate periodicamente,
         e non c'era niente che le controllasse.
  13.29 process_suggest_queue() apriva e chiudeva una connessione a ogni
         iterazione (~1/s, ~86.400 handshake al giorno) e faceva un UPDATE di
         scrittura ogni secondo su una tabella quasi sempre vuota.
  13.10 gli id dei file andavano nella request line: qualche migliaio di file
         supera gli 8 KB e la richiesta moriva con 414 prima di arrivare al
         codice applicativo. Verificato lato PHP nel test export_ids_check.php.

Uso:  docker cp tmp/watcher_queue_check.py awi-python:/tmp/
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
    suffix = 'OK' if cond else '<<< FALLITO'
    print('  %-58s %s%s' % (label, detail, suffix))
    if not cond:
        FAILED.append(label)


# ---------------------------------------------------------------- 13.28
print('\n=== 13.28: il riscan periodico deve essere collegato ===\n')

src = open(WATCH, encoding='utf-8').read()

check('scan_and_detect esiste', hasattr(watch_fs.FitsHandler, 'scan_and_detect'), '')

# Il punto non e' che la funzione esista, ma che qualcosa la chiami: prima non la
# chiamava nessuno e il flag --rescan-interval era inerte.
main_loop = src.split('logging.info(f"Started monitoring directory', 1)
main_loop = main_loop[1] if len(main_loop) > 1 else ''
check('il loop principale chiama scan_and_detect',
      'event_handler.scan_and_detect()' in main_loop, '')

# E deve essere auto-limitata, altrimenti chiamarla a ogni iterazione significa
# una scansione ricorsiva al secondo.
check('si auto-limita con rescan_interval',
      'self.last_scan < self.rescan_interval' in src, '')

# Il flag deve esistere ancora ed essere passato al handler, altrimenti "collegare"
# la funzione significa reimpiattare il contratto CLI.
check('--rescan-interval esiste ancora', '--rescan-interval' in src, '')
check('  e arriva al handler', 'rescan_interval=args.rescan_interval' in src, '')

# La funzione deve attraversare l'albero: e' l'unico controllo degli eventi persi.
fn = src.split('def scan_and_detect', 1)[1].split('\n    def ', 1)[0]
check('la scansione confronta created e deleted',
      'created = current - self.known_files' in fn
      and 'deleted = self.known_files - current' in fn, '')

# ---------------------------------------------------------------- 13.29
print('\n=== 13.29: una connessione, non una al secondo ===\n')

# Comportamentale: due chiamate devono riusare la stessa connessione.
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

# suggest_projects_backfill non deve arrivare: nessuna riga pending, quindi non viene
# chiamato, ma il modulo deve importarsi.
fake_lib = types.ModuleType('indexer_lib.projects')
fake_lib.suggest_projects_backfill = lambda conn, pid: {}
sys.modules.setdefault('indexer_lib', types.ModuleType('indexer_lib'))
sys.modules['indexer_lib.projects'] = fake_lib

watch_fs._suggest_conn = None
watch_fs._last_stale_reset = 0.0

params = {'host': 'h', 'user': 'u', 'password': 'p', 'database': 'd'}
for _ in range(5):
    watch_fs.process_suggest_queue(params)

check('cinque chiamate -> una sola connessione', len(connects) == 1,
      'connessioni aperte=%d' % len(connects))

# Il reset dei running orfani: una volta sola, non cinque.
resets = [s for s in polls if "status = 'pending', started_at = NULL" in s]
check('il reset dei running orfani gira una volta sola', len(resets) == 1,
      'reset eseguiti=%d su 5 chiamate' % len(resets))

# E la connessione non viene chiusa a ogni chiamata: chiudere significa rientrare
# nel ramo di riconnessione e rifare l'handshake.
src_fn = src.split('def process_suggest_queue', 1)[1].split('\ndef ', 1)[0]
check('nessun conn.close() per chiamata', 'conn.close()' not in src_fn, '')
check('  usa la cache a livello di modulo', '_suggest_conn' in src_fn, '')

# Una connessione caduta deve essere recuperata, non tenuta per valida.
c = FakeConn()
c.close()
watch_fs._suggest_conn = c
watch_fs._last_stale_reset = watch_fs.time.time()
watch_fs.process_suggest_queue(params)
check('connessione caduta -> riaperta', len(connects) == 2,
      'connessioni aperte=%d' % len(connects))
watch_fs._suggest_conn = None

# Il connettore non deve essere importato a ogni chiamata con il solo modulo in
# sys.modules: va bene, ma l'import non deve stare dentro un ciclo che gira al
# secondo. Controllo solo che non sia sparito.
check('l\'import del driver resta protetto', 'ImportError' in src_fn, '')

print('\nRISULTATO: ' + ('FALLITI: ' + ', '.join(FAILED) if FAILED
                         else 'watcher collegato e coda su una connessione'))