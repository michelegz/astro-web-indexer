<?php
// Check — includes/file_cells.php must write every archive-controlled value with
// htmlspecialchars.
//
// This partial is the largest XSS surface in the project and had no coverage with
// hostile input: it draws ALL the rows of the archive, and on this copy there
// are 365 files, each with name, object, filter, instrume, cameraid, telescop and
// forty other FITS headers. Whoever deposits a file into the archive controls them.
//
//   index.php -> includes/table.php:103 -> renderFileTableCells($f, 'main')
//   projects.php -> includes/igroup_files_table.php:29 -> renderFileTableCells($li, 'project', $liSuffix)
//
// The two scopes take different branches: in 'project' every cell carries data-col/data-val for
// client-side sorting, the duplicates badge is static, and the (off)/(auto_off)
// markers arrive as $nameSuffix. Both are exercised.
//
// No writes: the row is synthetic, built below.
//
// The three contexts the partial writes, and how each is controlled:
//
//   text          htmlspecialchars() on every string column
//   attribute     htmlspecialchars() in fileCellAttrs() (71), on the raw data-val
//   href          rawurlencode() on the path (228): it encodes '%' too, so the payload
//                 appears as XSS%3C and not as XSS<
//
// On the third point the test counts three shapes instead of one: raw, HTML entity and
// percent-encoded. If the path reached the href without encoding, the raw shape would rise
// from 0 and the test would say so.
//
// Usage:
//   docker cp tmp/file_cells_escape_check.php awi-php:/tmp/
//   docker exec awi-php sh -c 'cd /tmp && php file_cells_escape_check.php'

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once '/var/www/html/includes/config.php';

// __() is needed by every label. $strings has to be initialized by hand because the language
// file uses HEADER_TITLE, which is not defined in the CLI; language_functions.php goes
// before language.php, which calls it immediately.
$lang = DEFAULT_LANGUAGE;
$strings = include '/var/www/html/languages/' . $lang . '.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
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

/**
 * Second opinion independent of the regexes: a real HTML parser looks for (a) a dangerous
 * element the template never writes and (b) an on* attribute on ANY element.
 *
 * Note on (b): here the allow-list of tags cannot be used as in the other tests,
 * because this partial deliberately writes <img> for the thumbnails, <div>, <span>, <a>,
 * <td>, <table>, <tbody>, <tr>. The deny-list is that of what must never
 * come from the payload.
 *
 * More important note, already written as trap #34 in the README: searching for the
 * `onerror=` substring to prove that a handler is not injectable is WRONG and
 * produces a false positive. The text `onerror=alert(1)` stays readable inside a
 * correctly escaped attribute (`data-hash="XSS&lt;img src=x onerror=alert(1)&gt;"`),
 * and a regex looking for it there says "injected". The only right level is the
 * attribute name in the parsed DOM.
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
        if (in_array($tag, ['svg', 'script', 'iframe', 'object', 'embed', 'form'], true)) {
            $bad[] = "element <$tag> not expected by the template";
        }
        foreach ($el->attributes as $attr) {
            if (stripos($attr->nodeName, 'on') === 0) {
                $bad[] = "attributo {$attr->nodeName} su <$tag>";
            }
        }
    }
    return array_values(array_unique($bad));
}

/** The duplicates badge, seen from the DOM: no handler, and data-hash with the escaped text. */
function badgeAudit(string $html): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $handlers = [];
    $node = $xp->query('//span[contains(@class,"duplicate-badge")]')->item(0);
    if ($node === null) {
        return ['found' => false, 'handlers' => [], 'hash' => null];
    }
    foreach ($node->attributes as $attr) {
        if (stripos($attr->nodeName, 'on') === 0) {
            $handlers[] = $attr->nodeName;
        }
    }
    return ['found' => true, 'handlers' => $handlers, 'hash' => $node->getAttribute('data-hash')];
}

// The payload covers the three contexts: text, attribute with double quotes, attribute with
// single quotes. No slash, so that dirname() on the path does not eat it: the path has one
// of its own, because it is there to keep the payload inside the directory.
$XSS = 'XSS<img src=x onerror=alert(1)>"\'<svg onload=alert(2)>';
$NEEDLE = 'XSS';

// Columns the indexer writes as numbers. Here they get numbers, not the payload: they are
// not controlled by the archive, and without a cast (resolution, fov_w, fov_h, file_size) a
// non-numeric value would raise a TypeError instead of injecting, so the test would die
// without saying so. The scope of the test is the escaping of strings, not the validation of
// numbers.
$numeric = [
    'mtime' => 1750000000, 'file_size' => 16980480, 'width' => 9576, 'height' => 6388,
    'resolution' => 2.14, 'fov_w' => 326.7, 'fov_h' => 218.0,
    'hfr' => 2.5, 'fwhm' => 3.1, 'hfr_sd' => 0.4, 'eccentricity' => 0.11,
    'star_count' => 1840, 'snr_weight' => 0.9231, 'psf_signal' => 12345.678,
    'background_mean' => 512.3, 'min_pixel' => 12, 'max_pixel' => 65535,
    'mean_pixel' => 900.1, 'median_pixel' => 880.0, 'bit_depth' => 16, 'image_channels' => 3,
    'xbinning' => 1, 'ybinning' => 1, 'egain' => 2.0, 'gain' => 1.5, 'offset' => 10,
    'xpixsz' => 3.76, 'ypixsz' => 3.76, 'set_temp' => -10.0, 'ccd_temp' => -9.8,
    'ra' => 83.822, 'dec' => -5.391, 'centalt' => 60.1, 'centaz' => 180.2, 'airmass' => 1.23,
    'objctrot' => 0.0, 'rotator_angle' => 12.5, 'siteelev' => 120.0, 'sitelat' => 0.0,
    'sitelong' => 0.0, 'equinox' => 2000.0, 'moon_angle' => 135.4, 'moon_phase' => 0.72,
    'cloudcvr' => 10.0, 'dewpoint' => 5.5, 'humidity' => 45.0, 'pressure' => 1013.2,
    'ambtemp' => 12.0, 'focallen' => 1200.0, 'focratio' => 7.0, 'focpos' => 42123,
    'focussz' => 3.5, 'foctemp' => 20.0, 'visible_duplicate_count' => 1,
    'total_duplicate_count' => 1,
];

/**
 * Synthetic row with the payload in every string column coming from the archive.
 * The list is the one the main table query selects from `files`: the
 * FITS headers plus the computed metadata.
 */
function hostileRow(array $numeric, string $xss, bool $withThumb = false): array
{
    $row = [
        'id' => 424242,
        'file_id' => 424242,
        'name' => $xss,
        'path' => 'DIR/' . $xss . '.fits',
        'object' => $xss,
        'filter' => $xss,
        'imgtype' => $xss,
        'date_obs' => '2026-03-04 05:06:07',
        'date_avg' => '2026-03-04 05:07:07',
        'file_hash' => $xss,
        'instrume' => $xss,
        'cameraid' => $xss,
        'usblimit' => $xss,
        'fwheel' => $xss,
        'telescop' => $xss,
        'focname' => $xss,
        'pierside' => $xss,
        'rotator_name' => $xss,
        'readoutm' => $xss,
        'swcreate' => $xss,
        'roworder' => $xss,
        'objctra' => $xss,
        'objctdec' => $xss,
        'siteelev' => $xss,
        'sitelat' => $xss,
        'sitelong' => $xss,
        'image_color_type' => $xss,
        'bayer_pattern' => $xss,
        'thumb' => $withThumb ? "\x89PNG\r\n\x1a\n" : null,
        'thumb_crop' => $withThumb ? "\x89PNG\r\n\x1a\n" : null,
    ];
    return array_merge($row, $numeric);
}

// Forced visibility: without empty hiddenCols showColFor would hide almost everything and the
// test would pass without having rendered any of the cells it wants to cover.
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

function render(string $scope, array $row, string $suffix = ''): string
{
    $level = ob_get_level();
    ob_start();
    try {
        renderFileTableCells($row, $scope, $suffix);
        return (string)ob_get_clean();
    } finally {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
    }
}

/**
 * The count that makes the test honest: every occurrence of the payload must be either
 * escaped as an entity or percent-encoded. A third shape means the payload
 * ended up raw in one of the two directions.
 */
function tally(string $html, string $needle): array
{
    $raw = substr_count($html, $needle . '<');
    $ent = substr_count($html, $needle . '&lt;');
    $url = substr_count($html, $needle . '%3C');
    $total = substr_count($html, $needle);
    return ['raw' => $raw, 'ent' => $ent, 'url' => $url, 'total' => $total];
}

echo "\n=== scope 'main' (home, main table) ===\n";
$row = hostileRow($numeric, $XSS);
$html = render('main', $row);

printf("  rendered %d bytes\n", strlen($html));
$t = tally($html, $NEEDLE);
printf("  occurrences of '%s': %d total = %d escaped + %d percent-encoded\n",
    $NEEDLE, $t['total'], $t['ent'], $t['url']);

// Positivity check: without a rendered payload, "no injection" would be
// true only because the row was empty.
check('the payload reached the HTML', $t['total'] > 0,
    "{$t['total']} occurrences");
check('every occurrence is escaped or percent-encoded',
    $t['ent'] + $t['url'] === $t['total'],
    "entities={$t['ent']} url={$t['url']} raw={$t['raw']}");
check('no raw occurrence', $t['raw'] === 0, "raw={$t['raw']}");

$liveTag = preg_match('#' . preg_quote($NEEDLE, '#') . '\s*<(img|svg|script)#i', $html, $m1);
check('no live tag after the payload', !$liveTag, $liveTag ? '>>> ' . $m1[0] : '');

$bad = injectedMarkup($html);
check('no injected element or handler (parser)', $bad === [],
    $bad ? implode('; ', $bad) : '');

// The attribute context of this scope: the duplicates badge carries data-hash. A
// value > 1 is needed for the badge to be rendered. The check on the badge is done on the DOM,
// not with a regex: see the note on badgeAudit().
$htmlDup = render('main', hostileRow(array_merge($numeric, [
    'visible_duplicate_count' => 3, 'total_duplicate_count' => 5]), $XSS));
$badge = badgeAudit($htmlDup);
check('the duplicates badge is there', $badge['found'], $badge['found'] ? '' : 'not found');
// In the DOM the attribute comes back DECODED: getAttribute() returns
// 'XSS<img src=x onerror=alert(1)>...', not the shape with &lt;. Looking for the escaped
// shape here would be wrong because it is the parser that already resolved it. The right invariant
// is that the value comes back identical to the starting one: proof that the payload was
// carried as a VALUE and not as markup, and that the attribute boundary is intact
// (if the escaping had eaten a separator, the value would be different).
check('  data-hash comes back identical to the original (escaping without losses)',
    $badge['hash'] === $XSS,
    $badge['hash'] === $XSS ? '' : 'value="' . $badge['hash'] . '"');
check('  and in the source it is escaped as an entity',
    str_contains($htmlDup, 'data-hash="' . $NEEDLE . '&lt;'), '');
check('  and it has no on* attributes (checked on the DOM, not with a regex)',
    $badge['handlers'] === [],
    $badge['handlers'] ? implode(', ', $badge['handlers']) : '');
check('this scope does NOT carry data-col (only the project one does)',
    !str_contains($html, 'data-col='), '');

echo "\n=== scope 'project' (projects page, integration group tables) ===\n";
$suffix = ' <span class="text-gray-500">(' . __('projects_link_off') . ')</span>';
$htmlP = render('project', $row, $suffix);
printf("  rendered %d bytes\n", strlen($htmlP));
$t = tally($htmlP, $NEEDLE);
printf("  occurrences of '%s': %d total = %d escaped + %d percent-encoded\n",
    $NEEDLE, $t['total'], $t['ent'], $t['url']);
check('the payload reached the HTML', $t['total'] > 0, "{$t['total']} occurrences");
check('every occurrence is escaped or percent-encoded',
    $t['ent'] + $t['url'] === $t['total'],
    "entities={$t['ent']} url={$t['url']} raw={$t['raw']}");

$badP = injectedMarkup($htmlP);
check('no injected element or handler (parser)', $badP === [],
    $badP ? implode('; ', $badP) : '');

// The attribute context proper to this scope: fileCellAttrs() puts the raw
// column value in data-val, so it receives archive strings and not only numbers.
check('the project scope carries data-col', str_contains($htmlP, 'data-col="'), '');
check('  and the data-val of a string column is escaped',
    (bool)preg_match('#data-val="' . preg_quote($NEEDLE, '#') . '&lt;#', $htmlP), '');
check('  and the data-col does not contain the payload',
    !preg_match('#data-col="[^"]*' . preg_quote($NEEDLE, '#') . '#', $htmlP), '');

// $nameSuffix is the only point of the partial that prints without htmlspecialchars (line 230).
// Today the only caller passes it fixed markup around a translation, so it is not
// controlled by the archive: it has to be verified, not taken for granted.
check('$nameSuffix contains only the markup and the translation of the caller',
    substr_count($htmlP, '<span class="text-gray-500">') === 1
    && str_contains($htmlP, '(' . __('projects_link_off') . ')'),
    substr_count($htmlP, '<span class="text-gray-500">') . ' hand-inserted spans');
check('  and no payload gets into it',
    !preg_match('#text-gray-500">[^<]*' . preg_quote($NEEDLE, '#') . '#', $htmlP), '');

echo "\n=== the thumbnails branch deliberately writes <img> ===\n";
// With thumb set the partial writes two <img> pointing to image.php. That is not an injection,
// so it cannot go in the list above, but their srcs have to be checked: the
// parameter is a numeric id and must not be able to become a string.
$htmlT = render('main', hostileRow($numeric, $XSS, true));
$badT = injectedMarkup($htmlT);
check('with the thumbnails there is nothing unexpected', $badT === [],
    $badT ? implode('; ', $badT) : '');
check('the two <img> point to image.php with a numeric id',
    substr_count($htmlT, '/image.php?id=424242&type=') === 2,
    substr_count($htmlT, '/image.php?id=424242&type=') . ' references');
$doc = new DOMDocument();
libxml_use_internal_errors(true);
$doc->loadHTML('<!DOCTYPE html><html><body>' . $htmlT . '</body></html>', LIBXML_NOWARNING);
libxml_clear_errors();
$xp = new DOMXPath($doc);
$srcs = [];
foreach ($xp->query('//img') as $el) {
    $srcs[] = $el->getAttribute('src');
}
check('  no src contains the payload',
    !preg_match('#' . preg_quote($NEEDLE, '#') . '#', implode(' ', $srcs)), implode(' | ', $srcs));

echo "\n--- raw bytes of the three contexts ---\n";
// data-val of the *string* column that contains the payload: the first data-val is the
// one for 'preview', empty, so it has to be searched by content and not by position.
if (preg_match('#data-val="[^"]*' . preg_quote($NEEDLE, '#') . '&lt;[^"]{0,40}#', $htmlP, $m)) {
    echo '  attribute  : ' . $m[0] . "\n";
}
if (preg_match('#href="/fits/[^"]{0,90}#', $html, $m)) {
    echo '  href       : ' . $m[0] . "\n";
}
// The name text comes AFTER the `>` that closes the <a> tag, and that tag has other attributes
// after the href (`download class="..."`), so `[^>]*` is needed to reach the boundary.
if (preg_match('#<a href="/fits/[^"]*"[^>]*>([^<]*)</a>#', $html, $m)) {
    echo '  text name  : ' . trim($m[1]) . "\n";
}
if (preg_match('#data-hash="[^"]{0,80}#', $htmlDup, $m)) {
    echo '  data-hash  : ' . $m[0] . "\n";
}

echo "\nRESULT: " . ($failed
    ? 'FAILED: ' . implode(', ', $failed)
    : 'file_cells.php escapes text, attributes and href in both scopes') . "\n";
exit($failed ? 1 : 0);
