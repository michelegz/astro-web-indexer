<?php
// Check — includes/sff_filter_template.php must write the reference value
// with htmlspecialchars.
//
// The HTML produced here is what api/sff_get_filters.php echoes and what
// sff.js:53 puts into sffFiltersPanel.innerHTML:
//
//   sff.js:53  sffFiltersPanel.innerHTML = html
//     <- fetch /api/sff_get_filters.php?id=<file>&type=<searchType>
//       <- render_sff_filter($config, $referenceFile[$key])
//
// The reference value is files.<column> of a LIGHT frame, i.e. the FITS header
// of an archive file: whoever deposits a file controls it.
//
// Nothing needs to be written here: the reference value is synthetic, passed as an
// argument. So the test is a real regression test — remove one of the two
// htmlspecialchars and it goes red.
//
// Note on the HTTP path: the endpoint reads the value from the database and not from the
// request, so there is no way to render a client-controlled one without
// writing a row into files. tree_preview_escape_check.php covers instead the real
// HTTP path of project_tree_preview.php, where the name comes from the request.
//
// Usage:
//   docker cp tmp/sff_filter_escape_check.php awi-php:/tmp/
//   docker exec awi-php sh -c 'cd /tmp && php sff_filter_escape_check.php'

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once '/var/www/html/includes/config.php';

// language.php defines __(), which the template calls in the toggle branch. $strings must
// be initialized by hand because the language file would otherwise read it with
// HEADER_TITLE already defined above. getBestLanguage() lives in language_functions.php
// and must be loaded before language.php, which calls it right away.
$lang = DEFAULT_LANGUAGE;
$strings = include '/var/www/html/languages/' . $lang . '.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
require_once '/var/www/html/includes/sff_filter_template.php';

$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-56s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

/**
 * Second opinion independent of the regexes: a real HTML parser says whether there is an
 * element or an attribute the template did not write. In the negative check it
 * saw both the text branch and the attribute one.
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

// The payload covers text, attribute with double quotes and attribute with single quotes.
$XSS = 'XSSPAYLOAD<img src=x onerror=alert(1)>"\'<svg onload=alert(2)>';
$NEEDLE = 'XSSPAYLOAD';

echo "\n=== render_sff_filter() with a hostile reference value ===\n";

// One filter per type, so both the toggle branch (which has no slider)
// and the three slider types, which add data-type, value= and data-unit=, are covered.
//
// The ATTACK side is only $referenceValue: it comes from the database, so it is controlled
// by whoever deposits the file. The configuration fields come instead from
// sff_all_filters(), a hand-written catalog: they are not archive data. Here they
// receive the payload anyway as defence in depth, and the toggle branch carries a
// hostile unit to also cover the text label on line 52.
$configs = [
    ['id' => 'object', 'label' => $XSS, 'type' => 'toggle', 'default_on' => true, 'unit' => $XSS],
    ['id' => 'filter', 'label' => 'Filter', 'type' => 'slider_percent', 'default_on' => true,
     'min' => 0, 'max' => 100, 'step' => 1, 'default_tolerance' => 5, 'unit' => '%'],
    ['id' => 'exptime', 'label' => 'Exposure', 'type' => 'slider_absolute', 'default_on' => false,
     'min' => 0, 'max' => 3600, 'step' => 1, 'default_tolerance' => 0, 'unit' => $XSS],
    ['id' => 'ra', 'label' => 'RA', 'type' => 'slider_degrees', 'default_on' => false,
     'min' => 0, 'max' => 360, 'step' => 1, 'default_tolerance' => 1, 'unit' => "\u{00b0}"],
];

$level = ob_get_level();
ob_start();
foreach ($configs as $cfg) {
    render_sff_filter($cfg, $XSS);
}
$html = (string)ob_get_clean();

file_put_contents('/tmp/sff_render_escape_check.html', $html);
printf("  rendered %d bytes over %d filters\n", strlen($html), count($configs));

// Positivity check: without a payload in the HTML, 'no injection' would be
// true just because the page was empty.
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

// The two contexts must be checked by position, not just globally: otherwise
// a test covering only the text would pass even without protecting the attribute.
check('the value is escaped in the text (line 37)',
    str_contains($html, 'text-gray-300">' . $NEEDLE . '&lt;'), '');
check('the value is escaped in value= (line 38)',
    str_contains($html, 'class="sff-reference-value" value="' . $NEEDLE . '&lt;'), '');
// The label on line 51-52 uses $filterConfig['unit']: a double quote would
// close data-unit and inject a handler. The field is from the catalog, not
// from the archive, so this is defence in depth.
check('the unit is escaped in data-unit (line 51)',
    str_contains($html, 'data-unit="' . $NEEDLE . '&lt;'), '');
check('  and the text label next to it (line 52)',
    str_contains($html, '&pm;0' . $NEEDLE . '&lt;'), '');

// The toggle branch has no slider: without this, the test would pass even if the
// slider branch had vanished, taking the value= and data-unit coverage with it.
check('the slider branch was rendered',
    substr_count($html, 'sff-filter-slider') === 3,
    substr_count($html, 'sff-filter-slider') . ' sliders over ' . count($configs) . ' filters');

echo "\n--- text and attribute, raw bytes ---\n";
if (preg_match('#text-gray-300">' . preg_quote($NEEDLE, '#') . '&lt;[^<]{0,60}#', $html, $m)) {
    echo '  text      : ' . $m[0] . "\n";
}
if (preg_match('#class="sff-reference-value" value="[^"]{0,80}#', $html, $m)) {
    echo '  attribute : ' . $m[0] . "\n";
}

echo "\nRESULT: " . ($failed
    ? 'FAILED: ' . implode(', ', $failed)
    : 'sff_filter_template.php escapes text, value= and data-unit') . "\n";
exit($failed ? 1 : 0);
