# Check 13.6 — the migration's SQL normalization must agree with
# Python's normalize_imgtype(), term by term and by test order.
#
# This is not a vague symmetry: the migration rewrites Python's rules in SQL, and a
# disagreement would make the two inputs diverge (future ingest vs existing data).
#
# Usage:  docker cp tmp/imgtype_parity_check.py awi-python:/tmp/
#         docker exec awi-python sh -c 'cd /tmp && python imgtype_parity_check.py'

import os
import sys

sys.path.insert(0, os.environ.get("AWI_PROJECTS_LIB", "/opt/scripts"))
import mysql.connector
from indexer_lib.projects import normalize_imgtype

failed = []


def check(label, cond, detail=""):
    print(f"  {label:<34} {detail:<34} {'OK' if cond else '<<< FAILED'}")
    if not cond:
        failed.append(label)


# The same expression used by migration 20261021120000.
SQL_EXPR = (
    "REPLACE(REPLACE(REPLACE(UPPER(TRIM({v})), ' ', ''), '_', ''), '-', '')"
)

CASES = [
    'Light Frame', 'LIGHT', 'light', 'DarkFrame', 'DARK', 'DARKFLAT',
    'DarkFlat', 'FlatFrame', 'SKYFLAT', 'DOMEFLAT', 'FLATFIELD',
    'BIAS', 'BIAS ', ' bias_frame ', 'SCIENCE', 'Light', 'UNKNOWN',
    'MASTER DARK', 'FLAT DARK', '', '   ', 'GUIDE STAR', 'DarkFlat_2s',
]

conn = mysql.connector.connect(
    host=os.environ.get("DB_HOST", "mariadb"), database=os.environ.get("DB_NAME", "awi_db"),
    user=os.environ.get("DB_USER", "awi_user"), password=os.environ.get("DB_PASSWORD", ""))
cur = conn.cursor()

print("  case".ljust(34), "python".ljust(12), "sql".ljust(12), "verdict")
print("  " + "-" * 66)

mismatch = 0
for raw in CASES:
    expected = normalize_imgtype(raw)
    # The migration's CASE, applied to the derived value.
    cur.execute(
        "SELECT CASE "
        "WHEN t = '' THEN 'UNKNOWN' "
        "WHEN t LIKE '%DARK%' THEN 'DARK' "
        "WHEN t LIKE '%FLAT%' THEN 'FLAT' "
        "WHEN t LIKE '%BIAS%' THEN 'BIAS' "
        "WHEN t LIKE 'LIGHT%' OR t = 'SCIENCE' THEN 'LIGHT' "
        "WHEN t = 'UNKNOWN' THEN 'UNKNOWN' "
        "ELSE t END "
        "FROM (SELECT " + SQL_EXPR.format(v="%s") + " AS t) x", (raw,))
    got = cur.fetchone()[0]
    ok = got == expected
    if not ok:
        mismatch += 1
    print(f"  {raw!r:<32} {expected:<12} {got:<12} {'ok' if ok else '<<< DIVERGES'}")

print()
check("SQL and Python agree", mismatch == 0, f"{mismatch} divergences over {len(CASES)} cases")
check("critical cases resolved",
      normalize_imgtype('DarkFlat') == 'DARK' and normalize_imgtype('DARKFLAT') == 'DARK',
      "DARKFLAT -> DARK (test order: DARK before FLAT)")

conn.close()
print("\nRESULT: " + ("the migration can be applied"
                      if not failed else f"FAILED: {failed}"))
sys.exit(0 if not failed else 1)