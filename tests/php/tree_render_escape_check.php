<?php
// Check — includes/projects_tree.php must write every database-controlled value
// as text or attribute with htmlspecialchars.
//
// The partial produces the HTML that ends up in two sinks:
//
//   api/project_tree_preview.php  -> JSON 'html' -> main.js:646 projectTreePreview.innerHTML
//   projects.php:803              -> straight into the document
//
// The values crossing it come from the database: getProjectTree() selects f.* on
// every link row, so files.name (the FITS file name) and the labels of
// project_sessions / project_panels / project_setups. Whoever can deposit an FITS
// in the archive controls that string.
//
// Nothing needs to be written here: the tree is synthetic, built below. So
// this test can run at any time and stays a real regression test:
// remove one of the htmlspecialchars and it goes red.
//
// Run in THREE modes (hypo 1, tree 0, review 2), in three separate processes,
// because the branches take different paths and the partial declares functions at
// file level: including it twice in the same process is a fatal error.
//
//   hypo=1  the one of project_tree_preview.php: no checkboxes, no rename button,
//           the setups are already open
//   hypo=0  the one of projects.php: data-setup-name="" appears (line 250), the
//           attribute context that hypoMode never reaches
//   rev =2  the one of the suggestions modal (sugBulkForm): no link_keys[] checkboxes,
//           no rename, integer suggestion_ids[] checkboxes on the pending ones only with
//           the reason in an escaped tooltip, group masters without a name, only the
//           branches with pending ones open
//
// Usage:
//   docker cp tmp/tree_render_escape_check.php awi-php:/tmp/
//   docker exec awi-php sh -c 'cd /tmp && php tree_render_escape_check.php 1 && php tree_render_escape_check.php 0 && php tree_render_escape_check.php 2'

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

/**
 * Second opinion independent of the regexes: a real HTML parser is asked whether there
 * is an element or an attribute the template never wrote. In the negative
 * check this is the one that caught the break of the attribute
 * context, where my regular expression watched the wrong character.
 */
function injectedMarkup(string $html): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $bad = [];
    foreach ($xp->query('//*') as $el) {
        $tag = strtolower($el->nodeName);
        if (in_array($tag, ['img', 'svg', 'script', 'iframe'], true)) {
            $bad[] = "element <$tag>";
        }
        foreach ($el->attributes as $attr) {
            if (stripos($attr->nodeName, 'on') === 0) {
                $bad[] = "attribute {$attr->nodeName} on <$tag>";
            }
        }
    }
    return array_values(array_unique($bad));
}

// The payload covers the three contexts in which the partial writes: text, attribute with
// double quotes, and attribute with single quotes. parseProjectAddRequest truncates to 64
// characters only the custom setup name, so the length is not a limit here.
$XSS = 'XSSPAYLOAD<img src=x onerror=alert(1)>"\'<svg onload=alert(2)>';
$NEEDLE = 'XSSPAYLOAD';

$arg = (string)($argv[1] ?? '1');
$review = $arg === '2';

$cal = static function (int $fid) use ($XSS, $review) {
    return [
        'file_id' => $fid, 'name' => $XSS, 'imgtype' => $XSS,
        'enabled' => 1, 'pending' => $review ? 1 : 0,
        'suggestion_id' => $fid + 1000, 'reason' => $XSS,
        'level' => 'setup', 'node_id' => 1,
        'scope_sessions' => [], 'scope_nights' => [$XSS],
        'exptime' => $XSS, 'ccd_temp' => $XSS,
    ];
};

$light = static function (int $fid) use ($XSS, $review) {
    return [
        'file_id' => $fid, 'name' => $XSS, 'exptime' => $XSS, 'ccd_temp' => 1.0,
        'enabled' => 1, 'pending' => $review ? 1 : 0, 'auto_off' => 0,
        'suggestion_id' => $fid + 1000, 'reason' => $XSS,
        'filter' => $XSS,
    ];
};

$projectTree = [
    'setups' => [[
        'id' => 1, 'setup_no' => 1, 'label' => $XSS, 'fingerprint' => $XSS,
        'calibrations' => [$cal(101)],
        'panels' => [[
            'id' => 10, 'panel_no' => 1, 'ra' => 1.0, 'dec' => 2.0, 'label_object' => $XSS,
            'calibrations' => [$cal(102)],
            'sessions' => [[
                'id' => 100, 'session_no' => 3, 'astro_night' => $XSS,
                'calibrations' => [$cal(103)],
                'filters' => [[
                    'name' => $XSS,
                    'calibrations' => [$cal(104)],
                    'lights' => [$light(201)],
                ]],
            ]],
        ]],
    ]],
    'project_links' => [],
];

$projectDiag = diagnoseProjectTree($projectTree, [], []);
$dupLinks = indexDuplicateLinks($projectTree);
$darkRoles = indexDarkRoles($projectTree, []);

$mode = !$review && (!isset($argv[1]) || (string)$argv[1] === '1');
$desc = $review ? 'suggestReviewMode=true (projects.php sugModal)'
    : ($mode ? 'hypoMode=true  (project_tree_preview.php)' : 'hypoMode=false (projects.php)');

echo "\n=== $desc ===\n";

$calCtxBase = [
    'dup' => $dupLinks,
    'filterAliases' => [],
    'darkRoles' => $darkRoles,
    'flatCov' => diagnoseFlatCoverage($projectTree, [], $darkRoles),
    'reviewMode' => $review,
    // Only in hypoMode does the partial read this, for the green badge.
    'hypoLinks' => $mode ? ['101:setup:1' => true, '102:panel:10' => true,
                            '103:session:100' => true, '104:filter:100' => true,
                            '201:filter:100' => true] : [],
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
if (!$mode) {
    // Saved only to show the raw bytes of the two contexts at the end of the output.
    file_put_contents('/tmp/tree_render_last.html', $html);
}

// Positivity check. If the payload did not reach the HTML, «no
// injection» would be true only because the page was empty.
$occ = substr_count($html, $NEEDLE);
$esc = substr_count($html, $NEEDLE . '&lt;');
check('the payload reached the HTML', $occ > 0, "$occ occurrences");
printf("  occurrences: total=%d  with '<' as entity=%d\n", $occ, $esc);
check('  every occurrence has \'<\' as an entity', $esc === $occ,
    $esc === $occ ? '' : ($occ - $esc) . ' raw');

$liveTag = preg_match('#' . preg_quote($NEEDLE, '#') . '\s*<(img|svg|script)#i', $html, $m1);
check('no live tag after the payload', !$liveTag, $liveTag ? '>>> ' . $m1[0] : '');

$bad = injectedMarkup($html);
check('no injected element or handler (parser)', $bad === [],
    $bad ? implode('; ', $bad) : '');

// The attribute context exists only in the non-hypo branch. Its absence in hypoMode
// is expected, and is asserted: otherwise the test would pass silently on a
// context it never visited.
$hasAttr = str_contains($html, 'data-setup-name=');
if ($mode === false && !$review) {
    check('the non-hypo branch renders data-setup-name', $hasAttr, '');
    check('  and its value stays closed',
        $hasAttr && str_contains($html, 'data-setup-name="' . $NEEDLE . '&lt;'), '');
} else {
    check('the hypoMode/review branches do not render data-setup-name', !$hasAttr,
        $hasAttr ? 'the attribute context would have been visited twice' : '');
}

if ($review) {
    // The review shows suggestion_ids[] (integers from the DB) and the textual reason:
    // the first cannot escape from the value, the second has to be escaped.
    preg_match_all('/name="suggestion_ids\[\]" value="([^"]*)"/', $html, $mSug);
    $sugVals = $mSug[1];
    $allInt = $sugVals !== [];
    foreach ($sugVals as $v) {
        if (!ctype_digit($v)) {
            $allInt = false;
            break;
        }
    }
    check('the review has checkboxes on the pending ones (suggestion_ids[])', $sugVals !== [],
        count($sugVals) . ' checkboxes');
    check('  the values are plain integers', $allInt, $allInt ? '' : implode(',', $sugVals));
    check('the review has no link_keys[] checkboxes', !str_contains($html, 'name="link_keys[]"'), '');
    // The review has the group masters like the real tree (they select the pending
    // ones only), but without a name: they are never sent, only sug-check counts.
    check('the review has the group masters', str_contains($html, 'pgroup-check')
        && str_contains($html, 'cgroup-check'), '');
    preg_match_all('/<input[^>]*class="[^"]*(?:pgroup-check|cgroup-check)[^"]*"[^>]*>/', $html, $mGrp);
    $grpNamed = array_filter($mGrp[0], fn($tag) => str_contains($tag, 'name='));
    check('  and they have no name (never sent)', $mGrp[0] !== [] && $grpNamed === [], count($mGrp[0]) . ' masters');
}

// Raw bytes of the two contexts, so the result can be judged by eye and not only from the
// check outcome. Only in the non-hypo branch, which is the one containing both.
if (!$mode && !$review) {
    echo "\n--- text (line 248) and attribute (line 250), raw bytes ---\n";
    if (preg_match('#S\d+:\s*' . preg_quote($NEEDLE, '#') . '&lt;[^<]{0,60}#', $html, $m)) {
        echo '  text      : ' . $m[0] . "\n";
    }
    if (preg_match('#data-setup-name="[^"]{0,80}#', $html, $m)) {
        echo '  attribute : ' . $m[0] . "\n";
    }
}

echo "\nRESULT: " . ($failed
    ? 'FAILED: ' . implode(', ', $failed)
    : 'projects_tree.php escapes in every context, mode ' . ($review ? 'review' : ($mode ? 'hypo' : 'non-hypo')))
    . "\n";
exit($failed ? 1 : 0);
