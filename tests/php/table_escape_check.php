<?php
// Check — includes/table.php must write the archive values with htmlspecialchars in
// BOTH views.
//
// table.php is the shell of the main table and contains a second rendering of the
// same data: besides the list view (which delegates the cells to renderFileTableCells,
// already covered by file_cells_escape_check.php) there is the cards view, which
// rewrites from scratch name, path, object, filter, exptime, imgtype and date_obs.
// It is a distinct path: a fix to the list view would not touch it.
//
//   index.php -> includes/table.php   (list view + cards view, via the viewMode cookie)
//
// No writes: the rows are synthetic. The project in $projectList stays empty,
// because it would require an insert: the project names are covered by the
// htmlspecialchars on line 36, verifiable by eye but not proven here.
//
// The data is archive-controlled exactly as in file_cells.php: name and path
// are strings, the rest are numbers.
//
// Usage:
//   docker cp tmp/table_escape_check.php awi-php:/tmp/
//   docker exec awi-php sh -c 'cd /tmp && php table_escape_check.php'

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once '/var/www/html/includes/config.php';

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
    printf("  %-54s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

/**
 * Second opinion independent of the regexes, on the parsed DOM.
 *
 * Here, unlike in the other tests, the partial deliberately writes things the deny-list of
 * the other tests forbids: a <script> block (the duplicates handler) and
 * `onclick="sortTable(...)"` on the headings that sort (template_functions.php:55).
 * Banning them would produce two false positives on correct code, which is the worst way
 * to make a test fail: the signal gets lost and nobody looks further.
 *
 * So the invariant is not «no handler», which would be false here, but «no handler
 * that the template does not write itself»: `onclick` on <th> is tolerated and everything
 * else is banned. And it is checked that the payload did not end up inside the <script>,
 * which is the only place where this form of check would protect nothing: an injected
 * <script> executes even with everything else escaped.
 */
function injectedMarkup(string $html, string $needle = ''): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $bad = [];
    foreach ($xp->query('//*') as $el) {
        $tag = strtolower($el->nodeName);
        // <img> is legitimate (the thumbnails), <script> is the duplicates handler.
        if (in_array($tag, ['svg', 'iframe', 'object', 'embed', 'form'], true)) {
            $bad[] = "element <$tag> not expected by the template";
        }
        foreach ($el->attributes as $attr) {
            $an = strtolower($attr->nodeName);
            if (str_starts_with($an, 'on') && !($an === 'onclick' && $tag === 'th')) {
                $bad[] = "attribute {$attr->nodeName} on <$tag>";
            }
        }
    }
    // Only the <script> is checked in its content, and that is because there the comparison
    // makes sense: the content of a <script> is raw text, the parser does NOT resolve
    // entities inside it, so searching the payload there is informative. Not on normal
    // nodes: the DOM returns the values DECODED, and `XSS&lt;img` arrives there as text
    // `XSS<img`. A check «the payload does not appear in the text» would therefore be true
    // only for broken code, and false on everything else — the exact opposite of a test
    // (trap #50). The proof that the payload was carried as a value and not as markup is
    // already the raw/entity count done above, plus the absence of new elements and
    // handlers here.
    foreach ($xp->query('//script') as $el) {
        if ($needle !== '' && str_contains($el->textContent, $needle)) {
            $bad[] = 'the payload is inside a <script>';
        }
    }
    return array_values(array_unique($bad));
}

$XSS = 'XSS<img src=x onerror=alert(1)>"\'<svg onload=alert(2)>';
$NEEDLE = 'XSS';

// Numbers, not the payload: they are the values the indexer writes as numbers, and without
// a cast (resolution, fov_w, fov_h, file_size) a string here would raise a TypeError
// instead of injecting. The scope of this test is the escaping of strings.
$numeric = [
    'id' => 424243, 'mtime' => 1750000000, 'file_size' => 16980480,
    'width' => 9576, 'height' => 6388, 'resolution' => 2.14, 'fov_w' => 326.7, 'fov_h' => 218.0,
    'hfr' => 2.5, 'fwhm' => 3.1, 'star_count' => 1840, 'psf_signal' => 1234.5,
    'background_mean' => 512.3, 'min_pixel' => 12, 'max_pixel' => 65535,
    'mean_pixel' => 900.1, 'median_pixel' => 880.0, 'bit_depth' => 16, 'image_channels' => 3,
    'exptime' => 600.0, 'ccd_temp' => -9.8, 'xbinning' => 1, 'ybinning' => 1,
    'instrume' => 'CAM', 'cameraid' => '1', 'telescop' => 'TEL', 'ra' => 83.8, 'dec' => -5.4,
    'visible_duplicate_count' => 3, 'total_duplicate_count' => 5,
];

function hostileRow(array $numeric, string $xss): array
{
    return array_merge($numeric, [
        'name' => $xss,
        'path' => 'DIR/' . $xss . '.fits',
        'object' => $xss,
        'filter' => $xss,
        'imgtype' => $xss,
        'date_obs' => '2026-03-04 05:06:07',
        'file_hash' => $xss,
        // The two views use two different names for the same placeholder: the cards view
        // looks at $f['thumb'], the delegated list view looks at $f['thumb']. Both
        // covered, because the two branches of table.php are different.
        'thumb' => "\x89PNG\r\n\x1a\n",
        'thumb_crop' => "\x89PNG\r\n\x1a\n",
    ]);
}

// Forced visibility: without empty hiddenCols showColFor would hide almost everything and the
// test would pass without having rendered the cells it wants to cover.
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

$sortBy = 'date_obs';
$sortOrder = 'desc';

/**
 * Renders table.php.
 *
 * Note what the cookie does NOT do: `viewMode` does not choose which of the two branches
 * gets rendered, it only changes the `hidden` class of a container. The partial ALWAYS
 * prints list and cards, so the two executions below produce the same byte for
 * byte (40111 bytes in both, measured). They are not two separate renderings: they are two
 * checks that the cookie makes nothing worse. The cards view is the one with its own name
 * sink (`htmlspecialchars($f['name'])` at line 146), distinct from the one of
 * renderFileTableCells, and that is what the positional check below targets.
 */
function renderTable(string $viewMode, array $files): string
{
    $_COOKIE['viewMode'] = $viewMode;
    $_COOKIE['thumbSize'] = '3';
    $GLOBALS['files'] = $files;
    $GLOBALS['tableColspan'] = 12;
    // $conn absent: table.php does isset($conn) ? getProjects($conn) : [], so the project
    // list stays empty and the test does not touch the database.
    unset($GLOBALS['conn']);
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

$row = hostileRow($numeric, $XSS);
$files = [$row];

$baseline = null;
$baselineHtml = null;
foreach (['list' => 'viewMode=list', 'thumbnail' => 'viewMode=thumbnail'] as $mode => $desc) {
    echo "\n=== $desc ===\n";
    $html = renderTable($mode, $files);
    printf("  rendered %d bytes\n", strlen($html));

    // Positivity check: the payload must have arrived.
    $total = substr_count($html, $NEEDLE);
    $raw = substr_count($html, $NEEDLE . '<');
    $ent = substr_count($html, $NEEDLE . '&lt;');
    $url = substr_count($html, $NEEDLE . '%3C');

    // The cookie changes the output, but only the `hidden` classes: the two branches are
    // ALWAYS printed, so the invariant is not «same bytes» but «same payload occurrences,
    // and all escaped». The previous version compared the bytes and failed on
    // correct code, because `list-view hidden` is longer than `list-view `.
    $baselineHtml ??= $html;
    if ($baseline !== null) {
        $counts = [$total, $raw, $ent, $url];
        check('  the view cookie does not change how many times the payload appears',
            $counts === $baseline,
            $total . ' occurrences, same as the previous pass');
    } else {
        echo "  (first pass: no comparison with the cookie)\n";
    }
    $baseline = [$total, $raw, $ent, $url];
    printf("  occurrences of '%s': %d total = %d escaped + %d percent-encoded\n",
        $NEEDLE, $total, $ent, $url);
    check('the payload reached the HTML', $total > 0, "$total occurrences");
    check('every occurrence is escaped or percent-encoded',
        $ent + $url === $total && $raw === 0,
        "entities={$ent} url={$url} raw={$raw}");

    $liveTag = preg_match('#' . preg_quote($NEEDLE, '#') . '\s*<(img|svg|script)#i', $html, $m1);
    check('no live tag after the payload', !$liveTag, $liveTag ? '>>> ' . $m1[0] : '');

    $bad = injectedMarkup($html, $NEEDLE);
    check('no injected element, handler or payload (parser)', $bad === [],
        $bad ? implode('; ', $bad) : '');
    check('  and the partial\'s <script> does not contain the payload',
        !preg_match('#<script[^>]*>[^<]*' . preg_quote($NEEDLE, '#') . '#', $html), '');

    // Both table branches must have drawn the row, otherwise «zero
    // occurrences» could mean «zero rows».
    check('the row has been drawn', substr_count($html, 'selectable-item') > 0,
        substr_count($html, 'selectable-item') . ' selectable-item elements');
    check('  and the file checkboxes are there', substr_count($html, 'file-checkbox') > 0,
        substr_count($html, 'file-checkbox') . ' checkboxes');
}

// The two named sinks are distinct and have to be verified by position: the one of the
// cards view is proper to table.php (line 146), the other arrives from
// renderFileTableCells and is covered by file_cells_escape_check.php. Without these two
// checks a global test would pass even if only one of the two were uncovered.
echo "\n=== the two named sinks, by position ===\n";
$html = $baselineHtml;
// Cards view: inside thumb-title. The pattern hooks `class="thumb-title"` and NOT the
// word `thumb-title>`: in the HTML the closing quote of the attribute comes before
// the angle bracket, so `thumb-title>` never appears and the first version of the check
// found nothing on correct code.
$cardOk = (bool)preg_match('#class="thumb-title">\s*<a[^>]*>\s*'
    . preg_quote($NEEDLE, '#') . '&lt;#s', $html);
check('cards view: the name is escaped in its own <a>', $cardOk,
    $cardOk ? '' : 'the thumb-title block does not contain the escaped name');
// List view: inside the cell delegated to renderFileTableCells.
$listOk = (bool)preg_match('#class="p-3"[^>]*>\s*<a href="/fits/[^"]*"[^>]*>\s*'
    . preg_quote($NEEDLE, '#') . '&lt;#s', $html);
check('list view: the name is escaped in the delegated cells', $listOk, '');
// The checkboxes of both branches, with the escaped path.
check('the checkboxes of both branches have the escaped path',
    substr_count($html, 'value="' . 'DIR/' . $NEEDLE . '&lt;') === 2,
    substr_count($html, 'value="DIR/' . $NEEDLE . '&lt;') . ' checkboxes with escaped path');
check('  and neither of the two hrefs leaves the path raw',
    substr_count($html, 'href="/fits/' . 'DIR%2F' . $NEEDLE . '%3C') === 2,
    substr_count($html, 'href="/fits/DIR%2F' . $NEEDLE . '%3C') . ' percent-encoded hrefs');

// The cards view has two <img> per row (thumbnail and crop), the list view two more.
// Both must point to image.php with an integer id and no src can contain the payload.
echo "\n=== the thumbnail <img> ===\n";
foreach (['list', 'thumbnail'] as $mode) {
    $html = renderTable($mode, $files);
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $bad = [];
    $n = 0;
    foreach ($xp->query('//img') as $el) {
        $n++;
        $src = $el->getAttribute('src');
        if (!preg_match('#^/image\.php\?id=\d+&type=(thumb|crop)$#', $src)) {
            $bad[] = $src;
        }
    }
    check("viewMode=$mode: the <img> are only /image.php with an integer id",
        $n > 0 && $bad === [],
        $bad ? implode(' | ', $bad) : "$n conforming images");
}

echo "\n--- raw bytes, cards view ---\n";
$html = renderTable('thumbnail', $files);
if (preg_match('#class="file-checkbox[^"]*" value="[^"]{0,80}#', $html, $m)) {
    echo '  value= : ' . $m[0] . "\n";
}
if (preg_match('#href="/fits/[^"]{0,80}#', $html, $m)) {
    echo '  href   : ' . $m[0] . "\n";
}
if (preg_match('#thumb-title>\s*<a[^>]*>\s*[^<]{0,70}#s', $html, $m)) {
    echo '  name   : ' . trim(strip_tags($m[0])) . "\n";
}

echo "\nRESULT: " . ($failed
    ? 'FAILED: ' . implode(', ', $failed)
    : 'table.php escapes in both views') . "\n";
exit($failed ? 1 : 0);
