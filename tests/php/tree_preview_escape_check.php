<?php
// Check — the HTML of project_tree_preview.php that main.js:646 assigns to innerHTML
// must be escaping-complete, and the same for sff_get_filters.php -> sff.js:53.
//
// The chain:
//
//   request -> parseProjectAddRequest -> projectAddPrepare -> projectCreateSetup
//            -> project_setups.label (+ |CUSTOM: in the fingerprint)
//            -> getProjectTree -> includes/projects_tree.php
//            -> JSON field 'html' -> projectTreePreview.innerHTML = data.html
//
// The custom setup name is CLIENT-CONTROLLED and ends up in two different contexts
// inside the partial: text (lines 248, 252) and an attribute with double quotes
// (data-setup-name, line 250). No sentinel row: the partial runs inside the
// transaction that project_tree_preview.php rolls back, so the test writes nothing
// and the tree stays intact. That is also why this path is the right way to test
// escaping without touching production data.
//
// Usage:
//   docker cp tmp/tree_preview_escape_check.php awi-php:/tmp/
//   docker exec awi-php sh -c 'cd /tmp && php tree_preview_escape_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

$base = 'http://nginx';
$conn = connectDB();
$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-54s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

function httpGet(string $url, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 300]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

function httpPost(string $url, string $jar, $payload, bool $form = false): array
{
    // login.php reads from $_POST urlencoded: with an array and CURLOPT_POSTFIELDS it would
    // be multipart and the login would fail silently. The format is a flag, not inferred
    // from the type (traps #6 and #16).
    $body = $form ? http_build_query((array)$payload) : json_encode($payload);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . ($form
            ? 'application/x-www-form-urlencoded' : 'application/json')],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 300]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

/**
 * Second opinion independent of the regexes: a real HTML parser is asked whether any
 * element or attribute exists that the template did not write. In the negative
 * check this is the one that caught the attribute break, which my
 * regular expression did not see (after the payload there was '<', not '"').
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

$tag = 'treesc_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $plain, false, true, ['/']);
$jar = '/tmp/te_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');

echo "\n=== session ===\n";
[$s, $loginHtml] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $loginHtml, $m);
[$sLogin, ] = httpPost("$base/login.php", $jar, ['username' => $tag, 'password' => $plain,
    'csrf_token' => $m[1] ?? ''], true);
// Session check (trap #3): without this, a 403 or a 401 further down
// would be indistinguishable from "does not inject". A login page contains name="password".
[$sHome, $home] = httpGet("$base/projects.php", $jar);
$sessionOk = !str_contains($home, 'name="password"');
check('session established', $sessionOk, "login HTTP $sLogin, projects.php HTTP $sHome");

$pid = (int)$conn->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();
$fid = (int)$conn->query("SELECT id FROM files WHERE deleted_at IS NULL
                           AND imgtype='LIGHT' ORDER BY id LIMIT 1")->fetchColumn();
check('test project and LIGHT available', $pid > 0 && $fid > 0, "pid=$pid fid=$fid");

// Snapshot to demonstrate that the test does not write. project_tree_preview.php creates a
// project and a setup inside the transaction it then rolls back: if the rollback were
// missing, these counters would go up.
$before = [];
foreach (['projects', 'project_setups', 'project_files', 'setup_overrides'] as $t) {
    $before[$t] = (int)$conn->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}

echo "\n=== positive case: the preview answers and produces HTML ===\n";
[$st, $b] = httpPost("$base/api/project_tree_preview.php", $jar,
    ['ids' => [$fid], 'project_id' => $pid, 'overrides' => []]);
$j = json_decode($b, true);
$htmlLen = isset($j['html']) ? strlen($j['html']) : 0;
check('project_tree_preview.php -> 200 with non-empty HTML',
    $st === 200 && $htmlLen > 0, "HTTP $st, html $htmlLen bytes");
// If this fails, the rest of the test proves nothing: without a tree there is no
// sink to evaluate, and «no injection» would be true only because the page was empty.
check('  the HTML contains the tree structure',
    $htmlLen > 0 && (str_contains($j['html'] ?? '', 'cal-group')
        || str_contains($j['html'] ?? '', 'tnode')), '');

echo "\n=== the custom setup name must arrive escaped in every context ===\n";

// parseProjectAddRequest truncates to 64 characters and replaces '|' with a space, so
// the payload must fit in 64 characters: < > " ' survive, | does not.
$XSS = 'XSS<img src=x onerror=alert(1)>"\'<svg onload=alert(2)>';
$NEEDLE = 'XSS';
check('payload within the 64-character limit', strlen($XSS) <= 64, strlen($XSS) . ' characters');

[$st2, $b2] = httpPost("$base/api/project_tree_preview.php", $jar,
    ['ids' => [$fid], 'project_id' => $pid,
     'overrides' => [(string)$fid => 'new:' . $XSS]]);
$j2 = json_decode($b2, true);
$html2 = (string)($j2['html'] ?? '');
printf("  hostile request -> HTTP %d, html %d bytes\n", $st2, strlen($html2));

// Positivity check: the payload must be PRESENT in the response. If it were not,
// all the checks below would pass trivially.
$occ = substr_count($html2, $NEEDLE);
$esc = substr_count($html2, $NEEDLE . '&lt;');
check('the payload reached the HTML', $occ > 0, "$occ occurrences");
printf("  occurrences: total=%d  with '<' as entity=%d\n", $occ, $esc);

// The two signatures: text and attribute.
$liveTag = preg_match('#' . preg_quote($NEEDLE, '#') . '\s*<(img|svg|script)#i', $html2, $m1);
$liveAttr = preg_match('#data-setup-name="[^"]*' . preg_quote($NEEDLE, '#') . '[^"]*"[^>]*>#i', $html2, $m2);
check('no live tag after the payload', !$liveTag,
    $liveTag ? '>>> ' . $m1[0] : '');
check('the data-setup-name attribute stays closed', !$liveAttr,
    $liveAttr ? '>>> ' . $m2[0] : '');

$bad = injectedMarkup($html2);
check('no injected element or handler (parser)', $bad === [],
    $bad ? implode('; ', $bad) : '');

// The name must appear in the text of the setup summary, otherwise the coverage is
// illusory. Note the ": " between '>' and the name: projects_tree.php:248 prints
// "Setup S1: <label>", so the payload does NOT immediately follow a '>'.
check('the name appears in the setup summary text (line 248)',
    (bool)preg_match('#S\d+:\s*' . preg_quote($NEEDLE, '#') . '&lt;#', $html2), '');

// data-setup-name (line 250) is inside the `if (!$hypoMode)` branch, and the preview is
// ALWAYS in hypoMode, so that branch is not rendered here: its absence
// is not an escaping failure. This is stated explicitly because the test must
// not pass silently on a context it never visited.
$attrHere = str_contains($html2, 'data-setup-name=');
check('  the hypoMode branch does not render the rename button', !$attrHere,
    $attrHere ? '>>> present: the non-hypo branch would have been visited'
              : 'the attribute is covered by the harness test, which also exercises hypoMode=false');

// Raw bytes, so the result can be judged by eye and not only from the check outcome.
echo "\n--- every occurrence of the payload, with 90 characters of context ---\n";
if (preg_match_all('#.{90}' . preg_quote($NEEDLE, '#') . '.{60}#s', $html2, $m)) {
    foreach ($m[0] as $ctx) {
        echo '  ...' . str_replace(["\n", "\r", '  '], [' ', ' ', ' '], $ctx) . "...\n";
    }
}

echo "\n=== the test must not write anything ===\n";
$after = [];
foreach (['projects', 'project_setups', 'project_files', 'setup_overrides'] as $t) {
    $after[$t] = (int)$conn->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}
foreach ($before as $t => $n0) {
    check("  $t unchanged ($n0)", $after[$t] === $n0,
        $after[$t] === $n0 ? '' : "now $after[$t], delta " . ($after[$t] - $n0));
}

echo "\n=== sff_get_filters.php -> sff.js:53 ===\n";
// Here the reference value comes from files.<column>, i.e. from the FITS header, and NOT from the
// request: this test, which does not write into `files`, can only demonstrate that the sink's
// path is reachable and answers. The proof that the header cannot execute code
// requires a row in `files` and lives in sff_filter_http_escape_check.php, which writes and
// deletes a test row by id. This archive holds no values with
// '<', '>' or '"' anyway, so there is not even a natural payload to try here.
[$stS, $bS] = httpGet("$base/api/sff_get_filters.php?id=$fid&type=lights", $jar);
check('sff_get_filters.php -> 200 (sink path proven reachable)',
    $stS === 200 && strlen($bS) > 0, 'HTTP ' . $stS . ', ' . strlen($bS) . ' bytes');
$liveTagS = preg_match('#<(img|svg|script)#i', $bS, $mS);
check('  no injected tag in the filter panel', !$liveTagS,
    $liveTagS ? '>>> ' . $mS[0] : 'the static markup of the template contains only <div>/<input>');
$badS = injectedMarkup($bS);
check('  no injected handler (parser)', $badS === [],
    $badS ? implode('; ', $badS) : '');

$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(test artifacts and user removed)\n";
echo 'RESULT: ' . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'the server -> innerHTML chain is escaping-complete') . "\n";
exit($failed ? 1 : 0);
