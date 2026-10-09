<?php
// Check 13.9, 13.11, 13.13, 13.18 — user feedback and markup.
//
//  13.9  the save/rename errors were shown with $e->getMessage() on a
//        catch(Exception): a TypeError escaped as fatal and a PDOException
//        showed the user the query that had failed.
//  13.11 the truncation at 2000 files was invisible: a preview of the first 2000
//        looked like a complete answer.
//  13.13 with the column hidden no <td> was rendered at all, so the
//        chart read all null and skipped drawing, leaving an empty box
//        that looked broken.
//  13.18 the i18n template ended with ": " and the JS added " (name1, name2)":
//        two spaces.
//
// Usage:  docker cp tmp/ux_feedback_check.php awi-php:/tmp/
//         docker exec awi-php sh -c 'cd /tmp && php ux_feedback_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/http_json.php';
require_once '/var/www/html/includes/projects_functions.php';

if (!function_exists('__')) {
    function __(string $key, array $replace = []): string
    {
        $out = $key;
        foreach ($replace as $k => $v) {
            $out = str_replace('{' . $k . '}', (string)$v, $out);
        }
        return $out;
    }
}

$base = 'http://nginx';
$conn = connectDB();
$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-58s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

function httpGet(string $url, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redir = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$st, $b, $redir];
}

function httpPost(string $url, string $jar, array $payload, bool $form = false): array
{
    $body = $form ? http_build_query($payload) : json_encode($payload);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . ($form
            ? 'application/x-www-form-urlencoded' : 'application/json')],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redir = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$st, $b, $redir];
}

// =====================================================================
// 13.18 — no double space
// =====================================================================
echo "\n=== 13.18: the template must not end with a space ===\n";

$doubleSpace = [];
foreach (glob('/var/www/html/languages/*.php') as $lf) {
    $arr = require $lf;
    $v = (string)($arr['filter_mapping_unmapped'] ?? '');
    // The JS adds " (" + names + ")": a trailing space in the template makes two.
    if ($v !== '' && str_ends_with($v, ' ')) {
        $doubleSpace[] = basename($lf, '.php');
    }
}
check('no language makes the template end with a space',
    $doubleSpace === [],
    $doubleSpace === [] ? '5 languages' : 'with a space: ' . implode(', ', $doubleSpace));

$itArr = require '/var/www/html/languages/it.php';
$tmpl = (string)$itArr['filter_mapping_unmapped'];
$rendered = str_replace('{count}', 2, $tmpl) . ' (Ha, OIII)';
check('the final line does not have two spaces', !str_contains($rendered, ':  ('),
    '"' . $rendered . '"');

// =====================================================================
// 13.13 — charts and hidden columns
// =====================================================================
echo "\n=== 13.13: the chart says when its column is off ===\n";

$pid = (int)$conn->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();
check('test project', $pid > 0, "id=$pid");

$src = (string)file_get_contents('/var/www/html/projects.php');
check('the chart render knows about hidden columns',
    str_contains($src, 'in_array($mk, $hiddenColsProjects, true)'), '');
check('  and does not emit the canvas for a hidden metric',
    (bool)preg_match(
        '/in_array\(\$mk, \$hiddenColsProjects, true\).*?continue;.*?<canvas/s', $src), '');
check('  and explains the reason to the user',
    str_contains($src, "__('projects_chart_column_hidden')"), '');

// The key must exist in every language, otherwise the 200 leaves it empty.
$bad = [];
foreach (glob('/var/www/html/languages/*.php') as $lf) {
    $arr = require $lf;
    if (trim((string)($arr['projects_chart_column_hidden'] ?? '')) === '') {
        $bad[] = basename($lf, '.php');
    }
}
check('projects_chart_column_hidden in all languages', $bad === [],
    $bad === [] ? '5 languages' : 'missing in: ' . implode(', ', $bad));

// And the page must render without warnings and show the chart when the column
// is visible (the normal case), without the fix having turned it off entirely.
$tag = 'uxfb_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $plain, false, true, ['/']);
$jar = '/tmp/ux_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
[$s, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
httpPost("$base/login.php", $jar, ['username' => $tag, 'password' => $plain,
    'csrf_token' => $m[1] ?? ''], true);

[$st, $page] = httpGet("$base/projects.php?id=$pid", $jar);
if ($st === 302) {
    [$st, $page] = httpGet("$base/projects.php", $jar);
}
$noise = [];
foreach (['Warning', 'Notice', 'Deprecated', 'Fatal error'] as $lvl) {
    if (stripos($page, "<b>$lvl</b>") !== false) {
        $noise[] = $lvl;
    }
}
check('the projects page renders without PHP warnings', $noise === [],
    'HTTP ' . $st . ', ' . strlen($page) . ' bytes' . ($noise ? ': ' . implode('/', $noise) : ''));

// =====================================================================
// 13.11 — the truncation at 2000 is declared
// =====================================================================
echo "\n=== 13.11: the truncation at 2000 files is declared ===\n";

$prevSrc = (string)file_get_contents('/var/www/html/api/project_preview.php');
check('project_preview counts the discarded ids',
    str_contains($prevSrc, '$truncated = count($selectedIds) - count($ids);'), '');
check('  and exposes it in the response',
    str_contains($prevSrc, "'truncated' => \$truncated"), '');
check('  and adds a row to the skipped ones',
    str_contains($prevSrc, "__('projects_truncated'"), '');

$bad = [];
foreach (glob('/var/www/html/languages/*.php') as $lf) {
    $arr = require $lf;
    if (trim((string)($arr['projects_truncated'] ?? '')) === '') {
        $bad[] = basename($lf, '.php');
    }
}
check('projects_truncated in all languages', $bad === [],
    $bad === [] ? '5 languages' : 'missing in: ' . implode(', ', $bad));

// The count must be real: 2001 ids of which 2000 are analysed.
$fakeIds = range(1, 2001);
[$st, $b] = httpPost("$base/api/project_preview.php", $jar,
    ['ids' => $fakeIds, 'project_id' => $pid, 'overrides' => []]);
$j = json_decode($b, true);
check('the response declares the truncation',
    $st === 200 && is_array($j) && ($j['truncated'] ?? null) === 1,
    'HTTP ' . $st . ', truncated=' . var_export($j['truncated'] ?? null, true));
$mentioned = false;
foreach ((array)($j['skipped'] ?? []) as $s) {
    if (str_contains((string)($s['message'] ?? ''), '2000')) {
        $mentioned = true;
    }
}
check('  and says so in the skipped ones too', $mentioned, '');

// Below the limit it must not invent a truncation.
[$st, $b] = httpPost("$base/api/project_preview.php", $jar,
    ['ids' => [1, 2, 3], 'project_id' => $pid, 'overrides' => []]);
$j = json_decode($b, true);
check('below the limit -> truncated 0', $st === 200 && ($j['truncated'] ?? null) === 0,
    'truncated=' . var_export($j['truncated'] ?? null, true));

// =====================================================================
// 13.9 — errors are visible without losing detail
// =====================================================================
echo "\n=== 13.9: action errors are neither invisible nor dangerous ===\n";

check('projects.php catches Throwable, not Exception',
    (bool)preg_match('/\}\s*catch\s*\(\s*Throwable\s+\$e\s*\)\s*\{/', $src), '');
check('an InvalidArgumentException shows its message',
    str_contains($src, '$e instanceof InvalidArgumentException'), '');
check('a generic error does not show the query',
    str_contains($src, "__('projects_error_generic')") && str_contains($src, 'error_log('), '');

$bad = [];
foreach (glob('/var/www/html/languages/*.php') as $lf) {
    $arr = require $lf;
    if (trim((string)($arr['projects_error_generic'] ?? '')) === '') {
        $bad[] = basename($lf, '.php');
    }
}
check('projects_error_generic in all languages', $bad === [],
    $bad === [] ? '5 languages' : 'missing in: ' . implode(', ', $bad));

// An action that really fails must show the user something. rename_setup
// with a nonexistent id raises InvalidArgumentException, so the specific
// message must reach the page.
$csrf = null;
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page, $cm);
$csrf = $cm[1] ?? ($m[1] ?? '');
[$st, $out, $redir] = httpPost("$base/projects.php", $jar, [
    'action' => 'rename_setup',
    'project_id' => (string)$pid,
    'setup_id' => '99999999',
    'name' => 'x',
    'csrf_token' => $csrf,
], true);
// The error must reach the user as rendered text, not as a key: looking for
// the key would be a false pass because the key never ends up in the HTML.
$enArr = require '/var/www/html/languages/en.php';
$errText = (string)($enArr['projects_error_name'] ?? '');
$hasErr = $errText !== '' && str_contains($out, $errText);
check('a failed action shows the message to the user',
    $hasErr, 'HTTP ' . $st . ($redir !== '' ? ' -> ' . $redir : '') . ', '
    . strlen($out) . ' bytes, looked for "' . $errText . '"');

$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(test artifacts and user removed)\n";
echo 'RESULT: ' . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'user feedback and markup correct') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed FAILURES.
exit($failed ? 1 : 0);