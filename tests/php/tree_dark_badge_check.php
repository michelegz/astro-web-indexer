<?php
// Check — the darks carry their bias badge in the project tree.
//
// A master dark is the dark minus the bias: a dark with no matching bias is
// not calibrated. The tree said nothing about it: the B badge was on the
// flats (with their D side) and on the lights (with their D and F sides),
// and the dark rows and the dark group headers were bare.
//
// The check is on the HTML that reaches the browser, not on the source: the
// tree is synthetic, built below, and the partial is rendered for real.
//
// What it looks at:
//   - the verdict of diagnoseDarkCoverage() for the darks of the tree;
//   - the B badge on every dark row, green when a bias covers the dark and
//     red when there is none;
//   - the B badge on the dark group headers, the worst verdict of the rows
//     they hold;
//   - the darkflat group, which is a dark all the same (it needs a bias too);
//   - the flats and the lights, whose badges must not have moved.
//
// Run in THREE modes (hypo 1, tree 0, review 2), in three separate
// processes, because the partial declares functions at file level: including
// it twice in the same process is a fatal error.
//
//   hypo=1  project_tree_preview.php: the add-modal preview. The new links
//           are the hypothetical ones ($hypoLinks).
//   hypo=0  projects.php: the real tree.
//   rev =2  projects.php sugModal: the suggestions review. The pending row
//           is a new dark, and the diagnostics come from the merged tree
//           (the "as if accepted" state), exactly as projects.php does it.
//
// Usage:
//   docker cp tests/php/tree_dark_badge_check.php awi-php:/tmp/harness/
//   docker exec awi-php sh -c 'cd /tmp/harness && php tree_dark_badge_check.php 1 && php tree_dark_badge_check.php 0 && php tree_dark_badge_check.php 2'

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

/**
 * One calibration row. The gain is what tells the biases apart: the dark
 * with gain 2 has no bias to match (the only bias has gain 1), so it is the
 * uncovered one.
 */
$cal = static function (int $fid, string $type, float $gain, float $exp, ?float $temp, bool $pending = false) {
    return [
        'file_id' => $fid, 'name' => "F{$fid}.fits", 'imgtype' => $type,
        'enabled' => 1, 'pending' => $pending ? 1 : 0,
        'suggestion_id' => $fid + 1000, 'reason' => 'why',
        'level' => 'setup', 'node_id' => 1,
        'scope_sessions' => [], 'scope_nights' => [],
        'exptime' => $exp, 'ccd_temp' => $temp, 'filter' => 'Ha',
        'gain' => $gain, 'xbinning' => 1, 'ybinning' => 1, 'role' => 'sub',
    ];
};

$light = static function (int $fid) {
    return [
        'file_id' => $fid, 'name' => "F{$fid}.fits", 'exptime' => 120.0, 'ccd_temp' => -10.0,
        'enabled' => 1, 'pending' => 0, 'auto_off' => 0,
        'suggestion_id' => $fid + 1000, 'reason' => 'why',
        'filter' => 'Ha', 'gain' => 1.0, 'xbinning' => 1, 'ybinning' => 1,
    ];
};

$projectTree = [
    'setups' => [[
        'id' => 1, 'setup_no' => 1, 'label' => 'S1', 'fingerprint' => 'fp',
        'calibrations' => [
            $cal(11, 'BIAS', 1.0, 0.0, null),
            $cal(12, 'DARK', 1.0, 300.0, -10.0),
            $cal(13, 'DARK', 2.0, 300.0, -10.0),
            // The new dark of the review: it arrives with the suggestions.
            $cal(14, 'DARK', 1.0, 600.0, -10.0, $review),
            // Same exposure as the flat: it serves the flat and no light, so
            // it is a darkflat, not a dark.
            $cal(15, 'DARK', 1.0, 20.0, -10.0),
        ],
        'panels' => [[
            'id' => 10, 'panel_no' => 1, 'ra' => 1.0, 'dec' => 2.0, 'label_object' => 'M42',
            'calibrations' => [],
            'sessions' => [[
                'id' => 100, 'session_no' => 3, 'astro_night' => '2026-01-01',
                'calibrations' => [],
                'filters' => [[
                    'name' => 'Ha',
                    // The flat lives with the filter: the setup-level dark
                    // sees it and becomes a darkflat.
                    'calibrations' => [$cal(16, 'FLAT', 1.0, 20.0, -10.0)],
                    'lights' => [$light(21)],
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

// The review shows the tree as it would be once the suggestions are
// accepted: the diagnostics come from the merged tree, the rows from the
// tree that still holds the pending ones.
$diagTree = $review ? mergePendingTree($projectTree) : $projectTree;

$desc = $review ? 'review=2 (projects.php sugModal, suggestions preview)'
    : ($mode ? 'hypo=1 (project_tree_preview.php, add-modal preview)'
             : 'hypo=0 (projects.php, the real tree)');

echo "\n=== $desc ===\n";

// ---------------------------------------------------------------- verdicts
$darkCov = diagnoseDarkCoverage($diagTree);
$flatCov = diagnoseFlatCoverage($diagTree, $tols, $darkRoles);

check('the bias covers the dark with the same gain', ($darkCov[12] ?? null) === 'green', $darkCov[12] ?? 'missing');
check('the dark with another gain is not covered', ($darkCov[13] ?? null) === 'red', $darkCov[13] ?? 'missing');
check('the dark on its own is covered', ($darkCov[14] ?? null) === 'green', $darkCov[14] ?? 'missing');
check('the darkflat is covered too', ($darkCov[15] ?? null) === 'green', $darkCov[15] ?? 'missing');
check('the bias and the flat have no verdict of their own',
    !isset($darkCov[11]) && !isset($darkCov[16]));

// The flat verdicts must not have moved: the flat is covered by the bias
// and by the darkflat.
check('the flat is still covered by the bias', ($flatCov[16]['bias'] ?? null) === 'green', $flatCov[16]['bias'] ?? 'missing');
check('  and by the dark', ($flatCov[16]['dark'] ?? null) === 'green', $flatCov[16]['dark'] ?? 'missing');

// ------------------------------------------------------------------ render
$hypoLinks = $mode ? ['12:setup:1' => true] : [];

$calCtxBase = [
    'dup' => $dupLinks,
    'filterAliases' => [],
    'darkRoles' => $darkRoles,
    'flatCov' => $flatCov,
    'darkCov' => $darkCov,
    'reviewMode' => $review,
    'hypoLinks' => $hypoLinks,
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

/**
 * The badges of a calibration row: the letter and the colour of every
 * status box inside the row that holds the file.
 */
function rowBadges(string $html, string $fileName): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $out = [];
    foreach ($xp->query('//li') as $li) {
        if (!str_contains($li->textContent, $fileName)) {
            continue;
        }
        foreach ($xp->query('.//span[contains(concat(" ", normalize-space(@class), " "), " w-5 ")]', $li) as $sp) {
            $bg = '';
            if (preg_match('/bg-(green|yellow|red|gray)-/', $sp->getAttribute('class'), $m)) {
                $bg = $m[1];
            }
            $out[] = trim($sp->textContent) . ':' . $bg;
        }
    }
    return $out;
}

/**
 * The badges on the header of a calibration group, picked by its title
 * ("[DARK] 300 s, -10 °C (2)").
 */
function groupBadges(string $html, string $titleNeedle): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $out = [];
    foreach ($xp->query('//details[contains(@class, "cal-group")]') as $d) {
        $summary = $d->getElementsByTagName('summary')->item(0);
        if ($summary === null || !str_contains($summary->textContent, $titleNeedle)) {
            continue;
        }
        foreach ($xp->query('.//span[contains(concat(" ", normalize-space(@class), " "), " w-5 ")]', $summary) as $sp) {
            $bg = '';
            if (preg_match('/bg-(green|yellow|red|gray)-/', $sp->getAttribute('class'), $m)) {
                $bg = $m[1];
            }
            $out[] = trim($sp->textContent) . ':' . $bg;
        }
    }
    return $out;
}

// ------------------------------------------------------------------- rows
check('the covered dark carries a green B badge',
    rowBadges($html, 'F12.fits') === ['B:green'],
    implode(' ', rowBadges($html, 'F12.fits')));
check('the uncovered dark carries a red B badge',
    rowBadges($html, 'F13.fits') === ['B:red'],
    implode(' ', rowBadges($html, 'F13.fits')));
check('the dark on its own carries a green B badge',
    rowBadges($html, 'F14.fits') === ['B:green'],
    implode(' ', rowBadges($html, 'F14.fits')));
check('the darkflat carries a green B badge',
    rowBadges($html, 'F15.fits') === ['B:green'],
    implode(' ', rowBadges($html, 'F15.fits')));

// The bias and the flat keep their own badges: the bias has none, the flat
// keeps the B/D pair.
check('the bias carries no badge',
    rowBadges($html, 'F11.fits') === [],
    implode(' ', rowBadges($html, 'F11.fits')));
check('the flat keeps its B/D pair',
    rowBadges($html, 'F16.fits') === ['B:green', 'D:green'],
    implode(' ', rowBadges($html, 'F16.fits')));

// --------------------------------------------------------------- headers
check('the dark group header carries the worst badge of its rows',
    groupBadges($html, '[DARK] 300 s') === ['B:red'],
    implode(' ', groupBadges($html, '[DARK] 300 s')));
check('the group of the covered dark carries a green B badge',
    groupBadges($html, '[DARK] 600 s') === ['B:green'],
    implode(' ', groupBadges($html, '[DARK] 600 s')));
check('the darkflat group header carries a green B badge',
    groupBadges($html, '[DARKFLAT] 20 s') === ['B:green'],
    implode(' ', groupBadges($html, '[DARKFLAT] 20 s')));
check('the flat group header keeps its B/D pair',
    groupBadges($html, '[FLAT] Ha') === ['B:green', 'D:green'],
    implode(' ', groupBadges($html, '[FLAT] Ha')));
check('the bias group header carries no badge',
    groupBadges($html, '[BIAS]') === [],
    implode(' ', groupBadges($html, '[BIAS]')));

echo "\nRESULT: " . ($failed
    ? 'FAILED: ' . implode(', ', $failed)
    : 'the darks carry their bias badge in the tree')
    . "\n";
exit($failed ? 1 : 0);
