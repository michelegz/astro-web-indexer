import os
import sys

# projects.py lives in /opt/scripts/indexer_lib, so the path entry is the PARENT and the
# module is imported as indexer_lib.projects. This used to insert the same two paths and
# do a bare 'import projects', which died on ModuleNotFoundError: the PHP half still
# printed its JSON and nothing compared the two, so the parity gate was not gating.
# AWI_PROJECTS_LIB names that parent directory, as in calib_suggest_check.py.
sys.path.insert(0, os.environ.get('AWI_PROJECTS_LIB', '/opt/scripts'))
sys.path.insert(0, '/parity')

from indexer_lib import projects as P  # noqa: E402  (the edited copy)

import mysql.connector  # noqa: E402

conn = mysql.connector.connect(
    host=os.environ.get('DB_HOST', 'mariadb'),
    user=os.environ.get('DB_USER', 'awi_user'),
    password=os.environ.get('DB_PASSWORD', os.environ.get('DB_PASS', 'awi_password')),
    database=os.environ.get('DB_NAME', 'awi_db'),
)
cur = conn.cursor(dictionary=True)

cur.execute("SELECT id, name, tolerances, assign_mode FROM projects ORDER BY id")
projects = cur.fetchall()
globals_ = P.get_globals(cur)
cur.execute("SELECT id, path FROM files WHERE deleted_at IS NULL AND imgtype IN ('LIGHT','DARK','FLAT','BIAS') ORDER BY id LIMIT 5")
files = cur.fetchall()

out = {'globals': {k: str(globals_.get(k)) for k in sorted(globals_)}, 'projects': [], 'files': []}

# Token parity on tricky spellings, independent of any DB value.
tricky = ['5', '5.0', ' 5.00 ', '05', '0.5', '.5', '-.5', '10%', '3deg', '2C', 'auto', '', '-', '.', '-.', '0', '0.0']
out['tricky_tokens'] = {t: P._suggest_tol_token(t) for t in tricky}

for row in projects:
    try:
        overrides = __import__('json').loads(row['tolerances']) if row['tolerances'] else {}
        if not isinstance(overrides, dict):
            overrides = {}
    except Exception:
        overrides = {}
    proj = {'id': row['id'], 'name': row['name'], 'overrides': overrides,
            'mode': row['assign_mode'] if row['assign_mode'] in ('manual', 'suggest', 'frozen', 'auto') else 'suggest'}
    entry = {'id': row['id'], 'config_hash': P._suggest_config_hash(proj, globals_)}
    if files:
        entry['match_inputs'] = P._suggest_match_inputs(cur, files[0]['id'])
    out['projects'].append(entry)

for f in files:
    out['files'].append({'id': f['id'], 'path': f['path'],
                         'match_inputs': P._suggest_match_inputs(cur, f['id'])})

sys.stdout.write(__import__('json').dumps(out, indent=2, sort_keys=True))
sys.stdout.write("\n")
