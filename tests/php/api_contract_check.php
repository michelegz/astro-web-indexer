<?php
// Check 13.33, 13.34, §13.35 — HTTP contract of the projects endpoints.
//
//  13.33 failed validation answered 200 {"success":false}, without an
//        'error' key: main.js does fetch().then(r => r.json()) and only throws on
//        data.error, so the user saw an empty preview with no explanation
//  13.34 expired session -> 302 to /login.php, i.e. HTML where the client wants JSON
//  13.35 nonexistent project_id -> 200 success:true and a link to a project that
//        does not exist
//
// Runs both on the pre-fix code and on the correct one: on the former it reports the
// defects.
//
// Usage:  docker cp tmp/api_contract_check.php awi-php:/tmp/
//         docker exec awi-php sh -c 'cd /tmp && php api_contract_check.php'

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

function httpPost(string $url, string $jar, $payload, bool $json = true): array
{
    $body = is_array($payload) ? http_build_query($payload) : (string)$payload;
    $ctype = is_array($payload) ? 'application/x-www-form-urlencoded' : 'application/json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json ? $body : $body,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . $ctype],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redir = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$st, $b, $redir];
}

function httpGet(string $url, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redir = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$st, $b, $redir];
}

// ---- test data -----------------------------------------------------------
$pid = (int)$conn->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();
$maxPid = (int)$conn->query('SELECT COALESCE(MAX(id), 0) FROM projects')->fetchColumn();
$ghost = $maxPid + 100000;
$hasGhost = (int)$conn->query('SELECT COUNT(*) FROM projects WHERE id = ' . $ghost)->fetchColumn() === 0;
check('nonexistent project id chosen', $hasGhost && $pid > 0, "real=$pid ghost=$ghost");

$fid = (int)$conn->query("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype='LIGHT'
                           AND date_obs IS NOT NULL ORDER BY id LIMIT 1")->fetchColumn();

// =====================================================================
// 13.34 — expired session
// =====================================================================
echo "\n=== 13.34: absent session -> 401 JSON, not 302 HTML ===\n";

// An empty jar: no session cookie, so isLoggedIn() is false.
$anon = '/tmp/ac_anon_' . bin2hex(random_bytes(4));
@file_put_contents($anon, '');

$jsonEndpoints = ['project_preview.php', 'project_add.php',
    'project_tree_preview.php', 'project_export_preview.php'];

// This whole section needs auth to be enforced. With AUTH_MODE=none
// isLoggedIn() returns true unconditionally (auth.php), so an anonymous request is served
// like a logged-in one and the 401 can never arrive. Reporting that as six failures would
// describe the deployment mode, not the code, so the section is skipped with a note.
if (!isAuthEnabled()) {
    $mode = defined('AUTH_MODE') ? AUTH_MODE : 'unset';
    echo "  SKIPPED: 13.34 needs AUTH_MODE=full, this deployment has '$mode'.\n";
    echo "  With auth off every request counts as logged in, so there is no 401\n";
    echo "  to assert and no redirect to the login. Re-run with AUTH_MODE=full to cover it.\n";
} else {
foreach ($jsonEndpoints as $ep) {
    $payload = $ep === 'project_export_preview.php'
        ? ['project_id' => $pid]
        : ['ids' => [$fid], 'project_id' => $pid, 'overrides' => []];
    [$st, $b, $redir] = httpPost("$base/api/$ep", $anon, json_encode($payload));
    $j = json_decode($b, true);
    $isHtml = stripos(ltrim($b), '<') === 0;
    check("$ep -> 401 with JSON", $st === 401 && is_array($j) && isset($j['error']) && !$isHtml,
        'HTTP ' . $st . ($redir !== '' ? ' -> ' . $redir : '') . ', ' . substr(trim($b), 0, 50));
}

// The ZIP download is different: the response is a file the browser saves, and a
// 401 JSON would arrive as a corrupt .zip. There the redirect to the login is correct and
// must stay.
[$st, $b, $redir] = httpPost("$base/api/export_project_zip.php", $anon, ['project_id' => $pid], false);
check('export_project_zip.php -> redirect to the login (not JSON)',
    $st === 302 && str_contains($redir, 'login.php'),
    'HTTP ' . $st . ' -> ' . ($redir !== '' ? $redir : '(no redirect)'));
}

// =====================================================================
// 13.33 — failed validation
// =====================================================================
echo "\n=== 13.33: empty ids -> 400 with an error key ===\n";

// A valid session is needed, otherwise it would stop at the earlier 401.
$tag = 'apicontract_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $plain, false, true, ['/']);
$jar = '/tmp/ac_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
[$s, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
[$st, $b] = httpPost("$base/login.php", $jar, ['username' => $tag, 'password' => $plain,
    'csrf_token' => $m[1] ?? '']);
[$sHome, $home] = httpGet("$base/?panel=projects", $jar);
check('valid session', $sHome === 200 && !str_contains($home, 'name="password"'), 'HTTP ' . $sHome);

foreach (['project_add.php', 'project_preview.php', 'project_tree_preview.php'] as $ep) {
    [$st, $b] = httpPost("$base/api/$ep", $jar, json_encode(['ids' => [], 'project_id' => $pid]));
    $j = json_decode($b, true);
    // The 'error' key is what main.js actually looks at: without it the
    // next branch built an empty preview with no message.
    check("$ep: ids [] -> 400 + error", $st === 400 && is_array($j) && isset($j['error']),
        'HTTP ' . $st . ', keys=' . (is_array($j) ? implode(',', array_keys($j)) : substr(trim($b), 0, 40)));
    check("  " . substr($ep, 8) . ': no stray success:true',
        !is_array($j) || empty($j['success']),
        is_array($j) && !empty($j['success']) ? 'STILL success:true' : '');
}

// The happy path must stay intact: non-empty ids -> 200 with groups.
[$st, $b] = httpPost("$base/api/project_preview.php", $jar,
    json_encode(['ids' => [$fid], 'project_id' => $pid, 'overrides' => []]));
$j = json_decode($b, true);
check('project_preview.php with real ids -> 200 with groups',
    $st === 200 && is_array($j) && !empty($j['groups']) && ($j['success'] ?? false) === true,
    'HTTP ' . $st . ', groups=' . (isset($j['groups']) ? count($j['groups']) : '?'));

// =====================================================================
// 13.35 — nonexistent project
// =====================================================================
echo "\n=== 13.35: nonexistent project_id -> 404, never success:true ===\n";

foreach ($jsonEndpoints as $ep) {
    $payload = $ep === 'project_export_preview.php'
        ? ['project_id' => $ghost]
        : ['ids' => [$fid], 'project_id' => $ghost, 'overrides' => []];
    [$st, $b] = httpPost("$base/api/$ep", $jar, json_encode($payload));
    $j = json_decode($b, true);
    $saysSuccess = is_array($j) && ($j['success'] ?? false) === true;
    check("$ep -> 404 and no success:true",
        $st === 404 && is_array($j) && isset($j['error']) && !$saysSuccess,
        'HTTP ' . $st . ', ' . substr(trim($b), 0, 55));
}

// The case the plan flagged: add answered 200 success:true and the JS
// offered "go to the project" towards a nonexistent id. Note that the error must NOT
// contain project_id: that is exactly the field the JS would use for the link.
[$st, $b] = httpPost("$base/api/project_add.php", $jar,
    json_encode(['ids' => [$fid], 'project_id' => $ghost, 'overrides' => []]));
$j = json_decode($b, true);
check('project_add.php does not return a usable project_id',
    $st === 404 && empty($j['project_id'] ?? null),
    'HTTP ' . $st . ', project_id=' . var_export($j['project_id'] ?? null, true));

// And the ZIP download, which answers via die() and not JSON. The CSRF token is
// needed, otherwise it would stop at the 403 of the previous batch and never get here.
// The token is only bound to a real session when auth is enforced, so with AUTH_MODE=none
// the login never opens one and the endpoint answers 403 whatever we send.
if (!isAuthEnabled()) {
    echo "  SKIPPED: the ZIP 404 needs AUTH_MODE=full (no session means no valid CSRF token)\n";
} else {
    [$st, $b] = httpPost("$base/api/export_project_zip.php", $jar,
        ['project_id' => $ghost, 'csrf_token' => $m[1] ?? ''], false);
    check('export_project_zip.php -> 404 with a valid token', $st === 404,
        'HTTP ' . $st . ', ' . substr(trim($b), 0, 50));
}

// No writes: the ghost project must not have been created.
$stillGhost = (int)$conn->query('SELECT COUNT(*) FROM projects WHERE id = ' . $ghost)->fetchColumn();
check('no project created for a nonexistent id', $stillGhost === 0,
    $stillGhost === 0 ? '' : 'CREATED');

// =====================================================================
// rendering: no PHP warning in the page, i18n keys resolved
// =====================================================================
echo "\n=== rendering of the projects pages ===\n";

// 13.41 had done require_once of igroup_files_table.php inside the bootstrap.
// That file is a partial that renders markup and expects $grp/$gi from the caller, so
// executing it there produced HTML warnings that ended up in the JSON body. The test caught
// it, but it has to be kept under control: no warning may reach the output.
foreach (['/?panel=projects' => 'home projects', '/projects.php?id=' . $pid => 'project detail'] as $path => $label) {
    [$st, $page] = httpGet("$base$path", $jar);
    $noise = [];
    foreach (['Warning', 'Notice', 'Deprecated', 'Fatal error'] as $lvl) {
        if (stripos($page, "<b>$lvl</b>") !== false || stripos($page, "$lvl:") !== false) {
            $noise[] = $lvl;
        }
    }
    check("$label: clean HTML", $st === 200 && $noise === [],
        'HTTP ' . $st . ', ' . strlen($page) . ' bytes' . ($noise ? ', noise: ' . implode('/', $noise) : ''));
}

// The 13.35 key must exist in every language, otherwise the 404 would end with
// the parameter name instead of a message. The language files are plain arrays, there is
// no need to load language.php (which wants getBestLanguage() and the session).
$langs = glob('/var/www/html/languages/*.php');
$bad = [];
foreach ($langs as $lf) {
    $arr = require $lf;
    if (!isset($arr['projects_not_found']) || trim((string)$arr['projects_not_found']) === ''
        || str_contains((string)$arr['projects_not_found'], 'projects_not_found')) {
        $bad[] = basename($lf, '.php');
    }
}
check('projects_not_found in all languages', $bad === [],
    count($langs) . ' languages' . ($bad ? ', missing in: ' . implode(', ', $bad) : ''));

// ---- cleanup -------------------------------------------------------------
$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
@unlink($anon);
echo "\n(test artifacts and user removed)\n";
echo 'RESULT: ' . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'HTTP contract respected') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed FAILURES.
exit($failed ? 1 : 0);
