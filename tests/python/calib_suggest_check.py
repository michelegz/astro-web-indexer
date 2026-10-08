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
# Stesso spirito per i FLAT (§7b): ogni flat che condivideva il setup veniva
# proposto (sessione della notte o setup). Ora il flat e' proposto solo nella
# sessione della propria notte se quella sessione ha gia' light del progetto
# (linkati o pending), altrimenti e' scartato senza fallback a setup.
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
from datetime import timedelta

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
# Il LIGHT crea sessione + pending; il FLAT e' proposto solo se condivide
# una sessione con i light (stessa notte, sessione con light linkati o
# pending), mai a livello setup e mai senza data.
print("=== ramo LIGHT + gate FLAT ===")
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

# NB: niente match su xpixsz: e' FLOAT e `5.4 <=> 5.4` e' falso in MySQL
# (5.40000009 vs 5.4). Il vero match di setup lo fa il fingerprint, che
# confronta stringhe formattate; qui basta stesso strumento/telescopio.
flat_id = one("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype='FLAT' "
              "AND instrume <=> %s AND telescop <=> %s "
              "AND date_obs IS NOT NULL ORDER BY id LIMIT 1",
              (lrow["instrume"], lrow["telescop"]))
if flat_id is None:
    print("  (nessun FLAT per questo setup: sezione saltata)")
if flat_id:
    light_sess = sug["node_id"]  # il LIGHT e' a filter/session: il nodo e' la sessione
    lfil = (lrow["filter"] or "").strip()

    def reset_flat():
        cur.execute("DELETE FROM project_suggestions WHERE project_id = %s AND file_id = %s",
                    (pidL, flat_id["id"]))

    # Stessa notte del light, stesso filtro: il flat condivide la sessione
    # con i light -> suggerito li'. (UPDATE dentro la transazione annullata.)
    cur.execute("UPDATE files SET date_obs = %s, `filter` = %s WHERE id = %s",
                (lrow["date_obs"], lrow["filter"], flat_id["id"]))
    frow = load(flat_id["id"])
    res, sug, b, a = probe(pidL, flat_id["id"], dict(frow))
    check("FLAT stessa notte+stesso filtro -> session", res == "suggested" and sug
          and sug["level"] == "session" and sug["node_id"] == light_sess,
          f"esito={res} level={sug['level'] if sug else '-'} "
          f"node={sug['node_id'] if sug else '-'} atteso={light_sess}")
    # Stesso setup e notte ma filtro diverso (e senza alias): scartato.
    reset_flat()
    cur.execute("UPDATE files SET date_obs = %s, `filter` = 'ZZZ_T01' WHERE id = %s",
                (lrow["date_obs"], flat_id["id"]))
    frow = load(flat_id["id"])
    res, sug, b, a = probe(pidL, flat_id["id"], dict(frow))
    check("FLAT filtro diverso -> scartato", res == "skipped" and sug is None,
          f"esito={res} sug={'si' if sug else 'no'}")
    if lfil != "":
        # Con l'alias ZZZ_T01 -> filtro del light, lo stesso flat passa.
        cur.execute("INSERT INTO project_filter_aliases (project_id, alias, canonical) "
                    "VALUES (%s, %s, %s)", (pidL, "zzz_t01", lfil))
        projL.pop("aliases", None)  # cache lazy: ricaricala con l'alias nuovo
        frow = load(flat_id["id"])
        res, sug, b, a = probe(pidL, flat_id["id"], dict(frow))
        check("FLAT con alias -> session", res == "suggested" and sug
              and sug["level"] == "session" and sug["node_id"] == light_sess,
              f"esito={res} level={sug['level'] if sug else '-'}")
        cur.execute("DELETE FROM project_filter_aliases WHERE project_id = %s", (pidL,))
        projL.pop("aliases", None)
        reset_flat()
    # Flat senza filtro: solo con light senza filtro (regola uniforme).
    reset_flat()
    cur.execute("UPDATE files SET date_obs = %s, `filter` = NULL WHERE id = %s",
                (lrow["date_obs"], flat_id["id"]))
    frow = load(flat_id["id"])
    res, sug, b, a = probe(pidL, flat_id["id"], dict(frow))
    if lfil == "":
        check("FLAT senza filtro + light senza filtro -> session",
              res == "suggested" and sug and sug["level"] == "session",
              f"esito={res}")
    else:
        check("FLAT senza filtro + light con filtro -> scartato",
              res == "skipped" and sug is None, f"esito={res}")
    # Altra notte senza light: scartato, senza lasciare righe. Il DELETE e'
    # sul solo flat: la pending del LIGHT deve restare (anti-orfani sotto).
    reset_flat()
    cur.execute("UPDATE files SET date_obs = %s WHERE id = %s",
                (lrow["date_obs"] - timedelta(days=20), flat_id["id"]))
    frow = load(flat_id["id"])
    res, sug, b, a = probe(pidL, flat_id["id"], dict(frow))
    check("FLAT altra notte -> scartato", res == "skipped" and sug is None,
          f"esito={res} sug={'si' if sug else 'no'}")
    # Senza data: scartato (niente fallback a setup).
    cur.execute("UPDATE files SET date_obs = NULL WHERE id = %s", (flat_id["id"],))
    frow = load(flat_id["id"])
    res, sug, b, a = probe(pidL, flat_id["id"], dict(frow))
    check("FLAT senza data -> scartato", res == "skipped" and sug is None,
          f"esito={res} sug={'si' if sug else 'no'}")

# ============================================================ DARK / BIAS
print("\n=== ramo DARK/BIAS (il fix) ===")
dark_row = one("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype='DARK' "
               "AND date_obs IS NOT NULL AND instrume IS NOT NULL AND xpixsz IS NOT NULL "
               "ORDER BY id LIMIT 1")
dark_id = dark_row["id"] if dark_row else None
pidD = None
if dark_id is None:
    print("  (nessun DARK nel dataset: sezione saltata)")
else:
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

    # Stesso NB del lookup FLAT: niente match su xpixsz (FLOAT, `<=>` inaffidabile).
    bias_id = one("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype='BIAS' "
                  "AND instrume <=> %s AND telescop <=> %s "
                  "AND date_obs IS NOT NULL ORDER BY id LIMIT 1",
                  (drow["instrume"], drow["telescop"]))
    if bias_id is None:
        print("  (nessun BIAS per questo setup: caso saltato)")
    else:
        brow = load(bias_id["id"])
        res, sug, b, a = probe(pidD, bias_id["id"], no_coords(brow))
        check("BIAS senza coord -> setup", res == "suggested" and sug
              and sug["level"] == "setup" and sug["node_id"] == setupD,
              f"esito={res} level={sug['level'] if sug else '-'}")
        check("BIAS non crea sessioni", a == b, f"sessioni {b} -> {a}")

# nessuna sessione senza link né suggerimento (in suggest mode i link non
# esistono ancora: conta anche project_suggestions pending/accepted)
pids = [pidL] + ([pidD] if pidD else [])
inh = ",".join(["%s"] * len(pids))
orphans = one(
    "SELECT COUNT(*) c FROM project_sessions ss "
    "JOIN project_panels pp ON pp.id = ss.panel_id "
    "JOIN project_setups ps ON ps.id = pp.setup_id "
    "LEFT JOIN project_files pf ON pf.node_id = ss.id AND pf.level IN ('session','filter') "
    "LEFT JOIN project_suggestions sg ON sg.node_id = ss.id AND sg.level IN ('session','filter') "
    "AND sg.status IN ('pending','accepted') "
    f"WHERE ps.project_id IN ({inh}) AND pf.file_id IS NULL AND sg.id IS NULL",
    pids)["c"]
check("nessuna sessione orfana", orphans == 0, f"orfane={orphans}")

# idempotenza
n1 = one(f"SELECT COUNT(*) c FROM project_suggestions WHERE project_id IN ({inh})",
         pids)["c"]
suggest_file(cur, projL, globals_, dict(lrow), light_id)
if pidD:
    suggest_file(cur, projD, globals_, no_coords(drow), dark_id)
n2 = one(f"SELECT COUNT(*) c FROM project_suggestions WHERE project_id IN ({inh})",
         pids)["c"]
check("idempotente", n1 == n2, f"suggerimenti {n1} -> {n2}")

conn.rollback()
print("\n(transazioni annullate: nessun dato reale toccato)")
print("RISULTATO: " + ("tutti i controlli superati" if not failed
                      else f"FALLITI: {failed}"))
sys.exit(0 if not failed else 1)