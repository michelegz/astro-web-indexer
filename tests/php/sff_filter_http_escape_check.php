<?php
// End-to-end check — the filter panel that sff.js:53 assigns to innerHTML must not
// execute a FITS header.
//
// The gap this closes: the reference value of render_sff_filter() comes from
// files.<column>, i.e. from the FITS header of a file in the archive. There is no way to
// render it controllable by the client without writing a row into `files`, so until now
// the check only existed at harness level (sff_filter_escape_check.php, which drives the
// function with a synthetic value). Here the same claim is proven on the real path:
//
//   GET /api/sff_get_filters.php?id=<id>&type=lights
//     -> SELECT * FROM files WHERE id = :id AND imgtype = 'LIGHT'
//     -> render_sff_filter($config, $referenceFile[$key])   for each active key
//     -> echo, with Content-Type: text/html
//     -> sff.js:53  sffFiltersPanel.innerHTML = html
//
// The test file is NOT written to disk: only a row in `files`, which is then
// deleted by id. No reindex, no file in the archive, no other row touched.
//
// Hygiene (traps #44, #46): a single connection, autocommit, low lock wait, DELETE by
// exact id, and a check that the site answers before and after.
//
// Usage:
//   docker cp tmp/sff_filter_http_escape_check.php awi-php:/tmp/
//   docker exec awi-php sh -c 'cd /tmp && php sff_filter_http_escape_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

$base = 'http://nginx';
$failed = [];
$userTag = 'sffesc_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = null;
$rowId = null;
$jar = '/tmp/sffesc_' . bin2hex(random_bytes(4));

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-54s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

/**
 * The payload in the three contexts the template writes: text, attribute with double
 * quotes, attribute with single quotes.
 */
$XSS = 'XSS<img src=x onerror=alert(1)>"\'<svg onload=alert(2)>';
$NEEDLE = 'XSS';

// The `filter` column is varchar(50) and sql_mode has STRICT_TRANS_TABLES, so the short
// copy MUST fit in 50 characters: with a longer string the INSERT dies with
// `1406 Data too long` and the test would measure the database constraint instead
// of the escaping. That is why it has its own marker (Z9): without it, it would be
// indistinguishable from the other three copies, which the counts below only tally by
// $NEEDLE.
$XSS_F = 'Z9<img onerror=alert(1)>"\'<svg onload=alert(2)>';

function httpGet(string $url, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
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
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

/**
 * Second opinion independent of the regexes: in the parsed DOM no dangerous element and
 * no on* attribute. Here the template only writes <div>, <label>, <span>, <input>, so the
 * deny-list is enough; and unlike file_cells, here no <img> is legitimate.
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

$conn = connectDB();
// PDO on MySQL has autocommit on by default, so the REPEATABLE READ snapshot that bites
// mysql.connector (trap #44) is not a problem here: that one is a Python driver issue,
// not this one. The low lock wait stays, though: if this probe touched
// contended rows, the 50-second default would block the site too instead of failing
// the test.
$conn->exec("SET SESSION innodb_lock_wait_timeout = 5");

$filesBefore = (int)$conn->query('SELECT COUNT(*) FROM files')->fetchColumn();
[$sHome0, $home0] = httpGet("$base/projects.php", $jar);
echo "=== before ===\n";
echo "  rows in the files table: $filesBefore\n";
// Any response that is not 5xx: the site is up, and not blocked by the database.
check('the site answers before starting', !str_starts_with((string)$sHome0, '5'),
    "HTTP $sHome0");

try {
    // ---------------------------------------------------------------
    // Test row. `path` and `name` are NOT NULL; `imgtype` must be exactly
    // 'LIGHT', otherwise the endpoint's WHERE does not find it and the test would measure a
    // 404 instead of the escaping (same lesson as trap #31: check the real column
    // and the real value, do not infer them).
    // ---------------------------------------------------------------
    check('the short payload fits in filter varchar(50)', strlen($XSS_F) <= 50,
        strlen($XSS_F) . ' characters');
    $marker = 'sffesc_' . bin2hex(random_bytes(4));
    $st = $conn->prepare(
        'INSERT INTO files (path, name, imgtype, object, `filter`, instrume, cameraid, '
        . 'exptime, ccd_temp, xbinning, ybinning, date_obs, file_hash, mtime, file_size) '
        . 'VALUES (:path, :name, :imgtype, :object, :filter, :instrume, :cameraid, '
        . ':exptime, :ccd_temp, :xb, :yb, :date_obs, :hash, :mtime, :size)'
    );
    $st->execute([
        ':path' => $marker . '/LIGHT/probe.fits',
        ':name' => 'probe.fits',
        ':imgtype' => 'LIGHT',
        ':object' => $XSS,
        ':filter' => $XSS_F,
        ':instrume' => $XSS,
        ':cameraid' => $XSS,
        ':exptime' => 60.0,
        ':ccd_temp' => -10.0,
        ':xb' => 1,
        ':yb' => 1,
        ':date_obs' => '2026-03-04 05:06:07',
        ':hash' => str_repeat('a', 16),
        ':mtime' => time(),
        ':size' => 1024,
    ]);
    $rowId = (int)$conn->lastInsertId();
    echo "\n=== test row inserted ===\n";
    check('row inserted with imgtype=LIGHT', $rowId > 0, "id=$rowId");

    // ---------------------------------------------------------------
    // Real session: login with a test user and the CSRF token.
    // ---------------------------------------------------------------
    $uid = (int)createUser($conn, $userTag, $plain, false, true, ['/']);
    [$s, $loginHtml] = httpGet("$base/login.php", $jar);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $loginHtml, $m);
    [$sLogin,] = httpPost("$base/login.php", $jar, ['username' => $userTag,
        'password' => $plain, 'csrf_token' => $m[1] ?? ''], true);

    // Session check (trap #3): projects.php answers 302 even without a
    // session, so the signal is the login form inside the body, not the status.
    [$sHome, $home] = httpGet("$base/projects.php", $jar);
    check('session established', !str_contains($home, 'name="password"'),
        "login HTTP $sLogin, projects.php HTTP $sHome");

    // ---------------------------------------------------------------
    // Positivity check: the same endpoint, on the FIRST real LIGHT file of the archive,
    // must answer 200 with non-empty HTML. Without this, «the payload is not injected»
    // would be true even if the endpoint had returned nothing.
    // ---------------------------------------------------------------
    $realId = (int)$conn->query("SELECT id FROM files WHERE imgtype = 'LIGHT'
        AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    [$sOk, $okBody] = httpGet("$base/api/sff_get_filters.php?id=$realId&type=lights", $jar);
    check('a real LIGHT gives 200 with non-empty HTML (positivity check)',
        $sOk === 200 && strlen($okBody) > 0,
        "HTTP $sOk, " . strlen($okBody) . ' bytes');
    check('  and its panel does not contain the payload',
        !str_contains($okBody, $NEEDLE), '');

    // ---------------------------------------------------------------
    // The real case.
    // ---------------------------------------------------------------
    echo "\n=== hostile request ===\n";
    [$sHostile, $body] = httpGet("$base/api/sff_get_filters.php?id=$rowId&type=lights", $jar);
    printf("  HTTP %d, %d bytes\n", $sHostile, strlen($body));
    check('the test row gives 200', $sHostile === 200, "HTTP $sHostile");

    // Payload occurrences: they must all be entities. There is no href with
    // rawurlencode here, so the shapes are two: raw and entity.
    $raw = substr_count($body, $NEEDLE . '<');
    $ent = substr_count($body, $NEEDLE . '&lt;');
    $total = substr_count($body, $NEEDLE);
    printf("  occurrences of '%s': %d total = %d escaped + %d raw\n",
        $NEEDLE, $total, $ent, $raw);
    check('the payload reached the HTML', $total > 0, "$total occurrences");
    check('every occurrence is escaped as an entity', $ent === $total && $raw === 0,
        "entities={$ent} raw={$raw}");

    $liveTag = preg_match('#' . preg_quote($NEEDLE, '#') . '\s*<(img|svg|script)#i', $body, $m1);
    check('no live tag after the payload', !$liveTag, $liveTag ? '>>> ' . $m1[0] : '');

    $bad = injectedMarkup($body);
    check('no injected element or handler (parser)', $bad === [],
        $bad ? implode('; ', $bad) : '');

    // Positional check of the two contexts, not just a global one (trap #34/#50).
    check('the reference text is escaped (line 37 of the template)',
        str_contains($body, 'text-gray-300">' . $NEEDLE . '&lt;'), '');
    check('the hidden value= is escaped (line 38 of the template)',
        str_contains($body, 'class="sff-reference-value" value="' . $NEEDLE . '&lt;'), '');
    // The slider branch must have been rendered, otherwise the two checks above
    // would only cover the toggle and the test would pass without the rest of the template.
    check('the slider branch was rendered',
        substr_count($body, 'sff-filter-slider') > 0,
        substr_count($body, 'sff-filter-slider') . ' sliders');

    // The short one, the row that sits in filter varchar(50): it has its own marker (Z9),
    // so it is checked whole and not through $NEEDLE, which would not contain it.
    $rawF = substr_count($body, 'Z9<');
    $entF = substr_count($body, 'Z9&lt;');
    printf("  occurrences of 'Z9' (filter column): %d total = %d escaped + %d raw\n",
        $rawF + $entF, $entF, $rawF);
    check('the short payload (filter column) reached the HTML', ($rawF + $entF) > 0,
        ($rawF + $entF) . ' occurrences');
    check('  and it is escaped as an entity', $entF === $rawF + $entF && $rawF === 0,
        "entities={$entF} raw={$rawF}");

    echo "\n--- raw bytes of the two contexts ---\n";
    if (preg_match('#text-gray-300">[^<]{0,90}#', $body, $m)) {
        echo '  text      : ' . $m[0] . "\n";
    }
    if (preg_match('#class="sff-reference-value" value="[^"]{0,90}#', $body, $m)) {
        echo '  attribute : ' . $m[0] . "\n";
    }

} catch (Throwable $e) {
    printf("  EXCEPTION %s: %s\n", get_class($e), $e->getMessage());
    $failed[] = 'exception';
} finally {
    echo "\n=== cleanup ===\n";
    // By exact id, not with a very broad LIKE: the endpoint's WHERE uses the id, so
    // it is the same identity as the record I created.
    if ($rowId !== null) {
        $conn->prepare('DELETE FROM files WHERE id = :id')->execute([':id' => $rowId]);
        printf("  removed the files row id=%d (%d rows)\n", $rowId, $conn->query('SELECT ROW_COUNT()')->fetchColumn());
    }
    if ($uid !== null) {
        $conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
        $conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
        echo "  removed the test user\n";
    }
    @unlink($jar);

    $filesAfter = (int)$conn->query('SELECT COUNT(*) FROM files')->fetchColumn();
    check('files back to the initial count', $filesAfter === $filesBefore,
        $filesAfter === $filesBefore ? '' : "before $filesBefore, now $filesAfter");
    $left = (int)$conn->query("SELECT COUNT(*) FROM files WHERE object LIKE '%onerror=%'
        OR instrume LIKE '%onerror=%'")->fetchColumn();
    check('no leftover with the payload', $left === 0, "leftovers: $left");

    [$sHome2, ] = httpGet("$base/projects.php", $jar);
    check('the site answers afterwards too', !str_starts_with((string)$sHome2, '5'),
        "HTTP $sHome2");
}

echo "\nRESULT: " . ($failed
    ? 'FAILED: ' . implode(', ', $failed)
    : 'a FITS header cannot execute code in the filter panel, on real HTTP') . "\n";
exit($failed ? 1 : 0);
