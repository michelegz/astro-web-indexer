#!/usr/bin/env python3
"""Check 13.25, 13.26, 13.27 — the Python side of the suggester.

  13.27 get_globals() silently turned any read error into an
         empty dictionary, then into the defaults. That is not harmless: globals_ ends up in
         _suggest_config_hash(), so a transient error changed the hash and every
         dismissal saved with the previous hash was deleted. Now only a missing
         table degrades to the defaults, everything else propagates.
  13.26 parse_ra_to_deg()/parse_dec_to_deg() did not lower(), so "12H34M56S"
         ended up in the OBJECT bucket while the PHP twin projectParseRa() converts it:
         the panels created from the web were not recognized by the watcher.
  13.25 _num_prefix() accepted a '-' sign and a leading '.', the PHP twin does not.
         A negative tolerance is degenerate: `dist > tol_rot` is always true and
         no panel is ever matched.

Usage:  docker cp tmp/suggest_parity_check.py awi-python:/tmp/
        docker exec awi-python sh -c 'cd /tmp && python3 suggest_parity_check.py'
"""

import sys

sys.path.insert(0, '/opt/scripts')
sys.path.insert(0, '/parity')

from indexer_lib import projects as P  # noqa: E402

FAILED = []


def check(label, cond, detail=''):
    suffix = 'OK' if cond else '<<< FAILED'
    print('  %-56s %s%s' % (label, detail, suffix))
    if not cond:
        FAILED.append(label)


def close(a, b, tol=1e-9):
    """Comparison that tolerates None.

    Without this the test died on the first failure instead of listing them: if one of
    the two halves returns None, an arithmetic comparison raises TypeError and the process
    dies, so nothing else is visible any more. A test that stops at the
    first defect informs less than one that lists them.
    """
    if a is None or b is None:
        return False
    try:
        return abs(float(a) - float(b)) <= tol
    except (TypeError, ValueError):
        return False


def fmt(value):
    """Numeric label that holds up with None."""
    return 'None' if value is None else ('%.6f' % value)


# ---------------------------------------------------------------- 13.26
print('\n=== 13.26: the uppercase markers must be recognized ===')

# The same value in three forms. All three must give exactly the same result.
ra_lower = P.parse_ra_to_deg('12:34:56')
ra_upper = P.parse_ra_to_deg('12H34M56S')
ra_mixed = P.parse_ra_to_deg('12h34m56s')
expected = (12 + 34 / 60.0 + 56 / 3600.0) * 15.0 % 360.0
check('RA "12H34M56S" converted', ra_upper is not None, fmt(ra_upper))
check('RA uppercase = lowercase = colons',
      close(ra_upper, ra_lower) and close(ra_mixed, ra_lower),
      'lower=%s upper=%s' % (fmt(ra_lower), fmt(ra_upper)))
check('RA expected value', close(ra_upper, expected),
      'expected %s' % fmt(expected))

dec_lower = P.parse_dec_to_deg('-12:34:56')
dec_upper = P.parse_dec_to_deg('-12D34M56S')
dec_expected = -(12 + 34 / 60.0 + 56 / 3600.0)
check('Dec "-12D34M56S" converted', dec_upper is not None, fmt(dec_upper))
check('Dec uppercase = lowercase',
      close(dec_upper, dec_lower),
      'lower=%s upper=%s' % (fmt(dec_lower), fmt(dec_upper)))
check('Dec expected value', close(dec_upper, dec_expected),
      'expected %s' % fmt(dec_expected))
check('Dec with the + sign', P.parse_dec_to_deg('+12:34:56') is not None
      and abs(P.parse_dec_to_deg('+12:34:56') - abs(dec_expected)) < 1e-9, '')

# Decimal degrees must keep working: the numeric target goes through there.
check('RA in decimal degrees', P.parse_ra_to_deg('185.75') is not None
      and abs(P.parse_ra_to_deg('185.75') - 185.75) < 1e-9, '')
check('Dec in decimal degrees', P.parse_dec_to_deg('-12.5') is not None
      and abs(P.parse_dec_to_deg('-12.5') + 12.5) < 1e-9, '')
check('None stays None', P.parse_ra_to_deg(None) is None and P.parse_dec_to_deg(None) is None, '')
check('junk stays None', P.parse_ra_to_deg('N/A') is None and P.parse_dec_to_deg('---') is None, '')

# ---------------------------------------------------------------- 13.25
print('\n=== 13.25: _num_prefix must agree with projectNumPrefix() ===')

cases = [
    # (value, default expected with the PHP regex /^\s*([0-9]+(?:\.[0-9]+)?)/)
    ('10%', 10.0),
    ('2C', 2.0),
    ('3deg', 3.0),
    ('0.2', 0.2),
    ('  5 arcmin', 5.0),
    ('-5', 3.0),        # negative sign: rejected, like in PHP
    ('-.5', 3.0),       # leading dot: rejected, like in PHP
    ('', 3.0),
    ('abc', 3.0),
    (None, 3.0),
]
for text, want in cases:
    got = P._num_prefix(text, 3.0)
    check('%-12r -> %-6s' % (text, want), close(got, want, 1e-12), 'got=%s' % got)

# A negative tolerance must not survive: with a negative tol_rot every
# `dist > tol_rot` is true and no panel is ever matched.
neg = P._num_prefix('-5', 3.0)
check('a negative tol_rot does not reach the matcher', neg > 0, 'value=%s' % fmt(neg))
check('  and the declared default stays', close(neg, 3.0, 1e-12), 'default=3.0')

# ---------------------------------------------------------------- 13.27
print('\n=== 13.27: get_globals must no longer degrade silently ===')


class FakeCursor:
    """Mimics a cursor that raises an error with a given MySQL errno."""

    def __init__(self, exc):
        self.exc = exc

    def execute(self, *a, **k):
        raise self.exc

    def fetchall(self):
        return []


class Err(Exception):
    def __init__(self, message, errno):
        super().__init__(message)
        self.errno = errno


# 1146 = ER_NO_SUCH_TABLE: a database without the project migrations. Degrading to the
# defaults is legitimate, there is nothing to read.
try:
    out = P.get_globals(FakeCursor(Err("Table 'awi_db.global_settings' doesn't exist", 1146)))
    check('missing table -> defaults, no exception',
          isinstance(out, dict) and out.get('tol_rot') == P.DEFAULT_TOLS['tol_rot'],
          'tol_rot=%s' % out.get('tol_rot'))
except Exception as exc:  # noqa: BLE001
    check('missing table -> defaults, no exception', False, 'raised: %r' % exc)

# Any other error must propagate: if it degrades, the configuration hash changes
# and the user's dismissals are deleted.
for errno, label in ((1045, 'access denied'), (2006, 'connection dropped'), (None, 'error without errno')):
    raised = False
    try:
        P.get_globals(FakeCursor(Err('boom', errno)))
    except Exception:  # noqa: BLE001
        raised = True
    check('%s -> the error propagates' % label, raised, '' if raised else 'DEGRADED SILENTLY')

# And the case where the hash would change: the defaults must not replace an error.
class WorkingCursor:
    def execute(self, *a, **k):
        return None

    def fetchall(self):
        return [{'setting_key': 'tol_rot', 'setting_value': '7deg'}]


ok = P.get_globals(WorkingCursor())
check('successful read -> value from the DB', ok.get('tol_rot') == '7deg', 'tol_rot=%s' % ok.get('tol_rot'))

# The code must no longer contain the mute fallback.
src = open('/opt/scripts/indexer_lib/projects.py', encoding='utf-8').read()
fn = src.split('def get_globals', 1)[1].split('\ndef ', 1)[0]
check('get_globals logs the error before propagating', 'logger.error' in fn, '')
check('  and distinguishes 1146', '1146' in fn, '')
check('  no mute "except Exception: out = {}"',
      'except Exception:\n        out = {}' not in fn, '')

print('\nRESULT: ' + ('FAILED: ' + ', '.join(FAILED) if FAILED else 'suggester aligned with PHP'))
