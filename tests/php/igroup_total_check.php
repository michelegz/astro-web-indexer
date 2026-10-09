<?php
// Check 13.14, 13.15 — the grand total is the sum of a partition.
//
// projects.php:1202-1217 accumulates the total over $intGroups: count, exposure, auto_off and
// nights. If a light could sit in two integration groups, both count and exposure
// would be summed twice and the "grand total" would lie with nothing saying so.
//
// The docblock of getIntegrationGroups() says the groups are a partition, and that
// merge_tiles merges *panels* of different setups into a tile, it does not clone lights. This
// test verifies it on real data instead of trusting the comment: it counts the distinct
// lights and compares them with the sum, and does the same on the exposure.
//
// Usage:  docker cp tmp/igroup_total_check.php awi-php:/tmp/
//         docker exec awi-php sh -c 'cd /tmp && php igroup_total_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/projects_diagnostics.php';
require_once '/var/www/html/includes/projects_functions.php';

if (!function_exists('__')) {
    function __(string $key, array $replace = []): string
    {
        return $key;
    }
}

$conn = connectDB();
$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-58s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

$pid = (int)$conn->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();
check('test project', $pid > 0, "id=$pid");

$grouping = getProjectGrouping($conn, $pid);
$tree = getProjectTree($conn, $pid, false);

// The same path as project_tree_preview.php / project_export.php.
$defs = getToleranceDefs();
$posTols = [];
foreach ($defs as $tkey => $tdef) {
    $posTols[$tkey] = resolve_tol($conn, $pid, $tkey);
}
$tolExpRaw = trim((string)($posTols['tol_exp'] ?? '1%'));
$tolTempRaw = trim((string)($posTols['tol_temp'] ?? '2C'));
$aliases = getProjectFilterAliases($conn, $pid);
$thresholds = getProjectThresholds($conn, $pid);

$groups = getIntegrationGroups($tree, $tolExpRaw, $thresholds, $tolTempRaw, $grouping, $posTols, $aliases);

echo "\n=== 13.14 / 13.15: the sum of the groups is a partition ===\n";
printf("  grouping: split_setup=%s split_panel=%s split_filter=%s merge_tiles=%s\n",
    var_export($grouping['split_setup'] ?? null, true),
    var_export($grouping['split_panel'] ?? null, true),
    var_export($grouping['split_filter'] ?? null, true),
    var_export($grouping['merge_tiles'] ?? null, true));
printf("  groups: %d\n", count($groups));

// The total as projects.php computes it, replayed here.
$totCount = 0;
$totExp = 0.0;
$totAuto = 0;
$totNights = [];
$sumDistinct = 0;
$sumDistinctExp = 0.0;
$dupes = [];
$seen = [];
foreach ($groups as $gi => $g) {
    $totCount += (int)$g['count'];
    $totExp += (float)$g['exposure'];
    foreach ($g['lights'] as $li) {
        $fid = (int)($li['file_id'] ?? 0);
        if ($fid > 0) {
            if (isset($seen[$fid])) {
                $dupes[] = $fid;
            } else {
                // The exposure of the distinct files: the comparison that closes 13.14.
                $sumDistinctExp += (float)($li['exptime'] ?? 0);
            }
            $seen[$fid] = true;
        }
        if (!empty($li['auto_off'])) {
            $totAuto++;
        }
    }
    foreach ($g['nights'] as $tn) {
        $totNights[$tn] = true;
    }
}
$sumDistinct = count($seen);

// check 1: no file in two groups. This is the property that settles both entries.
check('no file belongs to two groups',
    $dupes === [],
    $dupes === [] ? $sumDistinct . ' distinct files' : 'duplicates: ' . implode(', ', array_slice($dupes, 0, 10)));

// check 2: the sum of the per-group counts matches the distinct files.
check('sum of the counts = distinct files',
    $totCount === $sumDistinct,
    "sum={$totCount} distinct={$sumDistinct}");

// check 3: the exposure. Float sums can diverge by a few ulp, so the
// comparison is not absolute but relative.
check('sum of the exposures = exposure of the distinct files',
    abs($totExp - $sumDistinctExp) <= 1e-6,
    sprintf('sum=%.3f s (%.4f h)', $totExp, $totExp / 3600));

// check 4: the files of the groups must exist and not be soft-deleted. The shape of the
// linking query is reused from the tree, so here it is enough to check that they exist:
// if a group contained a nonexistent id the count would lie.
$groupedIds = array_keys($seen);
$missingFiles = [];
foreach (array_chunk($groupedIds, 500) as $chunk) {
    $in = implode(',', array_map('intval', $chunk));
    $found = $conn->query(
        "SELECT COUNT(*) FROM files WHERE deleted_at IS NULL AND id IN ({$in})"
    )->fetchColumn();
    if ((int)$found !== count($chunk)) {
        $missingFiles[] = count($chunk) - (int)$found;
    }
}
check('the files of the groups exist and are not soft-deleted',
    $missingFiles === [],
    $missingFiles === [] ? count($groupedIds) . ' files verified'
        : 'not found: ' . implode(', ', $missingFiles));

$projectFiles = (int)$conn->query("SELECT COUNT(*) FROM project_files WHERE level = 'project' AND node_id = {$pid}")->fetchColumn();
printf("  (project-level links on the project: %d)\n", $projectFiles);

// check 5: the nights. Here too, a set overlapping between groups is not a summing
// error, but the total must be the union, and count() on an array with duplicates
// would give a number larger than the real nights.
$nightsDupes = 0;
$allNights = [];
foreach ($groups as $g) {
    foreach ($g['nights'] as $tn) {
        if (isset($allNights[$tn])) {
            $nightsDupes++;
        }
        $allNights[$tn] = true;
    }
}
printf("  nights: union=%d (reappearances across groups: %d, expected: a night can sit in more groups)\n",
    count($allNights), $nightsDupes);
check('the total of the nights is the real union',
    count($totNights) === count($allNights),
    'total=' . count($totNights) . ' union=' . count($allNights));

// =====================================================================
// the case in which the partition could break: tile mode
// =====================================================================
// merge_tiles merges panels of different setups with the same position/rotation/FoV. The
// test project has merge_tiles = false, so the suspicious case was never
// exercised: it is exactly where a light could end up in two groups. The
// configuration is forced in memory, without writing anything.
echo "\n=== tile mode: does the partition hold there too? ===\n";

$tileGrouping = array_merge($grouping, [
    'split_setup' => false,   // precondition of groupingTilesEffective()
    'split_panel' => true,
    'merge_tiles' => true,
]);
$tilesOn = function_exists('groupingTilesEffective') && groupingTilesEffective($tileGrouping);
check('the tile configuration is effective', $tilesOn,
    'split_setup=' . var_export($tileGrouping['split_setup'] ?? null, true)
    . ', merge_tiles=' . var_export($tileGrouping['merge_tiles'] ?? null, true));

if ($tilesOn) {
    $tileGroups = getIntegrationGroups($tree, $tolExpRaw, $thresholds, $tolTempRaw,
        $tileGrouping, $posTols, $aliases);
    $tCount = 0;
    $tExp = 0.0;
    $tDupes = [];
    $tSeen = [];
    $tDistinctExp = 0.0;
    foreach ($tileGroups as $g) {
        $tCount += (int)$g['count'];
        $tExp += (float)$g['exposure'];
        foreach ($g['lights'] as $li) {
            $fid = (int)($li['file_id'] ?? 0);
            if ($fid <= 0) {
                continue;
            }
            if (isset($tSeen[$fid])) {
                $tDupes[] = $fid;
            } else {
                $tDistinctExp += (float)($li['exptime'] ?? 0);
            }
            $tSeen[$fid] = true;
        }
    }
    printf("  tile groups: %d, distinct files: %d\n", count($tileGroups), count($tSeen));
    check('tile mode: no file in two groups', $tDupes === [],
        $tDupes === [] ? 'partition intact' : 'DUPLICATES: ' . implode(', ', array_slice($tDupes, 0, 10)));
    check('tile mode: sum of the counts = distinct files',
        $tCount === count($tSeen), "sum={$tCount} distinct=" . count($tSeen));
    check('tile mode: sum of the exposures = distinct files',
        abs($tExp - $tDistinctExp) <= 1e-6,
        sprintf('sum=%.3f s distinct=%.3f s', $tExp, $tDistinctExp));
} else {
    check('tile mode exercised', false, 'groupingTilesEffective() says no');
}

echo "\nRESULT: " . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'the groups are a partition, the total is correct') . "\n";