<?php
// Check — the ▲▼ buttons of the metrics trend card
//
//   index.php -> includes/metrics_chart.php
//
// must re-sort the file table by the metric of the chart they sit next to.
//
// The card's contract is "the charts follow the table order": init.php:89 feeds
// getStarTrend() the same $sortBy/$sortOrder as the table. The two buttons next to
// each chart re-enter that order, so a wrong key is not an error the page reports —
// it is a click that looks like it did nothing. Three ways that happens, none of
// them visible on screen:
//
//   1. the button carries a key that buildOrderClause() does not whitelist. The sort
//      is not rejected, it falls back to 'name' (db_functions.php:102), so the table
//      and every chart come back in the old order. This is the same family as §3 on
//      the projects page, where a chart was identified by the POSITIONAL index of the
//      metric and the labels came out swapped against the data;
//   2. the loop copies the same key into every block: the HTML is well-formed, a
//      global "14 buttons" count still passes, and the seven charts offer six
//      identical pairs. Only the pairing button<->canvas catches it;
//   3. the name ends up printed twice, once by this header and once by the Chart.js
//      title plugin, which is why the plugin is checked as OFF here.
//
// The list of metrics is NOT written out in this test: it is read from the 'star'
// group of the column registry (columns.php:42), the same source the Columns dialog
// lists, so a metric added on one side and forgotten on the other fails here instead
// of silently shipping a chart with no sort buttons (or buttons for a chart that is
// not there).
//
// No writes: $starTrend is synthetic and the partial never queries the database.
//
// Usage:
//   docker cp tests/php/trend_sort_check.php awi-php:/tmp/harness/
//   docker exec awi-php sh -c 'cd /tmp/harness && php trend_sort_check.php'

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once '/var/www/html/includes/config.php';

$lang = DEFAULT_LANGUAGE;
$strings = include '/var/www/html/languages/' . $lang . '.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/columns.php';

$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-54s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

/**
 * Renders metrics_chart.php and returns its HTML.
 *
 * $sortBy/$sortOrder/$starTrend/$lightRecords come from init.php in the real flow;
 * here they are set by the caller, inside a function, so they have to be globals.
 */
function renderTrend(string $sortBy, string $sortOrder, array $starTrend, int $lightRecords): string
{
    $GLOBALS['sortBy'] = $sortBy;
    $GLOBALS['sortOrder'] = $sortOrder;
    $GLOBALS['starTrend'] = $starTrend;
    $GLOBALS['lightRecords'] = $lightRecords;
    $level = ob_get_level();
    ob_start();
    try {
        include '/var/www/html/includes/metrics_chart.php';
        return (string)ob_get_clean();
    } finally {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
    }
}

function parse(string $html): DOMXPath
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOWARNING);
    libxml_clear_errors();
    return new DOMXPath($doc);
}

// Three rows with all seven metrics: enough for the card to open and for the median
// line to exist. Numbers, like the indexer writes them.
function fixtureRows(): array
{
    return [
        ['name' => 'light-001.fits', 'hfr' => 2.51, 'fwhm' => 3.14, 'hfr_sd' => 0.21,
            'eccentricity' => 0.18, 'star_count' => 1840, 'snr_weight' => 0.72, 'psf_signal' => 1234.5],
        ['name' => 'light-002.fits', 'hfr' => 2.02, 'fwhm' => 3.01, 'hfr_sd' => 0.19,
            'eccentricity' => 0.21, 'star_count' => 1910, 'snr_weight' => 0.68, 'psf_signal' => 1180.0],
        ['name' => 'light-003.fits', 'hfr' => 2.88, 'fwhm' => 3.42, 'hfr_sd' => 0.24,
            'eccentricity' => 0.15, 'star_count' => 1702, 'snr_weight' => 0.75, 'psf_signal' => 1301.2],
    ];
}

$expectedKeys = array_keys(getColumnGroups()['star']['columns']);

echo "\n=== the card renders one chart per star-metrics column ===\n";
$html = renderTrend('date_obs', 'ASC', fixtureRows(), 3);
printf("  rendered %d bytes\n", strlen($html));

// A PHP diagnostic printed inside the HTML would be read as markup by the parser
// below (trap: same reason table_escape_check.php checks it on its own).
$diag = preg_match('#\b(Warning|Notice|Deprecated|Fatal error|Parse error)\b\s*:[^\n]{0,240}?\bon line \d+#i',
    $html, $dm, PREG_OFFSET_CAPTURE);
check('no PHP diagnostic in the rendered HTML', !$diag,
    $diag ? '>>> ' . preg_replace('/\s+/', ' ', substr($html, max(0, $dm[0][1]), 140)) : '');

$xp = parse($html);
$canvases = $xp->query('//canvas[starts-with(@id, "trend-chart-")]');
check('the card drew its canvases', $canvases->length > 0, $canvases->length . ' canvases');
check('  one canvas per star-metrics column', $canvases->length === count($expectedKeys),
    $canvases->length . ' canvases vs ' . count($expectedKeys) . ' columns');

echo "\n=== each canvas carries its OWN pair of ▲▼ buttons ===\n";
$seenKeys = [];
foreach ($canvases as $canvas) {
    // canvas -> <div style="height:210px"> -> the per-metric block holding both
    // the header with the buttons and the canvas.
    $block = $canvas->parentNode->parentNode;
    $btns = $xp->query('.//button[contains(concat(" ", normalize-space(@class), " "), " trend-sort ")]', $block);

    // The name is the only span that neither contains a button nor sits inside the
    // span that groups them. Taken by position it would depend on how libxml keeps
    // the blank text between the tags.
    $labels = [];
    foreach ($xp->query('.//span', $block) as $sp) {
        if ($xp->query('.//button', $sp)->length > 0) {
            continue;
        }
        if ($xp->query('ancestor::span', $sp)->length > 0) {
            continue;
        }
        $labels[] = trim($sp->textContent);
    }
    $label = $labels[0] ?? '';

    $parsed = [];
    foreach ($btns as $b) {
        if (preg_match("/sortTable\(\s*'([^']*)'\s*,\s*'([^']*)'\s*\)/", $b->getAttribute('onclick'), $m)) {
            $parsed[] = $m[1] . ' ' . $m[2];
        } else {
            $parsed[] = 'UNPARSEABLE(' . $b->getAttribute('onclick') . ')';
        }
    }
    sort($parsed);
    // Two buttons, both on ONE key, one for each direction. Sorted, the ASC one
    // comes first. Kept as two separate entries on purpose: folding them by key
    // would make «two buttons on the same key» and «one button» the same array,
    // and that is exactly the copy-paste defect this pairing has to catch.
    $key = null;
    if (count($parsed) === 2
        && preg_match('/^(.+) ASC$/', $parsed[0], $a)
        && preg_match('/^(.+) DESC$/', $parsed[1], $b)
        && $a[1] === $b[1]) {
        $key = $a[1];
    }
    printf("  %-14s %-28s [%s]\n", $canvas->getAttribute('id'), $label, implode(' | ', $parsed));

    check('  ' . $canvas->getAttribute('id') . ': two buttons, same key, ASC + DESC',
        $key !== null, implode(' | ', $parsed));
    if ($key !== null) {
        $seenKeys[] = $key;
        // The button label must be the name of THAT metric, not a neighbour's.
        // `[[key]]` is what __() gives back for a metric with no translation
        // (language.php:25): the chart would work, and the card would read "[[hfr2]]".
        check('    and the header shows the translated name of that metric',
            $label !== '' && !str_contains($label, '[[') && str_contains($label, (string)__($key)),
            $label === '' ? '(empty header)' : $label);
        check('    and that name heads the block exactly once',
            substr_count($xp->document->saveHTML($block), $label) === 1,
            substr_count($xp->document->saveHTML($block), $label) . ' occurrences in the block');
    }
}

check('every canvas sorts a different metric', count($seenKeys) === count(array_unique($seenKeys)),
    implode(', ', $seenKeys));
$missing = array_diff($expectedKeys, $seenKeys);
$extra = array_diff($seenKeys, $expectedKeys);
$gap = array_filter([
    $missing ? 'missing: ' . implode(', ', $missing) : '',
    $extra ? 'unexpected: ' . implode(', ', $extra) : '',
]);
check('the buttons cover exactly the star-metrics columns',
    $gap === [], implode(' | ', $gap));

echo "\n=== the key is a column the server really sorts by ===\n";
// Checked on the keys the buttons actually carry, not on the registry: the list
// $expectedKeys comes from is sortable by construction, so probing it would be a
// constant assertion. buildOrderClause() does not reject an unknown key, it
// replaces it with 'name' (db_functions.php:102), so the probe is on the RESULT:
// a key that is not sortable still returns a valid clause, and only the clause's
// own text says so.
foreach (array_unique($seenKeys) as $key) {
    $asc = buildOrderClause($key, 'ASC');
    $desc = buildOrderClause($key, 'DESC');
    check("  sort_by=$key reaches the query untouched",
        $asc === $key . ' ASC' && $desc === $key . ' DESC',
        $asc === $key . ' ASC' ? '' : ">>> fell back to '$asc'");
}

echo "\n=== the name is not drawn twice ===\n";
// The header hosts the buttons, so Chart.js must not print the title again.
// The title is drawn at runtime, so this is read off the partial's own script.
preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $html, $sm);
$inlineJs = implode("\n", $sm[1]);
check('the inline script does not re-enable the Chart.js title',
    !preg_match('#title\s*:\s*\{\s*display\s*:\s*true#', $inlineJs),
    preg_match('#title\s*:\s*\{[^}]*\}#', $inlineJs, $tm) ? '>>> ' . trim($tm[0]) : '');
check('  and it still labels the dataset for the legend',
    str_contains($inlineJs, "label: s.label"), '');

echo "\n=== the active sort is visible on its own button ===\n";
// Without this the card cannot say "you are already looking at HFR ascending": the
// table header says it, but the chart header is 200px above the row it refers to.
foreach ([['hfr', 'ASC'], ['fwhm', 'DESC']] as [$sortBy, $sortOrder]) {
    $xp = parse(renderTrend($sortBy, $sortOrder, fixtureRows(), 3));
    $active = [];
    foreach ($xp->query('//button[contains(concat(" ", normalize-space(@class), " "), " trend-sort ")]') as $b) {
        // The lit state is a class TOKEN. Matching it with a regex over the whole
        // attribute would also match the `hover:text-white` every button carries,
        // and every button would come out lit.
        $tokens = preg_split('/\s+/', trim($b->getAttribute('class')));
        if (!in_array('text-white', $tokens, true)) {
            continue;
        }
        if (preg_match("/sortTable\(\s*'([^']*)'\s*,\s*'([^']*)'\s*\)/", $b->getAttribute('onclick'), $m)) {
            $active[] = $m[1] . ' ' . $m[2];
        }
    }
    check("sort_by=$sortBy sort_order=$sortOrder: exactly one lit button",
        $active === [$sortBy . ' ' . $sortOrder], implode(' | ', $active));
}

echo "\n=== an archive without metrics still hides the card ===\n";
$empty = renderTrend('date_obs', 'ASC', [['name' => 'bias-001.fits']], 0);
check('no chart and no button without metric values',
    !str_contains($empty, 'trend-chart-') && !str_contains($empty, 'trend-sort'), '');

echo "\nRESULT: " . ($failed
    ? 'FAILED: ' . implode(', ', $failed)
    : 'the trend charts sort the table by their own metric') . "\n";
exit($failed ? 1 : 0);