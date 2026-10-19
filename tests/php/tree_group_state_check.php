<?php
// Check — the group header checkbox selects, it does not expand; and in a
// preview tree a group is open if and only if it holds a new file.
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
//    group holding new files starts open, and a group with only files that
//    are already in the project stays folded. A new link inside a folded
//    group is a new link nobody sees, and a group of old files open next to
//    it is noise: the preview exists to show what is new.
//
// The rule is checked at EVERY level (setup, panel, session, filter,
// exposure, calibration group): the template once hardcoded `open` on the
// filters and the exposures, and a group of old files was sitting open
// right next to the new ones, which is exactly what the preview must not
// do.
//
// The tree is synthetic, built below, and the partial is rendered for real:
// the check is on the HTML that reaches the browser, not on the source.
//
// Run in THREE modes (hypo 1, tree 0, review 2), in three separate
// processes, because the partial declares functions at file level: including
// it twice in the same process is a fatal error.
//
//   hypo=1  project_tree_preview.php: the add-modal preview. The new links
//           are the hypothetical ones ($hypoLinks), a group holding one
//           starts open, a group of old files stays folded.
//   hypo=0  projects.php: the real tree. No new files at all, and the
//           default of each level: the setup, the panel, the filters and
//           the exposures open, the sessions and the calibration groups
//           folded.
//   rev =2  projects.php sugModal: the suggestions review. The new files are
//           the pending ones, same rule as the hypo preview.
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
                'filters' => [
                    // Ha holds the new frame, SII holds only old ones.
                    ['name' => 'Ha', 'calibrations' => [], 'lights' => [$light(201, $review ? 1 : 0)]],
                    ['name' => 'SII', 'calibrations' => [], 'lights' => [$light(202, 0)]],
                ],
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

// The new links of the add-modal preview: the BIAS of the setup and the
// frame of the Ha filter.
$hypoLinks = $mode ? ['101:setup:1' => true, '201:filter:100' => true] : [];

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
// The ancestors of the new links, the way project_tree_preview.php computes
// them: the BIAS of the setup, and the frame of the Ha filter (a link of
// the 'filter' level points at the session that holds it).
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
 * Every group of the rendered tree, with the three things the check cares
 * about: the level, whether it is open, and whether it holds a new file.
 *
 * A new file leaves a mark in the HTML in both preview modes: the pending
 * ones carry the suggestion_ids[] checkbox (review), the hypothetical ones
 * the "new" badge (hypo). The real tree has neither.
 */
function groups(string $html): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $out = [];
    foreach ($xp->query('//details') as $d) {
        $cls = $d->getAttribute('class');
        if (str_contains($cls, 'cal-group')) {
            $lvl = 'cal-group';
        } elseif (str_contains($cls, 'border-gray-700/60')) {
            $lvl = 'panel';
        } elseif (str_contains($cls, 'border-gray-700/40')) {
            $lvl = 'session';
        } elseif (str_contains($cls, 'rounded-lg')) {
            $lvl = 'setup';
        } else {
            // Filter or exposure: the exposure one is the innermost, it
            // holds the table of the lights and no other group.
            $lvl = $xp->query('.//details', $d)->length > 0 ? 'filter' : 'exposure';
        }
        $body = $doc->saveHTML($d);
        $out[] = [
            'level' => $lvl,
            'open' => $d->hasAttribute('open'),
            'new' => str_contains($body, 'name="suggestion_ids[]"')
                || str_contains($body, 'text-[10px] font-semibold text-green-300'),
            'title' => trim(preg_replace('/\s+/', ' ', (string)strip_tags(
                (string)($d->getElementsByTagName('summary')->item(0)->textContent ?? '')
            ))),
        ];
    }
    return $out;
}

$g = groups($html);

// The check would pass on an empty tree, so the groups have to be there.
check('the tree has groups', count($g) >= 8, count($g) . ' groups');

$levels = array_count_values(array_column($g, 'level'));
foreach (['setup', 'panel', 'session', 'filter', 'exposure', 'cal-group'] as $lvl) {
    check("the tree has a $lvl group", ($levels[$lvl] ?? 0) > 0, $levels[$lvl] ?? 0);
}

if ($mode || $review) {
    // The rule: open if and only if there is something new inside.
    foreach ($g as $grp) {
        $lbl = $grp['level'] . ($grp['new'] ? ' (new)' : ' (old)');
        check("the $lbl group is " . ($grp['new'] ? 'open' : 'folded'),
            $grp['open'] === $grp['new'],
            $grp['title']);
    }
} else {
    // The real tree: no new files at all, and the default of each level.
    $default = [
        'setup' => true, 'panel' => true, 'session' => false,
        'filter' => true, 'exposure' => true, 'cal-group' => false,
    ];
    foreach ($g as $grp) {
        check("the {$grp['level']} group keeps its default",
            $grp['open'] === ($default[$grp['level']] ?? false),
            $grp['title']);
    }
    check('the real tree has no new file at all',
        count(array_filter($g, fn($grp) => $grp['new'])) === 0);
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
    : 'the group checkbox only selects, and a preview opens the groups with new files')
    . "\n";
exit($failed ? 1 : 0);
