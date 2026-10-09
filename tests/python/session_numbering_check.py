# Check §8 on the Python side: the session numbering must follow the night
# even when the nights are suggested out of chronological order.
#
# It uses find_or_create_session() inside a rolled-back transaction: no real data is
# touched.
#
# Usage:  docker cp tmp/session_numbering_check.py awi-python:/tmp/
#         docker exec awi-python sh -c 'cd /tmp && python session_numbering_check.py'
#         docker exec -e AWI_PROJECTS_LIB=/tmp/oldlib awi-python sh -c 'cd /tmp && python session_numbering_check.py'

import os
import sys
import json

LIB = os.environ.get("AWI_PROJECTS_LIB", "/opt/scripts")
sys.path.insert(0, LIB)
import mysql.connector
from indexer_lib.projects import find_or_create_session

print(f"library under test: {LIB}")

conn = mysql.connector.connect(
    host=os.environ.get("DB_HOST", "mariadb"), database=os.environ.get("DB_NAME", "awi_db"),
    user=os.environ.get("DB_USER", "awi_user"), password=os.environ.get("DB_PASSWORD", ""))
cur = conn.cursor(dictionary=True)
conn.start_transaction()
failed = []


def check(label, cond, detail=""):
    print(f"  {label:<34} {detail}  {'OK' if cond else '<<< FAILED'}")
    if not cond:
        failed.append(label)


def one(sql, args=None):
    cur.execute(sql, args or ())
    return cur.fetchone()


# project -> setup -> two panels
cur.execute("INSERT INTO projects (name, notes, tolerances, assign_mode) "
            "VALUES (%s,%s,%s,'suggest')", ("numtest_" + os.urandom(3).hex(), "", json.dumps({})))
pid = cur.lastrowid
cur.execute("INSERT INTO project_setups (project_id, fingerprint, setup_no, label) "
            "VALUES (%s,%s,1,'t')", (pid, "FP|TEST|NUM|1X1|1|2|3"))
setup_id = cur.lastrowid
panels = []
for n, (ra, dec) in enumerate([(10.0, 20.0), (30.0, 40.0)], start=1):
    cur.execute("INSERT INTO project_panels (setup_id, ra, `dec`, rot_mean, fov_w, fov_h, "
                "label_object, panel_no) VALUES (%s,%s,%s,0,60,40,'Q99',%s)", (setup_id, ra, dec, n))
    panels.append(cur.lastrowid)
panel_a, panel_b = panels
print(f"project {pid} setup {setup_id} panels {panel_a},{panel_b}")

# nights out of order: a new one, an older one, one in between
nights = ['2026-03-10', '2026-03-20', '2026-02-01', '2026-03-15']
for n in nights:
    find_or_create_session(cur, panel_a, n)

cur.execute("SELECT astro_night, session_no FROM project_sessions "
            "WHERE panel_id = %s ORDER BY astro_night", (panel_a,))
rows = cur.fetchall()
# astro_night comes back as a datetime.date: normalise the key
by_night = {str(r['astro_night']): int(r['session_no']) for r in rows}
print("  night -> session_no: " + " ".join(f"{k}=N{v}" for k, v in by_night.items()))

check("session_no follows the night",
      [by_night[k] for k in sorted(by_night)] == list(range(1, len(nights) + 1)),
      "expected 1..%d in date order" % len(nights))

# the same night does not create a second session
before = one("SELECT COUNT(*) c FROM project_sessions WHERE panel_id = %s", (panel_a,))['c']
sid_again, created = find_or_create_session(cur, panel_a, '2026-03-10')
after = one("SELECT COUNT(*) c FROM project_sessions WHERE panel_id = %s", (panel_a,))['c']
check("repeated night -> reused", before == after and not created,
      f"sessions {before}->{after} created={created}")

# is the same id returned?
check("stable id", sid_again == one(
    "SELECT id FROM project_sessions WHERE panel_id = %s AND astro_night = '2026-03-10'",
    (panel_a,))['id'], f"id={sid_again}")

# second panel, same night: distinct numbers
sid_b, _ = find_or_create_session(cur, panel_b, '2026-03-10')
no_b = int(one("SELECT session_no FROM project_sessions WHERE id = %s", (sid_b,))['session_no'])
no_a = by_night['2026-03-10']
check("same night, different panel", no_b != no_a, f"A=N{no_a} B=N{no_b}")

# all the setup_no/panel_no of the project stay unique
dups = one("SELECT COUNT(*) c FROM (SELECT project_id, setup_no FROM project_setups "
           "WHERE project_id = %s GROUP BY project_id, setup_no HAVING COUNT(*)>1) x",
           (pid,))['c']
check("unique setup_no", dups == 0, f"duplicates={dups}")

conn.rollback()
print("\n(rolled-back transaction: no real data touched)")
print("RESULT: " + ("all checks passed" if not failed
                   else f"FAILED: {failed}"))
sys.exit(0 if not failed else 1)
