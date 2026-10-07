#!/usr/bin/env python3
"""End-to-end: un record che il database rifiuta non deve fermare il resto della passata.

Questo e' il buco dichiarato nel messaggio di d4ac075: «Not covered by an end-to-end
test». Lo colma, e per farlo serve scrivere righe sintetiche nella tabella `files` viva,
perche' awi_user non puo' creare uno schema isolato (1044 su CREATE DATABASE).

Il meccanismo del difetto (l'all-or-nothing di executemany) era gia' provato da
executemany_atomicity.py. Qui si prova il LOOP REALE di reindex.py contro la tabella
vera, end to end via HTTP-free ma con il vero script.

Perche' 'files' accetta il poison
---------------------------------
`filter` e' varchar(50) e sql_mode contiene STRICT_TRANS_TABLES, quindi un header FITS
con FILTER da 80 caratteri fa fallire l'INSERT con 1406 Data too long. Verificato prima
di scrivere questo test: un record con FILTER da 80 caratteri solleva
`DataError 1406 (22001): Data too long for column 'filter'`, e un executemany con un
record buono, uno velenoso e uno buono solleva e non lascia nulla.

Perche' 4 sottodirectory da 50 file
-----------------------------------
reindex.py ha `commit_interval = 50` hardcoded e svuota batch_params solo DOPO che il
flush e' riuscito. Quindi ogni flush copre 50 record. Con 4 sottodirectory da 50 file il
risultato del worker arriva in blocchi da 50, e il record velenoso sta per costruzione
in uno di quei blocchi: esattamente UN flush fallisce e gli altri tre passano.

La forma attesa e quindi deterministica e indipendente dall'ordine con cui os.walk
restituisce le directory: 3 flush su 4 riescono, 150 dei 199 file buoni finiscono nel
database, il velenoso no.

Il caso pre-fix, che e' la prova che il test distingue:

  velenoso in un blocco NON finale -> il flush falliva e la lista NON veniva svuotata,
  quindi ogni flush successivo ripeteva il record velenoso e falliva uguale: 0 file
  committati, e la coda finale falliva facendo uscire lo script con codice 1.

  velenoso nell'ULTIMO blocco -> i primi tre flush passavano (150 committati), ma il
  flush di coda, che e' FUORI dal try, ripeteva il velenoso e propagava: uscita 1.

In entrambi i casi pre-fix il test fallisce: sul numero di file, o sul codice di
uscita. E' per questo che il test asserisce entrambi e non solo il conteggio: il
conteggio da solo sarebbe ambiguo nell'ordine sfortunato.

Il flush di coda resta senza guardia di proposito (vedi il messaggio di d4ac075): una
riga cattiva nell'ultimo blocco fallisce rumorosamente *dopo* che tutto il resto e'
gia' committato. Questo test non prova che venga riparato: prova che il resto regge.

Igiene (imparata perdendo il servizio)
--------------------------------------
Una versione precedente di questa sonda ha usato DUE connessioni sulla stessa tabella e
ha lasciato aperta una transazione: il commit e' rimasto appeso 246 secondi e il sito ha
risposto 504 fino al riavvio. E una versione successiva leggeva 0 righe dopo che il
reindex ne aveva scritte 4, perche' mysql.connector ha autocommit spento per default e
la transazione implicita teneva uno snapshot REPEATABLE READ vecchio. Da qui le regole,
non opzionali:

  * una sola connessione per tutto il lavoro del test, con autocommit=True
  * rollback e close SEMPRE nel finally, anche se lo script muore a metta
  * innodb_lock_wait_timeout basso: la contenzione deve sollevare, non mettere in attesa
  * prefisso di percorso univoco per passata, e DELETE su quel prefisso esatto
  * un controllo finale che il sito risponde, e verifica che i conteggi siano tornati
    allo stato iniziale

Uso:
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
    print(f"  {label:<56} {detail}{'OK' if cond else '<<< FALLITO'}")
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
    """Un FITS minimale ma valido, con l'intestazione che process_file_worker legge."""
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
    """4 sottodirectory da `per_dir` file. Il velenoso e' il file 0 della prima."""
    poison_rel = None
    total = 0
    for d in range(dirs):
        sub = os.path.join(root, f"d{d}")
        os.makedirs(sub, exist_ok=True)
        for i in range(per_dir):
            total += 1
            is_poison = (d == 0 and i == 0)
            # FILTER da 80 caratteri: varchar(50) con STRICT_TRANS_TABLES -> 1406.
            filt = 'P' * 80 if is_poison else 'R'
            name = f"{total:04d}.fits"
            write_fits(os.path.join(sub, name), filt)
            if is_poison:
                poison_rel = f"d{d}/{name}"
    return total, poison_rel


REINDEX_PY = os.environ.get('REINDEX_PY', '/opt/scripts/reindex.py')
LIB_DIR = os.environ.get('AWI_PROJECTS_LIB', '/opt/scripts')


def run_index(root):
    """Lancia reindex.py come processo separato, con la stessa aria e lo stesso modo di
    avvio della riga 1 di /opt/scripts, cosi' il `spawn` dei worker e' quello vero.

    REINDEX_PY e LIB_DIR si possono puntare altrove per provare la versione pre-fix senza
    scrivere sul mount /opt/scripts: quello e' grpcfuse e una scrittura dentro finisce nel
    repo (trappola #38).
    """
    cmd = [sys.executable, REINDEX_PY, root,
           '--force', '--skip-cleanup', '--workers', '2', '--no-star-metrics']
    env = dict(os.environ, AWI_PROJECTS_LIB=LIB_DIR)
    print(f"  reindex: {REINDEX_PY}")
    print(f"  lib    : {LIB_DIR}")
    p = subprocess.run(cmd, capture_output=True, text=True, env=env, timeout=900)
    return p.returncode, p.stdout + p.stderr


def site_answers():
    """Il sito risponde? Una sonda che lascia il database bloccato puo' avere tutte le
    asserzioni verdi e il sito giu', quindi va verificato a parte.

    Nessun `docker exec`: questo script gira DENTRO awi-python, dove il docker CLI non
    esiste. `http://nginx` si risolve pero' dalla rete Docker, quindi la richiesta si
    fa da qui con urllib.
    """
    try:
        req = urllib.request.Request('http://nginx/projects.php', method='GET')
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                return str(r.status)
        except urllib.error.HTTPError as e:
            # 302 seguito dal login, 401/403: sono risposte, non guasti. 5xx no: quello
            # e' il sintomo del database bloccato.
            return str(e.code)
    except Exception as e:
        return f'errore: {type(e).__name__}'


def cleanup(root_prefix):
    """DELETE per prefisso esatto di percorso. `path` e' UNIQUE, quindi il prefisso
    produce un range lock sull'indice e non una scansione."""
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
        # autocommit=True NON e' il default di mysql.connector (che e' False), ed e' la
        # ragione per cui questa versione del test leggeva 0 righe dopo che il reindex
        # ne aveva scritte 4. Con autocommit spento la transazione implicita aperta dalla
        # prima SELECT tiene uno snapshot REPEATABLE READ: le SELECT successive vedono il
        # database com'era a quel momento, non quello aggiornato dal processo sotto test.
        # Una DELETE invece le vede aggiornate, perche' le letture con blocco usano sempre
        # l'ultima versione committata: quindi la stessa query di prefisso contava 0 e
        # cancellava 4, che e' il genere di contraddizione che fa impazzire una diagnosi.
        # In autocommit ogni affermazione e' subito visibile a tutti, e la pulizia non
        # puo' dimenticare un commit.
        autocommit=True)

    # Timeout bassi di proposito. Il default di InnoDB e' 50 secondi: se per errore
    # questo script toccasse righe contese, un'attesa lunga bloccherebbe anche il sito
    # invece di far fallire la prova. Meglio un'eccezione che un servizio giu'.
    cur = CONN.cursor()
    cur.execute("SET SESSION innodb_lock_wait_timeout = 5")
    # max_statement_time, non max_execution_time: sono lo stesso concetto con nomi
    # diversi, e `max_execution_time` su MariaDB risponde
    # "1193 Unknown system variable". In secondi.
    cur.execute("SET SESSION max_statement_time = 120")
    cur.close()

    before = snapshot()
    print("=== stato iniziale ===")
    for t in TABLES:
        print(f"  {t:<20} {before[t]}")

    code_before = site_answers()
    check('il sito risponde prima di iniziare', code_before in ('302', '200'),
          f'HTTP {code_before} ')

    root = tempfile.mkdtemp(prefix='batchprobe_')
    # `fits_root` e' la radice a cui i path sono relativi, quindi va passata la radice
    # ESTERNA e i file vanno tenuti in una sottodirectory: e' quella sottodirectory che
    # compare nel campo `path`, ed e' il prefisso che le query di pulizia devono usare.
    # Passando invece la sottodirectory, `path` sarebbe stato il solo nome del file e il
    # prefisso atteso non avrebbe mai corrisposto: la pulizia avrebbe lasciato tutto
    # dentro e il conteggio finale sarebbe fallito (l'ho scoperto con una smoke da 4
    # file, che ha anche depositato 4 righe spurie da ripulire a mano).
    tree_root = os.path.join(root, 'archive')
    os.makedirs(tree_root, exist_ok=True)
    rel_prefix = 'archive'
    fits_root = root

    try:
        total, poison_rel = build_tree(tree_root, 50, 4)
        good = total - 1
        n_disk = len(glob.glob(os.path.join(tree_root, '**', '*.fits'), recursive=True))
        print("\n=== albero di prova ===")
        check('i file su disco sono quelli attesi', n_disk == total,
              f'{n_disk} file, attesi {total}')
        check('il velenoso esiste ed e\' un FILTER overlong',
              os.path.exists(os.path.join(tree_root, poison_rel)),
              poison_rel)

        print("\n=== reindex.py sul albero di prova ===")
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

        print("\n=== cosa e' finito nel database ===")
        check('il run termina senza eccezioni', code == 0, f'exit {code}')
        check('il file velenoso NON e\' stato inserito', not poison_present,
              f'presente={poison_present}')
        check('esattamente 3 flush su 4 hanno committato', n_present == 150,
              f'{n_present} righe, attese 150 (3 blocchi da 50)')
        check('tutte le righe presenti sono file buoni', n_good == n_present,
              f'{n_good} buoni su {n_present}')
        # I 50 record del blocco perso sono 49 buoni e il velenoso: gli assenti fra i
        # buoni sono quindi 49, non 50. Dire 50 sarebbe stato un falsorosso, e la
        # versione di questo check che diceva 50 e' stata quello che falliva.
        absent_good = good - n_good
        check('il blocco perso contiene 49 buoni piu\' il velenoso',
              absent_good == 49 and absent_good + 1 == 50,
              f'{absent_good} buoni assenti, +1 velenoso = {absent_good + 1} record persi')
        check('nessun buono manca fuori dal blocco perso',
              absent_good == 49,
              f'assenti {absent_good} buoni, tutti nel blocco perso')

        # La forma conta piu' del numero: i 50 assenti devono stare tutti in UNA
        # sottodirectory, e non sparire a casaccio in tutto l'albero.
        #
        # Il QUALE blocco viene perso NON e' un'invariante: `os.walk` restituisce le
        # directory nell'ordine del filesystem, che cambia da una directory temporanea
        # all'altra, quindi il velenoso puo' cadere in d0 in una passata e in d1 nella
        # successiva. Una versione di questo check asseriva `lost == [0]` ed e' passata
        # perche' nella passata di allora il caso era d0: e' un'affermazione vera solo per
        # caso, quindi va riportata come informazione e non verificata. Le invarianti
        # stabili sono i numeri: un solo flush fallisce, tre passano, e il blocco perso
        # sparisca per intero.
        per_dir_present = {
            d: sum(1 for p in indexed if p.startswith(f'archive/d{d}/'))
            for d in range(4)
        }
        intact = [d for d, n in per_dir_present.items() if n == 50]
        lost = [d for d, n in per_dir_present.items() if n == 0]
        check('un solo blocco perso, gli altri tre sono interi',
              len(intact) == 3 and len(lost) == 1,
              f'intatti={intact}, perso={lost}, per blocco={per_dir_present}')
        check('nessun blocco e\' a meta\'',
              all(n in (0, 50) for n in per_dir_present.values()),
              f'per blocco={per_dir_present}')
        print(f"  nota: blocchi persi = {lost} (il velenoso era in uno solo di questi). "
              f"Su 4 flush quello del velenoso fallisce e basta; gia' sul pre-fix se ne "
              f"perdevano due, perche' la lista non veniva svuotata.")

    finally:
        print("\n=== pulizia ===")
        try:
            n_files, n_sugg = cleanup(rel_prefix)
            print(f"  rimosse {n_files} righe files, {n_sugg} righe project_suggestions")
        except Exception as e:
            print(f"  PULIZIA FALLITA: {type(e).__name__}: {e}")
            FAILED.append('pulizia')
        shutil.rmtree(root, ignore_errors=True)

        after = snapshot()
        print("\n=== i conteggi devono essere tornati ===")
        for t in TABLES:
            check(f'  {t} invariato ({before[t]})', after[t] == before[t],
                  '' if after[t] == before[t] else f'ora {after[t]}')

        code_after = site_answers()
        check('il sito risponde anche dopo', code_after in ('302', '200'),
              f'HTTP {code_after}')

    print('\nRISULTATO: ' + ('FALLITI: ' + ', '.join(FAILED)
          if FAILED else 'un record rifiutato dal DB non ferma il resto della passata'))
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
