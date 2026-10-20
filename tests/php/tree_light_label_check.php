<?php
// Check — the groups of light frames carry the [LIGHT] tag.
//
// The calibration groups of the tree name their type in brackets ("[DARK]
// 300 s, -10 °C (2)", "[FLAT] Ha (1)", "[BIAS] (2)"). The groups of light
// frames did not: the filter group said "Filter Ha" and the light rows said
// the file name and nothing else. Now they say "[LIGHT] Ha" and "[LIGHT]
// name.fits", so every group and every row of the tree says what it holds.
//
// The exposure subgroup keeps its own word ("Exposure 300 s"): it is a
// subgroup of the filter, not another kind of frame.
//
// The tree is synthetic, built below, and the partial is rendered for real:
// the check is on the HTML that reaches the browser, not on the source.
//
// Run in THREE modes (hypo 1, tree 0, review 2), in three separate
// processes, because the partial declares functions at file level: including
// it twice in the same process is a fatal error.
//
//   hypo=1  project_tree_preview.php: the add-modal preview.
//   hypo=0  projects.php: the real tree.
//   rev =2  projects.php sugModal: the suggestions review, where the pending
//           row is a new light.
//
// Usage:
//   docker cp tests/php/tree_light_label_check.php awi-php:/tmp/harness/
//   docker exec awi-php sh -c 'cd /tmp/harness && php tree_light_label_check.php 1 && php tree_light_label_check.php 0 && php tree_light_label_check.php 2'

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/projects_functions.php';
require_once '/var/www/html/includes/projects_diagnostics.php';

// language.php defines __(), which the partial calls on every line. $strings has to be
// initialized by hand because the language file would otherwise read it with
// HEADER_TITLE already defined above. getBestLanguage() lives in language_functions.php
// and has to be loaded before language.php, which calls it immediately.
$lang = DEFAULT_LANGUAGE;
$strings = include '/var/www/html/languages/' . $lang . '.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';

$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-56s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

$arg = (string)($argv[1] ?? '0');
$review = $arg === '2';
$mode = $arg === '1';

$cal = static function (int $fid, string $type, float $exp) {
    return [
        'file_id' => $fid, 'name' => "F{$fid}.fits", 'imgtype' => $type,
        'enabled' => 1, 'pending' => 0, 'suggestion_id' => $fid + 1000, 'reason' => 'why',
        'level' => 'setup', 'node_id' => 1,
        'scope_sessions' => [], 'scope_nights' => [],
        'exptime' => $exp, 'ccd_temp' => -10.0, 'filter' => 'Ha',
        'gain' => 1.0, 'xbinning' => 1, 'ybinning' => 1, 'role' => 'sub',
    ];
};

$light = static function (int $fid, bool $pending = false) {
    return [
        'file_id' => $fid, 'name' => "F{$fid}.fits", 'exptime' => 300.0, 'ccd_temp' => -10.0,
        'enabled' => 1, 'pending' => $pending ? 1 : 0, 'auto_off' => 0,
        'suggestion_id' => $fid + 1000, 'reason' => 'why',
        'filter' => 'Ha', 'gain' => 1.0, 'xbinning' => 1, 'ybinning' => 1,
    ];
};

$projectTree = [
    'setups' => [[
        'id' => 1, 'setup_no' => 1, 'label' => 'S1', 'fingerprint' => 'fp',
        'calibrations' => [$cal(101, 'DARK', 300.0), $cal(102, 'BIAS', 0.0)],
        'panels' => [[
            'id' => 10, 'panel_no' => 1, 'ra' => 1.0, 'dec' => 2.0, 'label_object' => 'M42',
            'calibrations' => [],
            'sessions' => [[
                'id' => 100, 'session_no' => 3, 'astro_night' => '2026-01-01',
                'calibrations' => [],
                'filters' => [[
                    'name' => 'Ha',
                    'calibrations' => [$cal(103, 'FLAT', 20.0)],
                    'lights' => [$light(201), $light(202, $review)],
                ]],
            ]],
        ]],
    ]],
    'project_links' => [],
];

$tols = ['exp' => '1%', 'temp' => '2C', 'rot' => '3deg'];
$projectDiag = diagnoseProjectTree($projectTree, $tols, []);
$dupLinks = indexDuplicateLinks($projectTree);
$darkRoles = indexDarkRoles($projectTree, $tols);

$desc = $review ? 'review=2 (projects.php sugModal, suggestions preview)'
    : ($mode ? 'hypo=1 (project_tree_preview.php, add-modal preview)'
             : 'hypo=0 (projects.php, the real tree)');

echo "\n=== $desc ===\n";

// ------------------------------------------------------------------ render
$calCtxBase = [
    'dup' => $dupLinks,
    'filterAliases' => [],
    'darkRoles' => $darkRoles,
    'flatCov' => diagnoseFlatCoverage($projectTree, $tols, $darkRoles),
    'darkCov' => diagnoseDarkCoverage($projectTree),
    'reviewMode' => $review,
    'hypoLinks' => $mode ? ['201:filter:100' => true] : [],
    'hypoMode' => $mode,
    'tols' => $tols,
];
$hypoMode = $mode;
$hypoOpen = $mode
    ? ['setups' => [1 => true], 'panels' => [10 => true], 'sessions' => [100 => true]]
    : ['setups' => [], 'panels' => [], 'sessions' => []];
$suggestReviewMode = $review;
$suggestOpen = ['setups' => [1 => true], 'panels' => [10 => true], 'sessions' => [100 => true]];

$level = ob_get_level();
ob_start();
try {
    include '/var/www/html/includes/projects_tree.php';
    $html = (string)ob_get_clean();
} finally {
    while (ob_get_level() > $level) {
        ob_end_clean();
    }
}

printf("  rendered %d bytes\n", strlen($html));

$doc = new DOMDocument();
libxml_use_internal_errors(true);
$doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOWARNING);
libxml_clear_errors();
$xp = new DOMXPath($doc);

/** The text of the summary that holds the given needle. */
function summaryText(DOMXPath $xp, string $needle): string
{
    foreach ($xp->query('//summary') as $s) {
        if (str_contains($s->textContent, $needle)) {
            return trim(preg_replace('/\s+/', ' ', $s->textContent));
        }
    }
    return '';
}

/** The text of the row (table row or list item) that holds the file. */
function rowText(DOMXPath $xp, string $fileName): string
{
    foreach ($xp->query('//tr | //li') as $row) {
        if (str_contains($row->textContent, $fileName)) {
            return trim(preg_replace('/\s+/', ' ', $row->textContent));
        }
    }
    return '';
}

// ------------------------------------------------------------- the headers
$filterSummary = summaryText($xp, '[LIGHT] Ha');
check('the filter group is named [LIGHT] Ha', $filterSummary !== '', $filterSummary);
check('  and it does not say "Filter" any more',
    $filterSummary !== '' && !str_contains($filterSummary, (string)__('projects_filter')),
    $filterSummary);
$exposureSummary = summaryText($xp, (string)__('projects_exposure'));
check('the exposure subgroup keeps its own word', $exposureSummary !== '', $exposureSummary);
check('  and it still names the exposure',
    $exposureSummary !== '' && str_contains($exposureSummary, '300 s'),
    $exposureSummary);

// ---------------------------------------------------------------- the rows
foreach ([201, 202] as $fid) {
    $row = rowText($xp, "F{$fid}.fits");
    check("the light row F{$fid}.fits says [LIGHT]",
        $row !== '' && str_contains($row, '[LIGHT]'),
        $row);
}

// The calibration rows keep their own tag: the tree names every frame.
check('the dark row still says [DARK]',
    str_contains(rowText($xp, 'F101.fits'), '[DARK]'),
    rowText($xp, 'F101.fits'));
check('the bias row still says [BIAS]',
    str_contains(rowText($xp, 'F102.fits'), '[BIAS]'),
    rowText($xp, 'F102.fits'));
check('the flat row still says [FLAT]',
    str_contains(rowText($xp, 'F103.fits'), '[FLAT]'),
    rowText($xp, 'F103.fits'));

echo "\nRESULT: " . ($failed
    ? 'FAILED: ' . implode(', ', $failed)
    : 'the groups of light frames carry the [LIGHT] tag')
    . "\n";
exit($failed ? 1 : 0);
