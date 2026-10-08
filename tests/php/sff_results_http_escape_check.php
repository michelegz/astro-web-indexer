<?php
// End-to-end check — the results table that sff.js:152 assigns to innerHTML must not
// execute a file name or a path from the archive.
//
// The gap this closes: `sff_results_table.php` had only been READ and checked by eye,
// never driven with hostile data. It is the second of the server-built HTML that reaches
// the client (the first is project_tree_preview.php, covered by
// tree_preview_escape_check.php).
//
//   sff.js:152  sffResultsPanel.innerHTML = data.html
//     <- POST /api/find_calibration_files.php  {file_id, search_type, filters}
//       -> SELECT id, name, path, date_obs, exptime, ccd_temp, xbinning, ybinning,
//                width, height, (thumb IS NOT NULL ...) AS has_thumb  FROM files ...
//       -> render_sff_results_table($results)
//       -> JSON field 'html'
//
// The archive-controlled values that reach the table are two strings:
// `name` (varchar 255) and `path` (varchar 768). The rest are numbers, and with numbers this
// table cannot be injected anyway: the proof of the real path is for `name`
// and `path`, which are also the two that end up in an href and in a value=.
//
// The test files do not go on disk: two rows in `files`, deleted by exact id.
// No reindex, nothing in /var/fits.
//
// One of the two rows deliberately has `date_obs` NULL. `sff_results_table.php:58` does
// `substr($file['date_obs'], 0, 10)` without checking the NULL, and in PHP 8.1+ substr() on
// NULL is deprecated. The archive of this copy has no LIGHT without date_obs, but
// reindex.py sets date_obs = NULL when DATE-OBS is not parseable, so the branch
// exists. Here it is not fixed and not declared a defect: what is OBSERVED is what reaches the
// client, and the verdict is given by the response body.
//
// Hygiene (traps #44, #46): a single connection, low lock wait, DELETE by id, and a
// check that the site answers before and after.
//
// Usage:
//   docker cp tmp/sff_results_http_escape_check.php awi-php:/tmp/
//   docker exec awi-php sh -c 'cd /tmp && php sff_results_http_escape_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

$base = 'http://nginx';
$failed = [];
$userTag = 'sffres_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = null;
$ids = [];
$jar = '/tmp/sffres_' . bin2hex(random_bytes(4));

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-54s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

$XSS = 'XSS<img src=x onerror=alert(1)>"\'<svg onload=alert(2)>';
$NEEDLE = 'XSS';

function httpGet(string $url, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

function httpPost(string $url, string $jar, $payload, bool $form = false): array
{
    // login.php reads from $_POST urlencoded: with an array and CURLOPT_POSTFIELDS it would
    // be multipart and the login would fail silently (traps #7 and #16).
    $body = $form ? http_build_query((array)$payload) : json_encode($payload);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . ($form
            ? 'application/x-www-form-urlencoded' : 'application/json')],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

/**
 * Second opinion independent of the regexes, on the parsed DOM. Here the template
 * deliberately writes <img> when there is a thumbnail, so the list is a DENY-LIST as in
 * file_cells_escape_check.php, and the legitimate <img> are checked separately.
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
                $bad[] = "attribute {$attr->nodeName} on <$tag>";
            }
        }
    }
    return array_values(array_unique($bad));
}

/** How many <img> there are and what they point to: they must only be /image.php with an integer id. */
function thumbAudit(string $html): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $srcs = [];
    foreach ($xp->query('//img') as $el) {
        $srcs[] = $el->getAttribute('src');
    }
    return $srcs;
}

$conn = connectDB();
// PDO on MySQL has autocommit on, so no stale REPEATABLE READ snapshot
// (trap #44, which is a mysql.connector problem). The low lock wait stays.
$conn->exec("SET SESSION innodb_lock_wait_timeout = 5");

$filesBefore = (int)$conn->query('SELECT COUNT(*) FROM files')->fetchColumn();
[$sHome0] = httpGet("$base/projects.php", $jar);
echo "=== before ===\n";
echo "  rows in the files table: $filesBefore\n";
check('the site answers before starting', !str_starts_with((string)$sHome0, '5'),
    "HTTP $sHome0");

try {
    // ---------------------------------------------------------------
    // Session and POSITIVITY CHECK BEFORE inserting the hostile rows.
    //
    // The order is essential and not a detail. With `filters` empty the search has
    // `imgtype = 'LIGHT'` as its only WHERE, so it returns the WHOLE archive: the first
    // version of this test inserted the hostile rows and then did the check on a
    // "real" LIGHT, and that check also returned the rows just created. The
    // check was therefore polluted and reported as a defect that its own
    // payload ended up in the response. The check must be done on a clean archive.
    // ---------------------------------------------------------------
    $uid = (int)createUser($conn, $userTag, $plain, false, true, ['/']);
    [$s, $loginHtml] = httpGet("$base/login.php", $jar);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $loginHtml, $m);
    [$sLogin,] = httpPost("$base/login.php", $jar, ['username' => $userTag,
        'password' => $plain, 'csrf_token' => $m[1] ?? ''], true);
    // projects.php answers 302 even without a session: the signal is the login form
    // inside the body (trap #3).
    [$sHome, $home] = httpGet("$base/projects.php", $jar);
    check('session established', !str_contains($home, 'name="password"'),
        "login HTTP $sLogin, projects.php HTTP $sHome");

    $realId = (int)$conn->query("SELECT id FROM files WHERE imgtype = 'LIGHT'
        AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    [$sOk, $okBody] = httpPost("$base/api/find_calibration_files.php", $jar,
        ['file_id' => $realId, 'search_type' => 'lights', 'filters' => []]);
    $okJson = json_decode($okBody, true);
    $okHtml = (string)($okJson['html'] ?? '');
    check('a real LIGHT gives 200 with JSON and html (positivity check)',
        $sOk === 200 && is_array($okJson) && strlen($okHtml) > 0,
        "HTTP $sOk, html " . strlen($okHtml) . ' bytes');
    check('  and its html does not contain the payload (archive still clean)',
        !str_contains($okBody, $NEEDLE), '');
    check('  and the table has the result checkboxes',
        substr_count($okHtml, 'sff-file-checkbox') > 1,
        substr_count($okHtml, 'sff-file-checkbox') . ' checkboxes');

    // ---------------------------------------------------------------
    // Now the test rows. The first has the thumbnail (to cover the has_thumb branch,
    // which writes an <img>) and a valid date_obs. The second has date_obs NULL on
    // purpose: substr(NULL, 0, 10) is deprecated in PHP 8.1+ and it is what we want to observe.
    //
    // `path` carries the payload as much as `name` does: it is the column that ends up in the
    // checkbox value= and in the /fits/ href, and it is the only one of the two that goes through
    // rawurlencode instead of htmlspecialchars. With a clean path those two contexts had
    // nothing to check and the checks failed for absence of payload, not because of a defect.
    // ---------------------------------------------------------------
    $marker = 'sffres_' . bin2hex(random_bytes(4));
    $ins = $conn->prepare(
        'INSERT INTO files (path, name, imgtype, object, `filter`, instrume, cameraid, '
        . 'exptime, ccd_temp, xbinning, ybinning, date_obs, file_hash, mtime, file_size, thumb) '
        . 'VALUES (:path, :name, :imgtype, :object, :filter, :instrume, :cameraid, '
        . ':exptime, :ccd_temp, :xb, :yb, :date_obs, :hash, :mtime, :size, :thumb)'
    );
    $rows = [
        ['path' => $marker . '/LIGHT/' . $XSS . 'a.fits', 'name' => $XSS,
         'date' => '2026-03-04 05:06:07', 'thumb' => "\x89PNG\r\n\x1a\n"],
        ['path' => $marker . '/LIGHT/' . $XSS . 'b.fits', 'name' => $XSS,
         'date' => null, 'thumb' => null],
    ];
    foreach ($rows as $r) {
        $ins->execute([
            ':path' => $r['path'], ':name' => $r['name'], ':imgtype' => 'LIGHT',
            ':object' => $r['name'], ':filter' => 'R', ':instrume' => 'PROBE',
            ':cameraid' => 'PROBE', ':exptime' => 60.0, ':ccd_temp' => -10.0,
            ':xb' => 1, ':yb' => 1, ':date_obs' => $r['date'],
            ':hash' => substr(md5($r['path']), 0, 16), ':mtime' => time(),
            ':size' => 1024, ':thumb' => $r['thumb'],
        ]);
        $ids[] = (int)$conn->lastInsertId();
    }
    $refId = $ids[0];
echo "\n=== test rows inserted ===\n";
    check('two rows inserted with imgtype=LIGHT', count($ids) === 2 && min($ids) > 0,
        'id=' . implode(',', $ids));

    // ---------------------------------------------------------------
    // The real case: the search with the hostile row as the reference.
    // Empty filters => no additional WHERE, so all the LIGHTs come back, and the
    // reference row is marked is_reference and put on top.
    // ---------------------------------------------------------------
    echo "\n=== hostile search ===\n";
    [$sHostile, $body] = httpPost("$base/api/find_calibration_files.php", $jar,
        ['file_id' => $refId, 'search_type' => 'lights', 'filters' => []]);
    printf("  HTTP %d, %d bytes of response\n", $sHostile, strlen($body));
    check('the response is 200', $sHostile === 200, "HTTP $sHostile");

    // ---------------------------------------------------------------
    // Regression for the defect in sff_results_table.php:58.
    //
    // One of the two rows has date_obs NULL. substr(NULL, 0, 10) is deprecated in PHP 8.1+,
    // and the diagnostic was printed INSIDE the buffer wrapping the partial, so it
    // ended up in the 'html' field and the client displayed it: the user read
    // "Deprecated: substr(): Passing null ..." in the panel, with the server's absolute path
    // and the line number. The JSON stayed valid, so it did not show up from the
    // failure of the parse: you had to look at the field.
    //
    // The JSON must also stay valid: if a diagnostic ended up BEFORE the JSON, the
    // client would get a SyntaxError instead of an actionable error.
    $json = json_decode($body, true);
    check('the body is valid JSON', is_array($json), json_last_error_msg());
    $html = (string)($json['html'] ?? '');
    printf("  html: %d bytes, count=%s\n", strlen($html), (string)($json['count'] ?? '?'));

    $diag = preg_match('#(Deprecated|Warning|Notice|Fatal error|Stack trace)#i', $body, $dm);
    check('no PHP diagnostic in the response body', !$diag,
        $diag ? '>>> ' . strip_tags(substr($dm[0], 0, 120)) : '');
    check('  and none in the html field that the client assigns to innerHTML',
        !preg_match('#(Deprecated|Warning|Notice|Fatal error)#i', $html), '');
// On the raw body this check would be VACUOUS: json_encode escapes slashes into '\/',
// so '/var/www/html/' never appears there, not even when the diagnostic is present. It must
// be checked on the decoded html field, which is what the client really sees.
check('  and no absolute server path exposed',
    !str_contains($html, '/var/www/html/'), '');

    // The row without date_obs must render like its neighbours, which print 'N/A'.
    check('the row with date_obs NULL shows N/A, like the nearby cells',
        substr_count($html, '>N/A<') > 1,
        substr_count($html, '>N/A<') . ' N/A cells');

    $raw = substr_count($html, $NEEDLE . '<');
    $ent = substr_count($html, $NEEDLE . '&lt;');
    $url = substr_count($html, $NEEDLE . '%3C');
    $total = substr_count($html, $NEEDLE);
    printf("  occurrences of '%s': %d total = %d escaped + %d percent-encoded + %d raw\n",
        $NEEDLE, $total, $ent, $url, $raw);
    check('the payload reached the html', $total > 0, "$total occurrences");
    check('every occurrence is escaped or percent-encoded',
        $ent + $url === $total && $raw === 0,
        "entities={$ent} url={$url} raw={$raw}");

    $liveTag = preg_match('#' . preg_quote($NEEDLE, '#') . '\s*<(img|svg|script)#i', $html, $m1);
    check('no live tag after the payload', !$liveTag, $liveTag ? '>>> ' . $m1[0] : '');

    $bad = injectedMarkup($html);
    check('no injected element or handler (parser)', $bad === [],
        $bad ? implode('; ', $bad) : '');

    // The two contexts specific to this table: href with rawurlencode and value= with
    // htmlspecialchars.
    check('the checkbox value= is escaped',
        (bool)preg_match('#class="sff-file-checkbox" value="[^"]*' . preg_quote($NEEDLE, '#') . '&lt;#', $html), '');
    check('the /fits/ href is percent-encoded',
        (bool)preg_match('#href="/fits/[^"]*' . preg_quote($NEEDLE, '#') . '%3C#', $html), '');
    check('the file name is escaped in the text',
        (bool)preg_match('#download>' . preg_quote($NEEDLE, '#') . '&lt;#', $html), '');

    // The has_thumb branch deliberately writes <img>: that is not an injection, but its src
    // must remain an integer id. The previous version passed the literal '#' as the
    // third argument of preg_match, which wants a reference: `Argument #3 ($matches)
    // could not be passed by reference`, and the check died instead of verifying.
    $srcs = thumbAudit($html);
    $badSrc = [];
    foreach ($srcs as $s) {
        if (!preg_match('#^/image\.php\?id=\d+&type=(thumb|crop)$#', $s)) {
            $badSrc[] = $s;
        }
    }
    check('the <img> are only /image.php with an integer id',
        $srcs !== [] && $badSrc === [],
        $badSrc ? implode(' | ', array_slice($badSrc, 0, 3))
                : count($srcs) . ' images, all conforming');
    check('  and no src contains the payload',
        !preg_match('#' . preg_quote($NEEDLE, '#') . '#', implode(' ', $srcs)), '');

    // The two test rows must both be in the table: without this, «zero
    // occurrences» could mean «zero rows».
    check('both test rows are in the table',
        substr_count($html, 'sff-file-checkbox') >= 2,
        substr_count($html, 'sff-file-checkbox') . ' checkboxes');

    echo "\n--- raw bytes of the three contexts ---\n";    if (preg_match('#class="sff-file-checkbox" value="[^"]{0,80}#', $html, $m)) {
        echo '  value=  : ' . $m[0] . "\n";
    }
    if (preg_match('#href="/fits/[^"]{0,90}#', $html, $m)) {
        echo '  href    : ' . $m[0] . "\n";
    }
    if (preg_match('#download>[^<]{0,80}#', $html, $m)) {
        echo '  name    : ' . $m[0] . "\n";
    }

} catch (Throwable $e) {
    printf("  EXCEPTION %s: %s\n", get_class($e), $e->getMessage());
    $failed[] = 'exception';
} finally {
    echo "\n=== cleanup ===\n";
    foreach ($ids as $id) {
        $conn->prepare('DELETE FROM files WHERE id = :id')->execute([':id' => $id]);
    }
    echo '  removed ' . count($ids) . " files rows\n";
    if ($uid !== null) {
        $conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
        $conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
        echo "  removed the test user\n";
    }
    @unlink($jar);

    $filesAfter = (int)$conn->query('SELECT COUNT(*) FROM files')->fetchColumn();
    check('files back to the initial count', $filesAfter === $filesBefore,
        $filesAfter === $filesBefore ? '' : "before $filesBefore, now $filesAfter");
    $left = (int)$conn->query("SELECT COUNT(*) FROM files WHERE instrume = 'PROBE'")->fetchColumn();
    check('no leftover with the payload', $left === 0, "leftovers: $left");

    [$sHome2] = httpGet("$base/projects.php", $jar);
    check('the site answers afterwards too', !str_starts_with((string)$sHome2, '5'),
        "HTTP $sHome2");
}

echo "\nRESULT: " . ($failed
    ? 'FAILED: ' . implode(', ', $failed)
    : 'the archive name and path cannot execute code in the SFF results') . "\n";
exit($failed ? 1 : 0);
