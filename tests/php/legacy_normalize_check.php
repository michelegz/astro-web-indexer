<?php
// Check 13.6 and 13.7 — the legacy data normalization really
// repairs rows and panels written before the rules.
//
// The development dataset already has everything canonical (the normalization comes in with
// this branch), so here legacy values are planted, the SQL that migration
// 20261021120000 will apply is run, and they are checked to become canonical.
// The original values are restored at the end.
//
// Usage:  docker cp tmp/legacy_normalize_check.php awi-php:/tmp/
//         docker exec awi-php sh -c 'cd /tmp && php legacy_normalize_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';

$conn = connectDB();
$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-44s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

// The same expression as the migration, run here so as not to depend on Phinx.
function normalizeImgtypeSql(PDO $conn, string $raw): string
{
    $st = $conn->prepare("SELECT CASE "
        . "WHEN t = '' THEN 'UNKNOWN' "
        . "WHEN t LIKE '%DARK%' THEN 'DARK' "
        . "WHEN t LIKE '%FLAT%' THEN 'FLAT' "
        . "WHEN t LIKE '%BIAS%' THEN 'BIAS' "
        . "WHEN t LIKE 'LIGHT%' OR t = 'SCIENCE' THEN 'LIGHT' "
        . "WHEN t = 'UNKNOWN' THEN 'UNKNOWN' ELSE t END FROM (SELECT "
        . "REPLACE(REPLACE(REPLACE(UPPER(TRIM(?)), ' ', ''), '_', ''), '-', '') AS t) x");
    $st->execute([$raw]);
    return (string)$st->fetchColumn();
}

function normalizeLabelSql(PDO $conn, string $raw): string
{
    $st = $conn->prepare("SELECT UPPER(TRIM(REGEXP_REPLACE(TRIM(?), '[[:space:]]+', ' ')))");
    $st->execute([$raw]);
    return (string)$st->fetchColumn();
}

echo "=== 13.6 imgtype legacy ===\n";
$legacy = [
    'Light Frame' => 'LIGHT', 'DarkFrame' => 'DARK', 'FlatFrame' => 'FLAT',
    'BIAS ' => 'BIAS', 'light_frame' => 'LIGHT', 'SCIENCE' => 'LIGHT',
    'DarkFlat' => 'DARK', 'Master Dark' => 'DARK', 'GUIDE STAR' => 'GUIDESTAR',
];
foreach ($legacy as $raw => $expected) {
    $got = normalizeImgtypeSql($conn, $raw);
    check('legacy imgtype', $got === $expected, sprintf('%s -> %s', var_export($raw, true), $got));
}

// The case that matters: the gate of projects.py:700 and of projectFetchEligibleRow.
// Before: 'Light Frame' is not in ('LIGHT','DARK','FLAT','BIAS') -> discarded.
$eligible = ['LIGHT', 'DARK', 'FLAT', 'BIAS'];
$beforeGate = in_array('Light Frame', $eligible, true);
$afterGate = in_array(normalizeImgtypeSql($conn, 'Light Frame'), $eligible, true);
check('the gate discarded the legacy row', $beforeGate === false);
check('after the normalization it passes', $afterGate === true,
    "'Light Frame' -> " . normalizeImgtypeSql($conn, 'Light Frame'));

echo "\n=== 13.7 label_object legacy ===\n";
$labels = [
    'xyz 1234' => 'XYZ 1234',
    'Q99' => 'Q99',
    'q  99' => 'Q 99',        // inner spaces collapsed
    '  xyz1234  ' => 'XYZ1234',
];
foreach ($labels as $raw => $expected) {
    $got = normalizeLabelSql($conn, $raw);
    check('legacy label_object', $got === $expected,
        sprintf('%s -> %s', var_export($raw, true), $got));
}

// The case that matters: the comparison of the panel with NULL coordinates.
// projects.py:393 compared the raw value with the normalized bucket.
$bucket = strtoupper(trim(preg_replace('/\s+/', ' ', 'XYZ 1234')));
$rawStored = 'xyz 1234';
check('before the comparison failed', $rawStored !== $bucket,
    sprintf('%s !== %s', var_export($rawStored, true), $bucket));
check('after the comparison passes', normalizeLabelSql($conn, $rawStored) === $bucket,
    normalizeLabelSql($conn, $rawStored) . ' === ' . $bucket);

echo "\n=== live DB test: plant and repair a real panel ===\n";
$panelId = (int)$conn->query('SELECT id FROM project_panels ORDER BY id LIMIT 1')->fetchColumn();
if ($panelId > 0) {
    $orig = $conn->query("SELECT label_object FROM project_panels WHERE id = $panelId")
        ->fetchColumn();
    $conn->prepare('UPDATE project_panels SET label_object = ? WHERE id = ?')
        ->execute(['xyz 1234', $panelId]);
    $before = $conn->query("SELECT label_object FROM project_panels WHERE id = $panelId")
        ->fetchColumn();
    // The migration's SQL.
    $conn->exec("UPDATE project_panels SET label_object = "
        . "UPPER(TRIM(REGEXP_REPLACE(TRIM(label_object), '[[:space:]]+', ' '))) "
        . "WHERE id = $panelId");
    $after = $conn->query("SELECT label_object FROM project_panels WHERE id = $panelId")
        ->fetchColumn();
    check('panel repaired on the DB', $before === 'xyz 1234' && $after === 'XYZ 1234',
        sprintf('%s -> %s', var_export($before, true), var_export($after, true)));
    // restore
    $conn->prepare('UPDATE project_panels SET label_object = ? WHERE id = ?')
        ->execute([$orig, $panelId]);
    $restored = $conn->query("SELECT label_object FROM project_panels WHERE id = $panelId")
        ->fetchColumn();
    check('original value restored', $restored === $orig,
        var_export($restored, true));
} else {
    echo "  (no panel: skipped)\n";
}

echo "\nRESULT: " . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'the normalization is correct and repairs the legacy data') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed FAILURES.
exit($failed ? 1 : 0);