<?php
// Check — the group header checkbox selects, it does not expand; and a group
// holding new files starts open in a preview tree.
//
//   includes/projects_tree.php   <details open class="cal-group"> when the
//                                group holds new files (review / hypo mode)
//   projects.php                 the cgroup-check handler no longer sets
//                                details.open
//
// Two things the user asked for, both easy to break by "improving" the
// handlers:
//
// 1. Clicking the checkbox on a group header must only select. It used to
//    open the group as well (`if (box.checked && details) details.open = true`),
//    so a click on a collapsed group both selected the files and unfolded
//    them: selecting is a decision, unfolding is another one, and the second
//    one was taken away from the user.
//
// 2. In a preview tree (the suggestions review and the add-modal preview) a
//    group holding new files starts open. A new link folded inside a
//    collapsed group is a new link nobody sees, and the preview exists
//    exactly to show what is new.
//
// The tree is synthetic, built below, and the partial is rendered for real:
// the check is on the HTML that reaches the browser, not on the source.
//
// Run in THREE modes (hypo 1, tree 0, review 2), in three separate
// processes, because the partial declares functions at file level: including
// it twice in the same process is a fatal error.
//
//   hypo=1  project_tree_preview.php: the add-modal preview. The new links
//           are the hypothetical ones ($hypoLinks), the group holding one
//           starts open.
//   hypo=0  projects.php: the real tree. No group starts open, the tree is
//           there to be browsed.
//   rev =2  projects.php sugModal: the suggestions review. The new files are
//           the pending ones, the group holding one starts open.
//
// Usage:
//   docker cp tests/php/tree_group_state_check.php awi-php:/tmp/harness/
//   docker exec awi-php sh -c 'cd /tmp/harness && php tree_group_state_check.php 1 && php tree_group_state_check.php 0 && php tree_group_state_check.php 2'

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/projects_functions.php';
require_once '/var/www/html/includes/projects_diagnostics.php';

// language.php defines __(), which the partial calls on every line. $strings
// has to be initialized by hand because the language file would otherwise read
// it with HEADER_TITLE already defined above.
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
 * One calibration row. A bias and a dark land in two different groups
 * (groupCalibrations splits by imgtype), so one group can hold a new file
 * while the other one holds none: that is the pair the check needs.
 */
$cal = static function (int $fid, string $type, int $pending) {
    return [
        'file_id' => $fid, 'name' => "F{$fid}.fits", 'imgtype' => $type,
        'enabled' => 1, 'pending' => $pending,
        'suggestion_id' => $fid + 1000, 'reason' => 'why',
        'level' => 'setup', 'node_id' => 1,
        'scope_sessions' => [], 'scope_nights' => [],
        'exptime' => 60.0, 'ccd_temp' => -10.0, 'filter' => 'Ha',
    ];
};

$light = static function (int $fid, int $pending) {
    return [
        'file_id' => $fid, 'name' => "F{$fid}.fits", 'exptime' => 120.0, 'ccd_temp' => -10.0,
        'enabled' => 1, 'pending' => $pending, 'auto_off' => 0,
        'suggestion_id' => $fid + 1000, 'reason' => 'why',
        'filter' => 'Ha',
    ];
};

$projectTree = [
    'setups' => [[
        'id' => 1, 'setup_no' => 1, 'label' => 'S1', 'fingerprint' => 'fp',
        // BIAS: the group that will hold the new file. DARK: the group that
        // will not.
        'calibrations' => [$cal(101, 'BIAS', $review ? 1 : 0), $cal(102, 'DARK', 0)],
        'panels' => [[
            'id' => 10, 'panel_no' => 1, 'ra' => 1.0, 'dec' => 2.0, 'label_object' => 'M42',
            'calibrations' => [],
            'sessions' => [[
                'id' => 100, 'session_no' => 3, 'astro_night' => '2026-01-01',
                'calibrations' => [],
                'filters' => [[
                    'name' => 'Ha',
                    'calibrations' => [],
                    'lights' => [$light(201, $review ? 1 : 0)],
                ]],
            ]],
        ]],
    ]],
    'project_links' => [],
];

$projectDiag = diagnoseProjectTree($projectTree, [], []);
$dupLinks = indexDuplicateLinks($projectTree);
$darkRoles = indexDarkRoles($projectTree, []);

$desc = $review ? 'review=2 (projects.php sugModal, suggestions preview)'
    : ($mode ? 'hypo=1 (project_tree_preview.php, add-modal preview)'
             : 'hypo=0 (projects.php, the real tree)');

echo "\n=== $desc ===\n";

// The new links of the add-modal preview: the BIAS of the setup.
$hypoLinks = $mode ? ['101:setup:1' => true] : [];

$calCtxBase = [
    'dup' => $dupLinks,
    'filterAliases' => [],
    'darkRoles' => $darkRoles,
    'flatCov' => diagnoseFlatCoverage($projectTree, [], $darkRoles),
    'reviewMode' => $review,
    'hypoLinks' => $hypoLinks,
    'hypoMode' => $mode,
    'tols' => ['exp' => '1%', 'temp' => '2C'],
];
$hypoMode = $mode;
$hypoOpen = ['setups' => [], 'panels' => [], 'sessions' => []];
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
 * The calibration groups of the rendered tree, each with the two things the
 * check cares about: is it open, and does it hold a new file.
 *
 * A new file is marked differently in the two preview modes, so the marker
 * follows the mode: the pending ones carry the suggestion_ids[] checkbox
 * (review), the hypothetical ones carry the "new" badge (hypo).
 */
function calGroups(string $html, string $marker): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $out = [];
    foreach ($xp->query("//details[contains(concat(' ', normalize-space(@class), ' '), ' cal-group ')]") as $d) {
        $body = $d->ownerDocument->saveHTML($d);
        $out[] = [
            'open' => $d->hasAttribute('open'),
            'new' => $marker !== '' && str_contains($body, $marker),
            'title' => trim(preg_replace('/\s+/', ' ', (string)strip_tags(
                (string)($d->getElementsByTagName('summary')->item(0)->textContent ?? '')
            ))),
        ];
    }
    return $out;
}

$marker = $review ? 'name="suggestion_ids[]"' : __('projects_hypo_new');
$groups = calGroups($html, $marker);

$withNew = array_values(array_filter($groups, fn($g) => $g['new']));
$without = array_values(array_filter($groups, fn($g) => !$g['new']));

if ($mode || $review) {
    // The check would pass on an empty tree, so the pair has to be there.
    check('the tree has a group with new files', $withNew !== [],
        count($withNew) . ' of ' . count($groups));
    check('the tree has a group without new files', $without !== [],
        count($without) . ' of ' . count($groups));
    foreach ($withNew as $g) {
        check('the group with the new files is open', $g['open'], $g['title']);
    }
    foreach ($without as $g) {
        check('the group without new files stays closed', !$g['open'], $g['title']);
    }
} else {
    // The real tree has no new files at all, and keeps every group closed:
    // it is there to be browsed.
    check('the real tree has no new files', $withNew === [],
        count($withNew) . ' of ' . count($groups));
    foreach ($groups as $g) {
        check('the group stays closed in the real tree', !$g['open'], $g['title']);
    }
}

// The header checkbox selects, it does not expand. The source of both
// handlers is checked here: the behaviour lives in the JS, and the rendered
// tree cannot show it.
$js = (string)file_get_contents('/var/www/html/projects.php');
check('the group header checkbox does not open the group',
    !str_contains($js, 'details.open = true'),
    str_contains($js, 'details.open = true') ? 'a handler still expands the group' : '');
check('  and the cgroup handlers are still there',
    substr_count($js, "cgroup-check") >= 2,
    substr_count($js, 'cgroup-check') . ' mentions');

echo "\nRESULT: " . ($failed
    ? 'FAILED: ' . implode(', ', $failed)
    : 'the group checkbox only selects, and a group with new files starts open')
    . "\n";
exit($failed ? 1 : 0);
