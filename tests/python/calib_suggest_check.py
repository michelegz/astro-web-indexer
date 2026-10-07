# Verifica §7 — DARK/BIAS non devono passare da find_panel, e non devono creare
# sessioni orfane.
#
# Prima del fix i darks/bias attraversavano find_panel (inutile: il link va a
# livello setup e panel_id non era usato) e find_or_create_session (che creava
# una riga project_sessions non referenziata da nessun link e non raggiungibile
# dalla potatura). Un dark da libreria di calibrazione non ha RA/DEC: il gate
# find_panel allora richiedeva un panel a coordinate NULL con lo stesso bucket
# OBJECT, condizione quasi mai vera, e il file veniva scartato in silenzio.
#
# Nel dataset i darks/bias appartengono a un telescopio diverso dai light (il
# fingerprint del setup include TELESCOP), quindi il test usa due progetti di
# prova: uno ancorato a un LIGHT, uno ancorato a un DARK.
#
# Uso:  docker cp tmp/calib_suggest_check.py awi-python:/opt/scripts/
#       docker exec awi-python python /opt/scripts/calib_suggest_check.py
#
# Tutto dentro transazioni annullate: nessun dato reale viene toccato.

import os
import sys
import json

# La libreria da usare. Per la prova di regressione si punta a una copia
# separata che contiene la versione PRE-fix: /opt/scripts/indexer_lib e' un bind
# mount della directory di lavoro, quindi sovrascriverlo da dentro il container
# modifica anche il file locale.
LIB = os.environ.get("AWI_PROJECTS_LIB", "/opt/scripts")
sys.path.insert(0, LIB)
import mysql.connector
from indexer_lib.projects import build_setup_fingerprint, suggest_file, get_globals, position_of

print(f"libreria sotto test: {LIB}")

conn = mysql.connector.connect(
    host=os.environ.get("DB_HOST", "mariadb"),
    database=os.environ.get("DB_NAME", "awi_db"),
    user=os.environ.get("DB_USER", "awi_user"),
    password=os.environ.get("DB_PASSWORD", ""))
cur = conn.cursor(dictionary=True)
conn.start_transaction()
failed = []

HEADERS = ("instrume, telescop, cameraid, xbinning, ybinning, gain, `offset`, xpixsz, "
           "imgtype, `filter`, exptime, date_obs, objctra, objctdec, `object`, "
           "fov_w, fov_h, objctrot, rotator_angle")


def one(sql, args=None):
    cur.execute(sql, args or ())
    return cur.fetchone()


def load(file_id):
    return one(f"SELECT {HEADERS} FROM files WHERE id = %s", (file_id,))


def check(label, cond, detail=""):
    print(f"  {label:<30} {detail}  {'OK' if cond else '<<< FALLITO'}")
    if not cond:
        failed.append(label)


def no_coords(row):
    """Header come arriva da una libreria di calibrazione: niente RA/DEC."""
    m = dict(row)
    m["objctra"] = None
    m["objctdec"] = None
    return m


def make_project(name):
    cur.execute("INSERT INTO projects (name, notes, tolerances, assign_mode) "
                "VALUES (%s, %s, %s, 'suggest')",
                (f"calibtest_{name}_{os.urandom(3).hex()}", "verifica 7", json.dumps({})))
    return cur.lastrowid


def add_setup(pid, row, label):
    cur.execute("INSERT INTO project_setups (project_id, fingerprint, setup_no, label) "
                "VALUES (%s, %s, 1, %s)", (pid, build_setup_fingerprint(row), label))
    return cur.lastrowid


def add_panel(setup_id, row):
    ra, dec, _ = position_of(row)
    cur.execute("INSERT INTO project_panels (setup_id, ra, `dec`, rot_mean, fov_w, fov_h, "
                "label_object, panel_no) VALUES (%s, %s, %s, %s, %s, %s, %s, 1)",
                (setup_id, ra, dec, row["objctrot"], row["fov_w"], row["fov_h"], row["object"]))
    return cur.lastrowid, ra, dec


globals_ = get_globals(cur)

# ============================================================ LIGHT / FLAT
print("=== ramo LIGHT/FLAT (nessun cambiamento atteso) ===")
light_id = one("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype='LIGHT' "
               "AND date_obs IS NOT NULL AND objctra IS NOT NULL AND objctdec IS NOT NULL "
               "AND instrume IS NOT NULL AND xpixsz IS NOT NULL ORDER BY id LIMIT 1")["id"]
lrow = load(light_id)

pidL = make_project("light")
setupL = add_setup(pidL, lrow, lrow["instrume"])
panelL, raL, _ = add_panel(setupL, lrow)
projL = {"id": pidL, "name": "light", "overrides": {}, "mode": "suggest"}
print(f"  progetto {pidL}  setup {setupL}  panel {panelL}  ra={raL}")


def probe(pid, file_id, meta):
    sess_before = one("SELECT COUNT(*) c FROM project_sessions WHERE panel_id IN "
                      "(SELECT id FROM project_panels WHERE setup_id = "
                      "(SELECT id FROM project_setups WHERE project_id = %s))", (pid,))["c"]
    res = suggest_file(cur, proj_of[pid], globals_, meta, file_id)
    sug = one("SELECT level, node_id FROM project_suggestions "
              "WHERE project_id = %s AND file_id = %s", (pid, file_id))
    sess_after = one("SELECT COUNT(*) c FROM project_sessions WHERE panel_id IN "
                     "(SELECT id FROM project_panels WHERE setup_id = "
                     "(SELECT id FROM project_setups WHERE project_id = %s))", (pid,))["c"]
    return res, sug, sess_before, sess_after


proj_of = {pidL: projL}

res, sug, b, a = probe(pidL, light_id, dict(lrow))
check("LIGHT -> filter/session", res == "suggested" and sug and sug["level"] == "filter"
      and b == 0 and a == 1,
      f"esito={res} level={sug['level'] if sug else '-'} sessioni {b}->{a}")

flat_id = one("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype='FLAT' "
              "AND instrume <=> %s AND telescop <=> %s AND xpixsz <=> %s "
              "AND date_obs IS NOT NULL ORDER BY id LIMIT 1",
              (lrow["instrume"], lrow["telescop"], lrow["xpixsz"]))
if flat_id:
    frow = load(flat_id["id"])
    res, sug, b, a = probe(pidL, flat_id["id"], dict(frow))
    check("FLAT invariato", res == "suggested" and sug and sug["level"] in ("session", "setup"),
          f"esito={res} level={sug['level'] if sug else '-'} sessioni {b}->{a}")

# ============================================================ DARK / BIAS
print("\n=== ramo DARK/BIAS (il fix) ===")
dark_id = one("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype='DARK' "
              "AND date_obs IS NOT NULL AND instrume IS NOT NULL AND xpixsz IS NOT NULL "
              "ORDER BY id LIMIT 1")["id"]
drow = load(dark_id)

pidD = make_project("dark")
setupD = add_setup(pidD, drow, drow["instrume"])
# panel a coordinate reali, prese da un panel-like dello stesso oggetto: cosi'
# find_panel ha un panel da agganciare e il test distingue "coordinate mancanti"
# da "panel assente".
panelD, raD, _ = add_panel(setupD, drow)
projD = {"id": pidD, "name": "dark", "overrides": {}, "mode": "suggest"}
proj_of[pidD] = projD
print(f"  progetto {pidD}  setup {setupD}  panel {panelD}  ra={raD}")

# Il dataset ha un solo DARK per questa tupla, quindi si riusa lo stesso file
# azzerando la riga di suggestion fra una sonda e l'altra (tutto dentro la
# transazione, che verrà annullata).
def reset_suggestions(pid):
    cur.execute("DELETE FROM project_suggestions WHERE project_id = %s", (pid,))


# DARK senza coordinate: questo e' il caso che il gate scartava
reset_suggestions(pidD)
res2, sug2, b2, a2 = probe(pidD, dark_id, no_coords(drow))
check("DARK senza coord -> setup", res2 == "suggested" and sug2
      and sug2["level"] == "setup" and sug2["node_id"] == setupD,
      f"esito={res2} level={sug2['level'] if sug2 else '-'}")
check("DARK non crea sessioni", a2 == b2, f"sessioni {b2} -> {a2}")

# DARK con coordinate: anche questo deve linkare a setup, senza creare sessioni
reset_suggestions(pidD)
res, sug, b, a = probe(pidD, dark_id, dict(drow))
check("DARK con coord -> setup", res == "suggested" and sug and sug["level"] == "setup"
      and sug["node_id"] == setupD, f"esito={res} level={sug['level'] if sug else '-'}")
check("DARK(coord) non crea sessioni", a == b, f"sessioni {b} -> {a}")

bias_id = one("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype='BIAS' "
              "AND instrume <=> %s AND telescop <=> %s AND xpixsz <=> %s "
              "AND date_obs IS NOT NULL ORDER BY id LIMIT 1",
              (drow["instrume"], drow["telescop"], drow["xpixsz"]))
if bias_id:
    brow = load(bias_id["id"])
    res, sug, b, a = probe(pidD, bias_id["id"], no_coords(brow))
    check("BIAS senza coord -> setup", res == "suggested" and sug
          and sug["level"] == "setup" and sug["node_id"] == setupD,
          f"esito={res} level={sug['level'] if sug else '-'}")
    check("BIAS non crea sessioni", a == b, f"sessioni {b} -> {a}")

# nessuna sessione senza link né suggerimento (in suggest mode i link non
# esistono ancora: conta anche project_suggestions pending/accepted)
orphans = one(
    "SELECT COUNT(*) c FROM project_sessions ss "
    "JOIN project_panels pp ON pp.id = ss.panel_id "
    "JOIN project_setups ps ON ps.id = pp.setup_id "
    "LEFT JOIN project_files pf ON pf.node_id = ss.id AND pf.level IN ('session','filter') "
    "LEFT JOIN project_suggestions sg ON sg.node_id = ss.id AND sg.level IN ('session','filter') "
    "AND sg.status IN ('pending','accepted') "
    "WHERE ps.project_id IN (%s, %s) AND pf.file_id IS NULL AND sg.id IS NULL",
    (pidL, pidD))["c"]
check("nessuna sessione orfana", orphans == 0, f"orfane={orphans}")

# idempotenza
n1 = one("SELECT COUNT(*) c FROM project_suggestions WHERE project_id IN (%s,%s)",
         (pidL, pidD))["c"]
suggest_file(cur, projL, globals_, dict(lrow), light_id)
suggest_file(cur, projD, globals_, no_coords(drow), dark_id)
n2 = one("SELECT COUNT(*) c FROM project_suggestions WHERE project_id IN (%s,%s)",
         (pidL, pidD))["c"]
check("idempotente", n1 == n2, f"suggerimenti {n1} -> {n2}")

conn.rollback()
print("\n(transazioni annullate: nessun dato reale toccato)")
print("RISULTATO: " + ("tutti i controlli superati" if not failed
                      else f"FALLITI: {failed}"))
sys.exit(0 if not failed else 1)