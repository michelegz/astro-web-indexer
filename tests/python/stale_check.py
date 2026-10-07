import json
import os
import sys

sys.path.insert(0, os.environ.get('AWI_PROJECTS_LIB', '/opt/scripts'))
sys.path.insert(0, '/parity')
from indexer_lib import projects as P  # the edited copy
import mysql.connector

FILE_ID = int(sys.argv[1])

conn = mysql.connector.connect(
    host=os.environ.get('DB_HOST', 'mariadb'),
    user=os.environ.get('DB_USER', 'awi_user'),
    password=os.environ.get('DB_PASSWORD', os.environ.get('DB_PASS', 'awi_password')),
    database=os.environ.get('DB_NAME', 'awi_db'),
)
cur = conn.cursor(dictionary=True)

proj = [p for p in P.get_projects(cur) if p['id'] == 36][0]
globals_ = P.get_globals(cur)

cur.execute('SELECT %s FROM files WHERE id = %%s LIMIT 1' % P.FILE_COLUMNS, (FILE_ID,))
meta = cur.fetchone()


def row_state():
    cur.execute(
        "SELECT id, status, config_hash, match_inputs FROM project_suggestions "
        "WHERE project_id = 36 AND file_id = %s", (FILE_ID,))
    return cur.fetchall()


print('before  : %s' % json.dumps([{k: (v[:12] if isinstance(v, str) and len(v) > 40 else v)
                                    for k, v in r.items()} for r in row_state()]))
outcome = P.suggest_file(cur, proj, globals_, meta, FILE_ID)
conn.commit()
after = row_state()
print('outcome : %s' % outcome)
print('after   : %s' % json.dumps([{k: (v[:12] if isinstance(v, str) and len(v) > 40 else v)
                                    for k, v in r.items()} for r in after]))
print('STILL_DISMISSED' if any(r['status'] == 'dismissed' for r in after) else 'NOT_DISMISSED')
