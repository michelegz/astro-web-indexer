#!/usr/bin/env python3
"""Verifica 13.25, 13.26, 13.27 — la parte Python del suggeritore.

  13.27 get_globals() trasformava in silenzio qualsiasi errore di lettura in un
         dizionario vuoto, poi nei default. Non e' innocuo: globals_ finisce in
         _suggest_config_hash(), quindi un errore transitorio cambiava l'hash e ogni
         dismiss salvato con l'hash precedente veniva cancellato. Ora solo una tabella
         mancante degrada ai default, tutto il resto si propaga.
  13.26 parse_ra_to_deg()/parse_dec_to_deg() non facevano lower(), quindi "12H34M56S"
         finiva nel bucket OBJECT mentre il gemello PHP projectParseRa() lo converte:
         i pannelli creati dalla web non venivano riconosciuti dal watcher.
  13.25 _num_prefix() accettava il segno '-' e un '.' iniziale, il gemello PHP no.
         Una tolleranza negativa e' degenerata: `dist > tol_rot` e' sempre vero e
         nessun pannello viene mai abbinato.

Uso:  docker cp tmp/suggest_parity_check.py awi-python:/tmp/
      docker exec awi-python sh -c 'cd /tmp && python3 suggest_parity_check.py'
"""

import sys

sys.path.insert(0, '/opt/scripts')
sys.path.insert(0, '/parity')

from indexer_lib import projects as P  # noqa: E402

FAILED = []


def check(label, cond, detail=''):
    suffix = 'OK' if cond else '<<< FALLITO'
    print('  %-56s %s%s' % (label, detail, suffix))
    if not cond:
        FAILED.append(label)


def close(a, b, tol=1e-9):
    """Confronto che tollera i None.

    Senza questo il test moriva sul primo fallimento invece di elencarlo: se una delle
    due meta' restituisce None, un confronto aritmetico solleva TypeError e il processo
    muore, quindi di tutto il resto non si vede piu' nulla. Un test che si ferma al
    primo difetto informa meno di uno che li elenca.
    """
    if a is None or b is None:
        return False
    try:
        return abs(float(a) - float(b)) <= tol
    except (TypeError, ValueError):
        return False


def fmt(value):
    """Etichetta numerica che regge None."""
    return 'None' if value is None else ('%.6f' % value)


# ---------------------------------------------------------------- 13.26
print('\n=== 13.26: i marcatori maiuscoli devono essere riconosciuti ===')

# Lo stesso valore in tre forme. Le prime due devono dare lo stesso identico risultato.
ra_lower = P.parse_ra_to_deg('12:34:56')
ra_upper = P.parse_ra_to_deg('12H34M56S')
ra_mixed = P.parse_ra_to_deg('12h34m56s')
expected = (12 + 34 / 60.0 + 56 / 3600.0) * 15.0 % 360.0
check('RA "12H34M56S" convertita', ra_upper is not None, fmt(ra_upper))
check('RA maiuscola = minuscola = colons',
      close(ra_upper, ra_lower) and close(ra_mixed, ra_lower),
      'lower=%s upper=%s' % (fmt(ra_lower), fmt(ra_upper)))
check('RA valore atteso', close(ra_upper, expected),
      'atteso %s' % fmt(expected))

dec_lower = P.parse_dec_to_deg('-12:34:56')
dec_upper = P.parse_dec_to_deg('-12D34M56S')
dec_expected = -(12 + 34 / 60.0 + 56 / 3600.0)
check('Dec "-12D34M56S" convertita', dec_upper is not None, fmt(dec_upper))
check('Dec maiuscola = minuscola',
      close(dec_upper, dec_lower),
      'lower=%s upper=%s' % (fmt(dec_lower), fmt(dec_upper)))
check('Dec valore atteso', close(dec_upper, dec_expected),
      'atteso %s' % fmt(dec_expected))
check('Dec col segno +', P.parse_dec_to_deg('+12:34:56') is not None
      and abs(P.parse_dec_to_deg('+12:34:56') - abs(dec_expected)) < 1e-9, '')

# I gradi decimali devono continuare a funzionare: ci passa l'obiettivo numerico.
check('RA in gradi decimali', P.parse_ra_to_deg('185.75') is not None
      and abs(P.parse_ra_to_deg('185.75') - 185.75) < 1e-9, '')
check('Dec in gradi decimali', P.parse_dec_to_deg('-12.5') is not None
      and abs(P.parse_dec_to_deg('-12.5') + 12.5) < 1e-9, '')
check('None resta None', P.parse_ra_to_deg(None) is None and P.parse_dec_to_deg(None) is None, '')
check('spazzatura resta None', P.parse_ra_to_deg('N/A') is None and P.parse_dec_to_deg('---') is None, '')

# ---------------------------------------------------------------- 13.25
print('\n=== 13.25: _num_prefix deve concordare con projectNumPrefix() ===')

cases = [
    # (valore, default atteso con la regex PHP /^\s*([0-9]+(?:\.[0-9]+)?)/)
    ('10%', 10.0),
    ('2C', 2.0),
    ('3deg', 3.0),
    ('0.2', 0.2),
    ('  5 arcmin', 5.0),
    ('-5', 3.0),        # segno negativo: rifiutato, come in PHP
    ('-.5', 3.0),       # punto iniziale: rifiutato, come in PHP
    ('', 3.0),
    ('abc', 3.0),
    (None, 3.0),
]
for text, want in cases:
    got = P._num_prefix(text, 3.0)
    check('%-12r -> %-6s' % (text, want), close(got, want, 1e-12), 'got=%s' % got)

# Una tolleranza negativa non deve sopravvivere: con tol_rot negativo ogni
# `dist > tol_rot` e' vero e nessun pannello viene abbinato.
neg = P._num_prefix('-5', 3.0)
check('tol_rot negativo non arriva al matcher', neg > 0, 'valore=%s' % fmt(neg))
check('  e resta il default dichiarato', close(neg, 3.0, 1e-12), 'default=3.0')

# ---------------------------------------------------------------- 13.27
print('\n=== 13.27: get_globals non deve più degradare in silenzio ===')


class FakeCursor:
    """Mima un cursor che solleva un errore con un dato errno MySQL."""

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


# 1146 = ER_NO_SUCH_TABLE: un database senza le migration dei progetti. Degenerare ai
# default e' legittimo, non c'e' niente da leggere.
try:
    out = P.get_globals(FakeCursor(Err("Table 'awi_db.global_settings' doesn't exist", 1146)))
    check('tabella assente -> default, senza eccezione',
          isinstance(out, dict) and out.get('tol_rot') == P.DEFAULT_TOLS['tol_rot'],
          'tol_rot=%s' % out.get('tol_rot'))
except Exception as exc:  # noqa: BLE001
    check('tabella assente -> default, senza eccezione', False, 'sollevata: %r' % exc)

# Qualsiasi altro errore deve propagare: se degrada, l'hash di configurazione cambia
# e i dismiss dell'utente vengono cancellati.
for errno, label in ((1045, 'accesso negato'), (2006, 'connessione persa'), (None, 'errore senza errno')):
    raised = False
    try:
        P.get_globals(FakeCursor(Err('boom', errno)))
    except Exception:  # noqa: BLE001
        raised = True
    check('%s -> l\'errore si propaga' % label, raised, '' if raised else 'DEGRADATO IN SILENZIO')

# E il caso in cui l'hash cambierebbe: i default non devono sostituire un errore.
class WorkingCursor:
    def execute(self, *a, **k):
        return None

    def fetchall(self):
        return [{'setting_key': 'tol_rot', 'setting_value': '7deg'}]


ok = P.get_globals(WorkingCursor())
check('lettura riuscita -> valore dal DB', ok.get('tol_rot') == '7deg', 'tol_rot=%s' % ok.get('tol_rot'))

# Il codice non deve piu' contenere il fallback muto.
src = open('/opt/scripts/indexer_lib/projects.py', encoding='utf-8').read()
fn = src.split('def get_globals', 1)[1].split('\ndef ', 1)[0]
check('get_globals registra l\'errore prima di propagare', 'logger.error' in fn, '')
check('  e distingue 1146', '1146' in fn, '')
check('  niente "except Exception: out = {}" muto',
      'except Exception:\n        out = {}' not in fn, '')

print('\nRISULTATO: ' + ('FALLITI: ' + ', '.join(FAILED) if FAILED else 'suggeritore allineato al PHP'))
