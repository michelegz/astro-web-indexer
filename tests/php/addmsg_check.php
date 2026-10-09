<?php
// Check §12 — the add-to-project error message must be visible
// in the step where it gets written.
//
// Before the fix #projectAddMsg was inside #projectStep1, which showProjectStep()
// hides: the errors come from the confirmation fetch (step 3), so the red
// box ended up in a display:none container and the user saw nothing.
//
// The test does a real login via login.php and then analyses the home markup, which
// is the page holding the modal.
//
// Usage:  docker cp tmp/addmsg_check.php awi-php:/var/www/html/
//         docker exec awi-php php /var/www/html/addmsg_check.php

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

$base = 'http://nginx';
$conn = connectDB();
$failed = [];

// test user, removed at the end
$tag = 'addmsg_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $plain, false, true, ['/']);

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-48s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

function httpGet(string $url, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30]);
    $body = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $body];
}

function httpPost(string $url, string $jar, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30]);
    curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st];
}

$jar = '/tmp/am_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
[$s, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
[$sLogin] = httpPost("$base/login.php", $jar,
    ['username' => $tag, 'password' => $plain, 'csrf_token' => $m[1] ?? '']);
[$sHome, $home] = httpGet("$base/", $jar);
echo "  login GET $s / POST $sLogin, home HTTP $sHome (" . strlen($home) . " bytes)\n";

check('authenticated home', !str_contains($home, 'name="password"'),
    str_contains($home, 'name="password"') ? 'the login page was served' : '');

// isolate the modal: from its start up to projectStep4 and its children
$start = strpos($home, '<div id="projectModal"');
check('modal present', $start !== false);
$modal = $start !== false ? substr($home, $start, 12000) : '';

if ($modal !== '') {
    // 1. the message must not sit inside any step
    foreach ([1, 2, 3, 4] as $s) {
        $needle = "id=\"projectStep{$s}\"";
        $i = strpos($modal, $needle);
        if ($i === false) {
            check("projectStep{$s} present", false);
            continue;
        }
        // the step's window: from its start to the end of the next known sibling
        $next = [1 => 'id="projectStep2"', 2 => 'id="projectStep3"',
                 3 => 'id="projectStep4"', 4 => '</div>'][$s];
        $j = strpos($modal, $next, $i + 1);
        $chunk = $j !== false ? substr($modal, $i, $j - $i) : '';
        check("projectAddMsg NOT inside projectStep{$s}",
            !str_contains($chunk, 'id="projectAddMsg"'));
    }

    // 2. position: it must precede projectStep1, otherwise the showProjectStep
    //    preceding it would hide the message too
    $iMsg = strpos($modal, 'id="projectAddMsg"');
    $iStep1 = strpos($modal, 'id="projectStep1"');
    check('projectAddMsg before projectStep1',
        $iMsg !== false && $iStep1 !== false && $iMsg < $iStep1,
        "msg@{$iMsg} step1@{$iStep1}");

    // 3. the four steps are still there
    foreach ([1, 2, 3, 4] as $s) {
        check("projectStep{$s} present", strpos($modal, "id=\"projectStep{$s}\"") !== false);
    }
    // 4. and the controls the JS uses for the confirmation step
    check('projectModalConfirm present', strpos($modal, 'id="projectModalConfirm"') !== false);
    check('projectTreePreview present', strpos($modal, 'id="projectTreePreview"') !== false);
}

// cleanup
$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(test user removed)\n";
echo 'RESULT: ' . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'the message is visible in every step') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed FAILURES.
exit($failed ? 1 : 0);