# Check §7 — DARK/BIAS must not go through find_panel, and must not create
# orphan sessions.
#
# Before the fix darks/bias went through find_panel (useless: the link goes to
# setup level and panel_id was not used) and find_or_create_session (which created
# a project_sessions row referenced by no link and unreachable from pruning). A dark
# from a calibration library has no RA/DEC: the find_panel gate then required a panel
# with NULL coordinates in the same OBJECT bucket, a condition almost never true, and
# the file was discarded silently.
#
# Same spirit for the FLATs (§7b): every flat that shared the setup was
# proposed (the night session or the setup). Now the flat is proposed only in
# the session of its own night if that session already has project lights
# (linked or pending), otherwise it is discarded with no fallback to setup.
#
# In the dataset darks/bias belong to a different telescope than the lights (the
# setup fingerprint includes TELESCOP), so the test uses two test projects:
# one anchored to a LIGHT, one anchored to a DARK.
#
# Usage:  docker cp tmp/calib_suggest_check.py awi-python:/opt/scripts/
#         docker exec awi-python python /opt/scripts/calib_suggest_check.py
#
# Everything inside rolled-back transactions: no real data is touched.

import os
import sys
import json
from datetime import timedelta

# The library to use. For the regression run it points to a separate
# copy containing the PRE-fix version: /opt/scripts/indexer_lib is a bind
# mount of the working directory, so overwriting it from inside the container
# modifies the local file too.
LIB = os.environ.get("AWI_PROJECTS_LIB", "/opt/scripts")
sys.path.insert(0, LIB)
import mysql.connector
from indexer_lib.projects import build_setup_fingerprint, suggest_file, get_globals, position_of

print(f"library under test: {LIB}")

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
    print(f"  {label:<30} {detail}  {'OK' if cond else '<<< FAILED'}")
    if not cond:
        failed.append(label)


def no_coords(row):
    """Header as it arrives from a calibration library: no RA/DEC."""
    m = dict(row)
    m["objctra"] = None
    m["objctdec"] = None
    return m


def make_project(name):
    cur.execute("INSERT INTO projects (name, notes, tolerances, assign_mode) "
                "VALUES (%s, %s, %s, 'suggest')",
                (f"calibtest_{name}_{os.urandom(3).hex()}", "check 7", json.dumps({})))
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


def synth_frame(kind, like, tag):
    """A synthetic calibration frame, created inside the rolled-back transaction.

    Used when the archive holds no frame of that kind, so the branch is exercised
    instead of skipped. `like` supplies the setup identity so the synthetic frame
    matches an existing setup and `build_setup_fingerprint` has something to
    compare. Nothing here survives the rollback.

    `path` is UNIQUE, so the tag must be too.
    """
    cur.execute(
        "INSERT INTO files (path, name, imgtype, `object`, instrume, telescop, cameraid, "
        "`filter`, exptime, ccd_temp, xbinning, ybinning, gain, `offset`, xpixsz, "
        "date_obs, objctra, objctdec, fov_w, fov_h, objctrot, rotator_angle, "
        "file_hash, mtime, file_size) "
        "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
        (f"{tag}/{kind}/synth.fits", "synth.fits", kind,
         like.get("object") or "SYNTHTARGET",
         like.get("instrume"), like.get("telescop"), like.get("cameraid"),
         like.get("filter"), like.get("exptime"), like.get("ccd_temp"),
         like.get("xbinning"), like.get("ybinning"), like.get("gain"), like.get("offset"),
         like.get("xpixsz"), like.get("date_obs"),
         like.get("objctra"), like.get("objctdec"),
         like.get("fov_w"), like.get("fov_h"), like.get("objctrot"), like.get("rotator_angle"),
         os.urandom(8).hex(), 1750000000, 1024))
    return {"id": cur.lastrowid}


globals_ = get_globals(cur)

# ============================================================ LIGHT / FLAT
# The LIGHT creates session + pending; the FLAT is proposed only if it shares
# a session with the lights (same night, session with linked or
# pending lights), never at setup level and never without a date.
print("=== LIGHT + FLAT gate branch ===")
light_id = one("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype='LIGHT' "
               "AND date_obs IS NOT NULL AND objctra IS NOT NULL AND objctdec IS NOT NULL "
               "AND instrume IS NOT NULL AND xpixsz IS NOT NULL ORDER BY id LIMIT 1")["id"]
lrow = load(light_id)

pidL = make_project("light")
setupL = add_setup(pidL, lrow, lrow["instrume"])
panelL, raL, _ = add_panel(setupL, lrow)
projL = {"id": pidL, "name": "light", "overrides": {}, "mode": "suggest"}
print(f"  project {pidL}  setup {setupL}  panel {panelL}  ra={raL}")


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
      f"outcome={res} level={sug['level'] if sug else '-'} sessions {b}->{a}")

# NB: no match on xpixsz: it is FLOAT and `5.4 <=> 5.4` is false in MySQL
# (5.40000009 vs 5.4). The real setup match is done by the fingerprint, which
# compares formatted strings; here the same instrument/telescope is enough.
flat_id = one("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype='FLAT' "
              "AND instrume <=> %s AND telescop <=> %s "
              "AND date_obs IS NOT NULL ORDER BY id LIMIT 1",
              (lrow["instrume"], lrow["telescop"]))
if flat_id is None:
    print("  (no FLAT for this setup: section skipped)")
if flat_id:
    light_sess = sug["node_id"]  # the LIGHT is at filter/session: the node is the session
    lfil = (lrow["filter"] or "").strip()

    def reset_flat():
        cur.execute("DELETE FROM project_suggestions WHERE project_id = %s AND file_id = %s",
                    (pidL, flat_id["id"]))

    # Same night as the light, same filter: the flat shares the session
    # with the lights -> suggested there. (UPDATE inside the rolled-back transaction.)
    cur.execute("UPDATE files SET date_obs = %s, `filter` = %s WHERE id = %s",
                (lrow["date_obs"], lrow["filter"], flat_id["id"]))
    frow = load(flat_id["id"])
    res, sug, b, a = probe(pidL, flat_id["id"], dict(frow))
    check("FLAT same night+same filter -> session", res == "suggested" and sug
          and sug["level"] == "session" and sug["node_id"] == light_sess,
          f"outcome={res} level={sug['level'] if sug else '-'} "
          f"node={sug['node_id'] if sug else '-'} expected={light_sess}")
    # Same setup and night but a different filter (and no alias): discarded.
    reset_flat()
    cur.execute("UPDATE files SET date_obs = %s, `filter` = 'ZZZ_T01' WHERE id = %s",
                (lrow["date_obs"], flat_id["id"]))
    frow = load(flat_id["id"])
    res, sug, b, a = probe(pidL, flat_id["id"], dict(frow))
    check("FLAT different filter -> discarded", res == "skipped" and sug is None,
          f"outcome={res} sug={'yes' if sug else 'no'}")
    if lfil != "":
        # With the alias ZZZ_T01 -> the light's filter, the same flat passes.
        cur.execute("INSERT INTO project_filter_aliases (project_id, alias, canonical) "
                    "VALUES (%s, %s, %s)", (pidL, "zzz_t01", lfil))
        projL.pop("aliases", None)  # lazy cache: recompute with the new alias
        frow = load(flat_id["id"])
        res, sug, b, a = probe(pidL, flat_id["id"], dict(frow))
        check("FLAT with alias -> session", res == "suggested" and sug
              and sug["level"] == "session" and sug["node_id"] == light_sess,
              f"outcome={res} level={sug['level'] if sug else '-'}")
        cur.execute("DELETE FROM project_filter_aliases WHERE project_id = %s", (pidL,))
        projL.pop("aliases", None)
        reset_flat()
    # Flat without filter: only with lights without a filter (uniform rule).
    reset_flat()
    cur.execute("UPDATE files SET date_obs = %s, `filter` = NULL WHERE id = %s",
                (lrow["date_obs"], flat_id["id"]))
    frow = load(flat_id["id"])
    res, sug, b, a = probe(pidL, flat_id["id"], dict(frow))
    if lfil == "":
        check("FLAT without filter + light without filter -> session",
              res == "suggested" and sug and sug["level"] == "session",
              f"outcome={res}")
    else:
        check("FLAT without filter + light with filter -> discarded",
              res == "skipped" and sug is None, f"outcome={res}")
    # Another night without lights: discarded, leaving no rows. The DELETE is
    # on the flat only: the LIGHT pending must stay (orphans check below).
    reset_flat()
    cur.execute("UPDATE files SET date_obs = %s WHERE id = %s",
                (lrow["date_obs"] - timedelta(days=20), flat_id["id"]))
    frow = load(flat_id["id"])
    res, sug, b, a = probe(pidL, flat_id["id"], dict(frow))
    check("FLAT another night -> discarded", res == "skipped" and sug is None,
          f"outcome={res} sug={'yes' if sug else 'no'}")
    # Without a date: discarded (no fallback to setup).
    cur.execute("UPDATE files SET date_obs = NULL WHERE id = %s", (flat_id["id"],))
    frow = load(flat_id["id"])
    res, sug, b, a = probe(pidL, flat_id["id"], dict(frow))
    check("FLAT without a date -> discarded", res == "skipped" and sug is None,
          f"outcome={res} sug={'yes' if sug else 'no'}")

# ============================================================ DARK / BIAS
print("\n=== DARK/BIAS branch (the fix) ===")
dark_row = one("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype='DARK' "
               "AND date_obs IS NOT NULL AND instrume IS NOT NULL AND xpixsz IS NOT NULL "
               "ORDER BY id LIMIT 1")
dark_id = dark_row["id"] if dark_row else None
pidD = None
if dark_id is None:
    # This archive has FLAT and LIGHT frames but no DARK, so the branch that the fix is
    # about would be skipped entirely. A synthetic DARK is created inside the transaction
    # instead, inheriting the LIGHT's setup identity and sky-coordinate fields so that
    # build_setup_fingerprint() and add_panel() see a realistic row. The panel position
    # still resolves to None here, exactly as the real-LIGHT panel above does: HEADERS
    # carries no ra/dec, so position_of() falls back to OBJCTRA/OBJCTDEC. The assertions
    # below do not depend on the position — they check the link level and the absence of
    # new sessions.
    dark_row = synth_frame("DARK", lrow, f"synthdark_{os.urandom(3).hex()}")
    dark_id = dark_row["id"]
    print(f"  (no DARK in the dataset: synthetic frame id={dark_id} created in-transaction)")
else:
    print("  (using a real DARK from the dataset)")
drow = load(dark_id)

pidD = make_project("dark")
setupD = add_setup(pidD, drow, drow["instrume"])
# panel at real coordinates, taken from a panel-like of the same object: this way
# find_panel has a panel to attach to and the test distinguishes "missing coordinates"
# from "no panel".
panelD, raD, _ = add_panel(setupD, drow)
projD = {"id": pidD, "name": "dark", "overrides": {}, "mode": "suggest"}
proj_of[pidD] = projD
print(f"  project {pidD}  setup {setupD}  panel {panelD}  ra={raD}")

# The dataset has only one DARK for this tuple, so the same file is
# reused by clearing the suggestion row between one probe and the next (all inside the
# transaction, which will be rolled back).
def reset_suggestions(pid):
    cur.execute("DELETE FROM project_suggestions WHERE project_id = %s", (pid,))

# DARK without coordinates: this is the case the gate discarded
reset_suggestions(pidD)
res2, sug2, b2, a2 = probe(pidD, dark_id, no_coords(drow))
check("DARK without coords -> setup", res2 == "suggested" and sug2
      and sug2["level"] == "setup" and sug2["node_id"] == setupD,
      f"outcome={res2} level={sug2['level'] if sug2 else '-'}")
check("DARK does not create sessions", a2 == b2, f"sessions {b2} -> {a2}")

# DARK with coordinates: this must link to setup too, without creating sessions
reset_suggestions(pidD)
res, sug, b, a = probe(pidD, dark_id, dict(drow))
check("DARK with coords -> setup", res == "suggested" and sug and sug["level"] == "setup"
      and sug["node_id"] == setupD, f"outcome={res} level={sug['level'] if sug else '-'}")
check("DARK(coords) does not create sessions", a == b, f"sessions {b} -> {a}")

# Same NB as the FLAT lookup: no match on xpixsz (FLOAT, `<=>` unreliable).
bias_id = one("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype='BIAS' "
              "AND instrume <=> %s AND telescop <=> %s "
              "AND date_obs IS NOT NULL ORDER BY id LIMIT 1",
              (drow["instrume"], drow["telescop"]))
if bias_id is None:
    # Same reason as the DARK: a synthetic BIAS matching the dark's setup, so this
    # branch is exercised on an archive that has none.
    bias_id = synth_frame("BIAS", drow, f"synthbias_{os.urandom(3).hex()}")
    print(f"  (no BIAS for this setup: synthetic frame id={bias_id['id']} created in-transaction)")
brow = load(bias_id["id"])
res, sug, b, a = probe(pidD, bias_id["id"], no_coords(brow))
check("BIAS without coords -> setup", res == "suggested" and sug
      and sug["level"] == "setup" and sug["node_id"] == setupD,
      f"outcome={res} level={sug['level'] if sug else '-'}")
check("BIAS does not create sessions", a == b, f"sessions {b} -> {a}")

# no session without link nor suggestion (in suggest mode the links do not
# exist yet: project_suggestions pending/accepted count too)
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
check("no orphan session", orphans == 0, f"orphans={orphans}")

# idempotency
n1 = one(f"SELECT COUNT(*) c FROM project_suggestions WHERE project_id IN ({inh})",
         pids)["c"]
suggest_file(cur, projL, globals_, dict(lrow), light_id)
if pidD:
    suggest_file(cur, projD, globals_, no_coords(drow), dark_id)
n2 = one(f"SELECT COUNT(*) c FROM project_suggestions WHERE project_id IN ({inh})",
         pids)["c"]
check("idempotent", n1 == n2, f"suggestions {n1} -> {n2}")

conn.rollback()
print("\n(rolled-back transactions: no real data touched)")
print("RESULT: " + ("all checks passed" if not failed
                   else f"FAILED: {failed}"))
sys.exit(0 if not failed else 1)