<?php

/**
 * Check — the "metrics pending" marker of a row whose star metrics are still owed.
 *
 * The indexer writes the row first (header, preview, frame statistics) and computes
 * the star metrics after, so a row can sit in the table with its metric columns
 * empty while the slow pass is still working on it. Without the marker an empty
 * HFR column looks like a frame with no stars, which is a different thing.
 *
 * What is verified here:
 *  - the marker appears on a row whose files.metrics_pending is 1;
 *  - it does NOT appear on a row whose flag is 0 (the normal case);
 *  - it appears in BOTH views of the main table (the delegated cell renderer and
 *    the cards view, which is a separate branch with its own name sink);
 *  - the label and the tooltip are the translated strings, not the raw keys
 *    (a missing key would render "[[metrics_pending]]", which looks like a bug
 *    the reader would have to decode).
 *
 * Usage:
 *   docker cp tests/php/pending_badge_check.php awi-php:/tmp/
 *   docker exec awi-php sh -c 'cd /tmp && php pending_badge_check.php'
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once '/var/www/html/includes/config.php';

// __() is needed by every label. $strings has to be initialized by hand because the language
// file uses HEADER_TITLE, which is not defined in the CLI.
$lang = DEFAULT_LANGUAGE;
$strings = include '/var/www/html/languages/' . $lang . '.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/columns.php';
require_once '/var/www/html/includes/template_functions.php';
require_once '/var/www/html/includes/file_cells.php';

$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-56s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

// The row: the same shape the indexer writes. The two views use the thumbnail
// as a placeholder, so it is set to keep the preview branch alive.
$row = [
    'id' => 424243, 'name' => 'probe.fits', 'path' => 'probe/probe.fits',
    'object' => 'M31', 'filter' => 'R', 'imgtype' => 'LIGHT',
    'date_obs' => '2026-03-04 05:06:07', 'file_hash' => 'deadbeef',
    'thumb' => "\x89PNG\r\n\x1a\n", 'thumb_crop' => "\x89PNG\r\n\x1a\n",
    'mtime' => 1750000000, 'file_size' => 16980480,
    'exptime' => 600.0, 'width' => 9576, 'height' => 6388,
    'visible_duplicate_count' => 1, 'total_duplicate_count' => 1,
];

// Forced visibility: without empty hiddenCols showColFor would hide almost
// everything and the test would pass without rendering the cells it wants.
$hiddenCols = [];
$hiddenColsProjects = [];
$base = array_keys(getBaseColumns());
$groups = getColumnGroups();
$star = $frame = $adv = [];
foreach ($groups as $gk => $g) {
    if ($gk === 'base') {
        continue;
    }
    $bucket = $gk === 'star' ? 'star' : ($gk === 'frame' ? 'frame' : 'adv');
    foreach (array_keys($g['columns']) as $k) {
        ${$bucket}[] = $k;
    }
}
$visibleStarKeys = $star;
$visibleFrameKeys = $frame;
$visibleAdvKeys = $adv;
$visibleProjectStarKeys = $star;
$visibleProjectFrameKeys = $frame;
$visibleProjectAdvKeys = $adv;

$sortBy = 'name';
$sortOrder = 'asc';

/**
 * Renders table.php with one synthetic row.
 */
function renderTable(string $viewMode, array $files): string
{
    $_COOKIE['viewMode'] = $viewMode;
    $_COOKIE['thumbSize'] = '3';
    $GLOBALS['files'] = $files;
    $GLOBALS['tableColspan'] = 12;
    unset($GLOBALS['conn']);
    $page = 1;
    $totalPages = 1;
    $totalRecords = 1;
    $totalExposure = 0.0;
    $perPage = 25;
    $level = ob_get_level();
    ob_start();
    try {
        include '/var/www/html/includes/table.php';
        return (string)ob_get_clean();
    } finally {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
    }
}

$label = __('metrics_pending');
$tooltip = __('metrics_pending_tooltip');

echo "=== the strings are there ===\n";
check('the label is translated, not a raw key', $label !== '[[metrics_pending]]', $label);
check('the tooltip is translated, not a raw key', $tooltip !== '[[metrics_pending_tooltip]]', '');

echo "\n=== a row that owes its metrics ===\n";
$pending = $row;
$pending['metrics_pending'] = 1;
foreach (['list' => 'viewMode=list', 'thumbnail' => 'viewMode=thumbnail'] as $mode => $desc) {
    $html = renderTable($mode, [$pending]);
    $hits = substr_count($html, htmlspecialchars($label));
    check("the marker is in the $desc view", $hits >= 1, "$hits occurrence(s)");
    check("the tooltip is in the $desc view", str_contains($html, htmlspecialchars($tooltip)), '');
}

echo "\n=== a row that is complete ===\n";
$complete = $row;
$complete['metrics_pending'] = 0;
foreach (['list' => 'viewMode=list', 'thumbnail' => 'viewMode=thumbnail'] as $mode => $desc) {
    $html = renderTable($mode, [$complete]);
    $hits = substr_count($html, htmlspecialchars($label));
    check("no marker in the $desc view", $hits === 0, "$hits occurrence(s)");
}

echo "\n=== the flag is read as a number and as a string ===\n";
// The column is a TINYINT: PDO hands it over as an int or as a string,
// depending on the driver settings. Both must light the marker.
foreach ([1, '1', true] as $flag) {
    $r = $row;
    $r['metrics_pending'] = $flag;
    $html = renderTable('list', [$r]);
    check('the marker appears for ' . var_export($flag, true),
        substr_count($html, htmlspecialchars($label)) >= 1, '');
}
foreach ([0, '0', false, null, ''] as $flag) {
    $r = $row;
    $r['metrics_pending'] = $flag;
    $html = renderTable('list', [$r]);
    check('no marker for ' . var_export($flag, true),
        substr_count($html, htmlspecialchars($label)) === 0, '');
}

echo "\n=== a row without the column at all ===\n";
// The project tree rows and the older queries do not carry the column: the
// marker must stay quiet instead of raising a notice.
unset($row['metrics_pending']);
$html = renderTable('list', [$row]);
check('no marker and no diagnostic for a row without the column',
    substr_count($html, htmlspecialchars($label)) === 0
    && !str_contains($html, 'Undefined'), '');

echo "\nRESULT: " . (empty($failed)
    ? 'the marker says that the metrics are still being computed'
    : 'FAILED: ' . implode(', ', $failed)) . "\n";

exit(empty($failed) ? 0 : 1);
