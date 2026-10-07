#!/usr/bin/env python3
"""Structural diff of the PHP and Python suggestion-hash output.

The PHP half of the parity gate emits JSON and the Python half emits the same shape, so
comparing the two is the gate. This says WHERE they differ. Capturing the two halves by
hand is exactly what let the gate rot in the first place: hash_parity.py still did a bare
'import projects' after the library moved to indexer_lib, so it died on
ModuleNotFoundError while the PHP half printed its JSON and nothing compared anything.

Known benign differences, allowed and asserted to still be present:

  globals/tol_pos_arcmin, globals/tol_pos_fovfrac, globals/tol_rot, globals/tol_fov,
  globals/tol_temp

Python's get_globals() returns a superset of PHP's. The hash cannot see them, because both
sides iterate the same fixed list: PROJECT_SUGGEST_TOL_KEYS in projects_functions.php and
SUGGEST_TOL_KEYS in indexer_lib/projects.py are the same four keys in the same order
(tol_pos_arcmin, tol_pos_fovfrac, tol_rot, tol_fov), with tol_temp deliberately excluded
from both. config_hash and match_inputs match on every file and project, which is the
property that matters. Anything else that differs is a real parity break and fails.

Usage:  docker cp tmp/hash_parity_diff.py awi-python:/tmp/
        docker exec awi-python sh -c 'cd /tmp && python hash_parity_diff.py php.json py.json'
"""

import json
import sys

# Flatten() prefixes every key with '/', so the root segments carry one.
BENIGN_ONLY_IN_PY = {
    '/globals/tol_pos_arcmin',
    '/globals/tol_pos_fovfrac',
    '/globals/tol_rot',
    '/globals/tol_fov',
    '/globals/tol_temp',
}


def flatten(obj, prefix=''):
    out = {}
    if isinstance(obj, dict):
        for k, v in obj.items():
            out.update(flatten(v, f'{prefix}/{k}'))
    elif isinstance(obj, list):
        for i, v in enumerate(obj):
            out.update(flatten(v, f'{prefix}[{i}]'))
    else:
        out[prefix] = obj
    return out


def main():
    if len(sys.argv) != 3:
        print('uso: hash_parity_diff.py <php.json> <py.json>')
        return 2
    with open(sys.argv[1], encoding='utf-8') as fh:
        php = flatten(json.load(fh))
    with open(sys.argv[2], encoding='utf-8') as fh:
        py = flatten(json.load(fh))

    only_php = sorted(set(php) - set(py))
    only_py = sorted(set(py) - set(php))
    differing = sorted(k for k in set(php) & set(py) if php[k] != py[k])

    unexpected_only_py = sorted(set(only_py) - BENIGN_ONLY_IN_PY)
    missing_benign = sorted(BENIGN_ONLY_IN_PY - set(only_py))

    print(f'chiavi: php={len(php)} py={len(py)}')
    print(f'solo in PHP: {len(only_php)}  solo in Python: {len(only_py)} '
          f'(note tolleranza: {len(only_py) - len(unexpected_only_py)})  valori diversi: {len(differing)}')

    for label, keys in (('SOLO PHP', only_php), ('SOLO PY (non tollerate)', unexpected_only_py)):
        for k in keys[:12]:
            print(f'  {label}  {k}')
        if len(keys) > 12:
            print(f'  {label}  ... e altre {len(keys) - 12}')

    for k in differing[:12]:
        print(f'  DIVERSO   {k}\n      php={php[k]!r}\n      py ={py[k]!r}')
    if len(differing) > 12:
        print(f'  DIVERSO   ... e altre {len(differing) - 12}')

    ok = not (only_php or unexpected_only_py or differing)
    if ok and missing_benign:
        # Not a parity break, but the allowlist is drifting from reality: either Python
        # stopped emitting one of these, or the explanation above no longer holds.
        print(f'  NOTA: non piu\' emesse {missing_benign}: rivedere la lista tollerata')
        return 1
    if ok:
        print('  (le tolleranze in piu\' di Python sono le note e non entrano nell\'hash)')
    print('PARITY OK' if ok else 'PARITY ROTTA')
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())