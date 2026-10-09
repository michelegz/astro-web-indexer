<?php
// Check §1 — the ZIP export must respect can_download like download.php does.
//
// It creates a test user without download, really logs in via login.php and then
// calls the endpoint. The user is removed at the end.
//
// Usage:  docker cp tmp/zip_guard_check.php awi-php:/var/www/html/
//         docker exec awi-php php /var/www/html/zip_guard_check.php

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

$base = 'http://nginx';
$conn = connectDB();

// The whole assertion is about can_download, and canDownload() returns true
// unconditionally when auth is off. With AUTH_MODE=none isAuthEnabled() is false,
// so the guard this test looks for is deliberately bypassed and the ZIP is served
// with 200: reporting that as a missing guard would blame the endpoint for the
// documented behaviour of the 'none' mode. Same guard as §13.39 in
// security_batch_check.php: skip with a note, assert for real under AUTH_MODE=full.
if (!isAuthEnabled()) {
    $mode = defined('AUTH_MODE') ? AUTH_MODE : 'unset';
    echo "\n=== the ZIP export respects can_download ===\n";
    echo "  SKIPPED: needs AUTH_MODE=full, this deployment has '$mode'.\n";
    echo "  With permissions off canDownload() returns true by design, so the\n";
    echo "  endpoint is meant to serve the ZIP. Re-run with AUTH_MODE=full to cover it.\n";
    echo "\nRESULT: skipped, needs AUTH_MODE=full\n";
    exit(0);
}

$tag = 'zipguard_' . bin2hex(random_bytes(4));
$plain = bin2hex(random_bytes(12));
$uid = createUser($conn, $tag, $plain, false, false, ['/']);   // can_download = 0

function req(string $url, string $jar, ?array $fields = null): array
{
    $ch = curl_init($url);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30, CURLOPT_HEADER => true];
    if ($fields !== null) {
        $o[CURLOPT_POST] = true;
        $o[CURLOPT_POSTFIELDS] = http_build_query($fields);
        $o[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
    }
    curl_setopt_array($ch, $o);
    $raw = curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$st, substr((string)$raw, (int)$hs)];
}

$jar = '/tmp/zg_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');

echo "test user: $tag  (is_admin=0, can_download=0)\n\n";

// real login
[$sG, $html] = req("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
[$sL] = req("$base/login.php", $jar,
    ['username' => $tag, 'password' => $plain, 'csrf_token' => $m[1] ?? '']);
echo "login.php: GET HTTP $sG, POST HTTP $sL\n";

// Careful: projects.php answers 302 -> /?panel=projects even with a valid session,
// so "not authenticated" is NOT deduced from the 302. The signal is a body
// containing the login form.
[$sP, $pBody] = req("$base/projects.php", $jar);
$authed = !str_contains($pBody, 'name="password"');
echo 'projects.php dopo login: HTTP ' . $sP . ' -> '
    . ($authed ? 'VALID SESSION' : 'LOST SESSION') . "\n\n";

// direct export
[$sZip, $body] = req("$base/api/export_project_zip.php", $jar, ['project_id' => '1']);
echo "POST api/export_project_zip.php -> HTTP $sZip\n";
echo '  body: ' . trim(preg_replace('/\s+/', ' ', strip_tags(substr($body, 0, 160)))) . "\n";

// The status alone is not enough: the CSRF guard right below canDownload() also
// answers 403, so a bare 403 is satisfied by either one. canDownload() is the first
// check in the endpoint, so under AUTH_MODE=full this request never reaches the CSRF
// guard and the 403 is unambiguously the download refusal. Under AUTH_MODE=none the
// opposite happens, canDownload() is bypassed and the CSRF guard produces the same 403,
// which would make this check pass while measuring nothing. Requiring that the body
// not be the CSRF error is what keeps the two apart.
$csrfRefusal = str_contains($body, 'CSRF');
$ok = $sZip === 403 && !$csrfRefusal;

// comparison: the same user must not be able to download via download.php either
[$sDl, $dBody] = req("$base/download.php", $jar);
echo "\nPOST download.php (riferimento) -> HTTP $sDl\n";
echo '  body: ' . trim(preg_replace('/\s+/', ' ', strip_tags(substr($dBody, 0, 100)))) . "\n";

$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(test user removed)\n\n";
echo 'RESULT: ' . ($ok ? 'can_download guard present (403)'
    : ($csrfRefusal ? '403 came from the CSRF guard, not from can_download <<< BUG'
        : 'guard ABSENT <<< BUG')) . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed the failure verdict.
exit($ok ? 0 : 1);