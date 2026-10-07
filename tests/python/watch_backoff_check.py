# Verifica §10 — il watcher non deve riprovare il reindex ogni secondo.
#
# Prima del fix last_reindex veniva aggiornato solo on success, quindi con un
# reindex fallito la condizione di cooldown era sempre vera e ogni secondo
# partiva una scansione completa dell'archivio + DB: proprio quando il DB è giù.
# Inoltre solo CalledProcessError era catturato, quindi uno script mancante o un
# timeout terminavano il processo watcher.
#
# Il test sostituisce subprocess.run con uno stub, così non serve un DB rotto e
# non parte nessun processo reale.
#
# Uso:  docker cp tmp/watch_backoff_check.py awi-python:/tmp/
#       docker exec awi-python sh -c 'cd /tmp && python watch_backoff_check.py'

import importlib.util
import os
import subprocess
import sys

WATCH = os.environ.get("AWI_WATCH_FS", "/opt/scripts/watch_fs.py")
spec = importlib.util.spec_from_file_location("watch_fs", WATCH)
watch_fs = importlib.util.module_from_spec(spec)
spec.loader.exec_module(watch_fs)

failed = []


def check(label, cond, detail=""):
    print(f"  {label:<38} {detail}  {'OK' if cond else '<<< FALLITO'}")
    if not cond:
        failed.append(label)


def make_handler():
    h = watch_fs.FitsHandler.__new__(watch_fs.FitsHandler)
    h.fits_dir = None
    h.reindex_script = None
    h.db_params = {"host": "h", "user": "u", "password": "p", "database": "d"}
    h.debug = False
    h.retention_days = 30
    h.thumb_size = 300
    h.pending_reindex = True
    h.last_reindex = 0
    h.cooldown = 10
    h.cooldown_max = 300
    h.reindex_failures = 0
    h.reindex_reason = "test"
    h.rescan_interval = 5.0
    h.last_scan = 0.0
    h.known_files = set()
    return h


# stub globale: conta i tentativi e solleva l'eccezione configurata
state = {"n": 0, "exc": subprocess.CalledProcessError(1, "reindex.py")}
_real_run = watch_fs.subprocess.run


def stub_run(cmd, check=False, **kw):
    state["n"] += 1
    if state["exc"] is not None:
        raise state["exc"]
    return 0


watch_fs.subprocess.run = stub_run

# clock finto: il cooldown si misura in secondi di tempo, non di iterazioni
clock = {"t": 1000.0}
watch_fs.time.time = lambda: clock["t"]

# ---------------------------------------------------------------------------
# 1. reindex che esce non-zero: quanti tentativi in 60 secondi?
# ---------------------------------------------------------------------------
h = make_handler()
state["n"] = 0
per_second = []
for _ in range(60):
    clock["t"] += 1.0
    h.check_and_reindex()
    per_second.append(state["n"])

attempts = state["n"]
print(f"  fallimenti consecutivi: {h.reindex_failures}")
print(f"  cooldown corrente: {h.cooldown}s")
print(f"  tentativi in 60 secondi: {attempts}")
check("non riprova ogni secondo", attempts <= 12, f"{attempts} tentativi in 60s")
check("backoff cresciuto", h.cooldown > 10, f"cooldown={h.cooldown}s")
check("cooldown sotto il tetto", h.cooldown <= h.cooldown_max,
      f"{h.cooldown} <= {h.cooldown_max}")
check("pending_reindex resta True", h.pending_reindex is True,
      "il lavoro non e' perso: verra' ritentato")

# ---------------------------------------------------------------------------
# 2. dopo il backoff, un reindex riuscito resetta tutto
# ---------------------------------------------------------------------------
state["exc"] = None
before = state["n"]
clock["t"] += h.cooldown + 1
h.check_and_reindex()
check("tentativo riuscito", state["n"] == before + 1, f"tentativi={state['n']}")
check("reset su successo",
      h.reindex_failures == 0 and h.cooldown == 10 and h.pending_reindex is False,
      f"failures={h.reindex_failures} cooldown={h.cooldown} pending={h.pending_reindex}")

# ---------------------------------------------------------------------------
# 3. eccezioni che prima non erano catturate non devono propagare
# ---------------------------------------------------------------------------
for name, exc in [("FileNotFoundError", FileNotFoundError("reindex.py")),
                  ("PermissionError", PermissionError("denied")),
                  ("TimeoutExpired", subprocess.TimeoutExpired("reindex.py", 600))]:
    h2 = make_handler()
    state["exc"] = exc
    clock["t"] += 1000
    try:
        h2.check_and_reindex()
        ok, why = True, ""
    except Exception as e:
        ok, why = False, f"propagata {type(e).__name__}"
    check(f"{name} gestita", ok, why or "catturata e registrata")

# ---------------------------------------------------------------------------
# 4. il tetto di 300s regge anche con fallimenti ripetuti
# ---------------------------------------------------------------------------
h3 = make_handler()
state["exc"] = subprocess.CalledProcessError(1, "reindex.py")
for _ in range(40):
    clock["t"] += 10000
    h3.check_and_reindex()
check("backoff limitato", h3.cooldown == h3.cooldown_max,
      f"cooldown={h3.cooldown}s dopo {h3.reindex_failures} fallimenti")

watch_fs.subprocess.run = _real_run
print("\nRISULTATO: " + ("tutti i controlli superati" if not failed
                         else f"FALLITI: {failed}"))
sys.exit(0 if not failed else 1)