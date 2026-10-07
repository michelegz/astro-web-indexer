import json
import os
import sys

sys.path.insert(0, os.environ.get('AWI_PROJECTS_LIB', '/opt/scripts'))
sys.path.insert(0, '/parity')
from indexer_lib import projects as P  # the edited copy
import mysql.connector

PROJECT = 36
FILE = int(sys.argv[1])

PERMISSIVE = {"tol_pos_arcmin": "90000", "tol_pos_fovfrac": "1",
              "tol_rot": "360deg", "tol_fov": "100%"}

conn = mysql.connector.connect(
    host=os.environ.get('DB_HOST', 'mariadb'),
    user=os.environ.get('DB_USER', 'awi_user'),
    password=os.environ.get('DB_PASSWORD', os.environ.get('DB_PASS', 'awi_password')),
    database=os.environ.get('DB_NAME', 'awi_db'),
)
cur = conn.cursor(dictionary=True)


def suggestion_rows():
    cur.execute("SELECT id, status, config_hash FROM project_suggestions "
                "WHERE project_id = %s AND file_id = %s", (PROJECT, FILE))
    return cur.fetchall()


def unlink():
    """Make the file look unlinked to already_processed (rollback restores it)."""
    cur.execute("DELETE FROM project_files WHERE file_id = %s AND ("
                "(level='project' AND node_id=%s) OR "
                "(level='setup' AND node_id IN (SELECT id FROM project_setups WHERE project_id=%s)) OR "
                "(level='panel' AND node_id IN (SELECT pp.id FROM project_panels pp "
                " JOIN project_setups ps ON ps.id=pp.setup_id WHERE ps.project_id=%s)) OR "
                "(level IN ('session','filter') AND node_id IN (SELECT ss.id FROM project_sessions ss "
                " JOIN project_panels pp ON pp.id=ss.panel_id JOIN project_setups ps ON ps.id=pp.setup_id "
                " WHERE ps.project_id=%s)))",
                (FILE, PROJECT, PROJECT, PROJECT, PROJECT))


def seed_dismissed(config_hash):
    cur.execute("DELETE FROM project_suggestions WHERE project_id = %s AND file_id = %s",
                (PROJECT, FILE))
    cur.execute(
        "INSERT INTO project_suggestions (project_id, file_id, level, node_id, role, reason, "
        "status, config_hash, match_inputs) VALUES (%s, %s, 'setup', "
        "(SELECT id FROM project_setups WHERE project_id=%s ORDER BY setup_no LIMIT 1), "
        "'sub', 'probe', 'dismissed', %s, %s)",
        (PROJECT, FILE, PROJECT, config_hash, P._suggest_match_inputs(cur, FILE)))


def load_meta():
    cur.execute('SELECT %s FROM files WHERE id = %%s LIMIT 1' % P.FILE_COLUMNS, (FILE,))
    return cur.fetchone()


fail = []


def check(ok, msg):
    print(('  ok   ' if ok else '  FAIL ') + msg)
    if not ok:
        fail.append(msg)


conn.start_transaction()
try:
    proj = [p for p in P.get_projects(cur) if p['id'] == PROJECT][0]
    globals_ = P.get_globals(cur)
    meta = load_meta()

    print('file %d  fingerprint match: %s' % (
        FILE, 'yes' if P.find_setup(cur, PROJECT, P.build_setup_fingerprint(meta)) else 'NO'))

    # --- Control: dismissal recorded under the CURRENT config must hold.
    cur.execute("UPDATE projects SET tolerances = NULL WHERE id = %s", (PROJECT,))
    globals_ = P.get_globals(cur)
    proj = [p for p in P.get_projects(cur) if p['id'] == PROJECT][0]
    seed_dismissed(P._suggest_config_hash(proj, globals_))
    unlink()
    outcome = P.suggest_file(cur, proj, globals_, meta, FILE)
    rows = suggestion_rows()
    check(outcome == 'skipped' and len(rows) == 1 and rows[0]['status'] == 'dismissed',
          'unchanged config -> dismissal stands (outcome=%s)' % outcome)

    # --- Stale via config change: must be re-evaluated and come back pending.
    cur.execute("UPDATE projects SET tolerances = %s WHERE id = %s",
                (json.dumps(PERMISSIVE), PROJECT))
    globals_ = P.get_globals(cur)
    proj = [p for p in P.get_projects(cur) if p['id'] == PROJECT][0]
    outcome = P.suggest_file(cur, proj, globals_, meta, FILE)
    rows = suggestion_rows()
    check(not any(r['status'] == 'dismissed' for r in rows),
          'config changed -> dismissed row consumed (outcome=%s)' % outcome)
    check(any(r['status'] == 'pending' for r in rows),
          'config changed -> file re-proposed as pending (rows=%s)'
          % [(r['status']) for r in rows])
    check(len(rows) == 1, 'exactly one suggestion row after resurrection')
    if rows:
        check(rows[0]['config_hash'] == P._suggest_config_hash(proj, globals_),
              'resurrected row carries the new config hash')

    # --- Stale via header change: dismissal recorded now, header moves after.
    cur.execute("DELETE FROM project_suggestions WHERE project_id = %s AND file_id = %s",
                (PROJECT, FILE))
    unlink()
    cur.execute("UPDATE projects SET tolerances = NULL WHERE id = %s", (PROJECT,))
    globals_ = P.get_globals(cur)
    proj = [p for p in P.get_projects(cur) if p['id'] == PROJECT][0]
    seed_dismissed(P._suggest_config_hash(proj, globals_))
    cur.execute("UPDATE files SET objctrot = objctrot + 1 WHERE id = %s", (FILE,))
    outcome = P.suggest_file(cur, proj, globals_, meta, FILE)
    rows = suggestion_rows()
    check(not any(r['status'] == 'dismissed' for r in rows),
          'header changed -> dismissed row consumed (outcome=%s)' % outcome)

    # --- Legacy row (pre-migration, hashes NULL): must be reconsidered once.
    cur.execute("DELETE FROM project_suggestions WHERE project_id = %s AND file_id = %s",
                (PROJECT, FILE))
    unlink()
    cur.execute("UPDATE projects SET tolerances = %s WHERE id = %s",
                (json.dumps(PERMISSIVE), PROJECT))
    globals_ = P.get_globals(cur)
    proj = [p for p in P.get_projects(cur) if p['id'] == PROJECT][0]
    cur.execute(
        "INSERT INTO project_suggestions (project_id, file_id, level, node_id, role, reason, status) "
        "VALUES (%s, %s, 'setup', (SELECT id FROM project_setups WHERE project_id=%s ORDER BY setup_no LIMIT 1), "
        "'sub', 'legacy', 'dismissed')", (PROJECT, FILE, PROJECT))
    outcome = P.suggest_file(cur, proj, globals_, meta, FILE)
    rows = suggestion_rows()
    check(not any(r['status'] == 'dismissed' and r['config_hash'] is None for r in rows),
          'legacy row (NULL hashes) reconsidered (outcome=%s)' % outcome)
finally:
    conn.rollback()
    print('\nrolled back')

print('SCENARIO OK' if not fail else 'FAILURES: %d' % len(fail))
sys.exit(1 if fail else 0)
