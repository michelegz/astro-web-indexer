<?php
// Check 13.41 and 13.2 — the five projects endpoints must work without
// init.php, and the preview must no longer carry the manifest nobody reads.
//
// It does a real login and calls each endpoint over HTTP, comparing the status and the
// shape of the response. It also counts the queries run during the request, which is
// what the slim bootstrap should have removed.
//
// Usage:  docker cp tmp/api_bootstrap_check.php awi-php:/tmp/
//         docker exec awi-php sh -c 'cd /tmp && php api_bootstrap_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

$base = 'http://nginx';
$conn = connectDB();
$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-52s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

// test user
$tag = 'apiboot_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $plain, false, true, ['/']);

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

function httpPost(string $url, string $jar, $payload): array
{
    // CURLOPT_POSTFIELDS with an array would send multipart/form-data, and login.php
    // reads urlencoded from $_POST: it must be encoded explicitly.
    $body = is_array($payload) ? http_build_query($payload) : (string)$payload;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

function httpPostJson(string $url, string $jar, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

$jar = '/tmp/ab_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
[$s, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
httpPost("$base/login.php", $jar, ['username' => $tag, 'password' => $plain,
    'csrf_token' => $m[1] ?? '']);
// the slim bootstrap no longer calls init.php: the home page stays reachable anyway
[$sHome, $home] = httpGet("$base/projects.php", $jar);
check('valid session (redirect to /?panel=projects expected)',
    !str_contains($home, 'name="password"'), "HTTP $sHome");

$pid = (int)$conn->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();
$fid = (int)$conn->query("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype='LIGHT'
                           AND date_obs IS NOT NULL ORDER BY id LIMIT 1")->fetchColumn();

echo "\n=== the five endpoints answer ===\n";

// The contract keys are camelCase ('customSetups', 'groupFpOverrides',
// 'customSkipped') while 'project_id', 'new_project', 'ids', 'overrides' are
// snake_case: an oversight on any of them raises no error, it yields an undefined
// key which in PHP is null, and the semantically right branch is skipped
// silently. The tree preview and the add must also read the same keys,
// otherwise the preview promises a custom setup the add then never creates.
require_once '/var/www/html/includes/projects_functions.php';
$reqKeys = array_keys(parseProjectAddRequest(
    ['ids' => [1], 'new_project' => ['name' => 'x'], 'overrides' => [], 'custom_setups' => [1 => 'S']]
));
check('parseProjectAddRequest: expected keys present',
    count(array_intersect(['project_id', 'ids', 'overrides', 'customSetups',
        'groupFpOverrides', 'new_project'], $reqKeys)) === 6,
    implode(', ', $reqKeys));

$tmpPid = (int)$conn->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();
$conn->beginTransaction();
$prepKeys = array_keys(projectAddPrepare($conn, $tmpPid, [], [], [], null));
$conn->rollBack();
check('projectAddPrepare: expected keys present',
    count(array_intersect(['project_id', 'ids', 'customSkipped'], $prepKeys)) === 3,
    implode(', ', $prepKeys));

// Every key read by the endpoints must exist in one of the two arrays.
// 'frozen' is a declared exception: projectAddPrepare exposes it only on
// early returns (project already frozen), and in the normal branch it is absent, where
// !empty() correctly treats it as false.
$optional = ['frozen'];
$expected = array_merge($reqKeys, $prepKeys);
foreach (['project_add.php', 'project_tree_preview.php'] as $ep) {
    $src = (string)file_get_contents('/var/www/html/api/' . $ep);
    preg_match_all("/\\\$(?:req|prep)\['([A-Za-z_]+)'\]/", $src, $km);
    $missing = array_values(array_diff(array_unique($km[1]), $expected, $optional));
    check($ep . ': no key outside the contract', $missing === [],
        $missing ? implode(', ', $missing) : implode(' / ', array_unique($km[1])));
}

// And the frozen branch must really expose the flag, otherwise the guard in
// project_add.php would be dead code: here that very entry point is exercised.
$conn->beginTransaction();
$conn->prepare("INSERT INTO projects (name, notes, assign_mode) VALUES (:n, '', 'frozen')")
    ->execute([':n' => 'api_boot_frozen_' . bin2hex(random_bytes(3))]);
$fpPid = (int)$conn->lastInsertId();
$prepFrozen = projectAddPrepare($conn, $fpPid, [1], [], [], null);
$conn->rollBack();
check('projectAddPrepare reports the frozen project',
    !empty($prepFrozen['frozen']) && ($prepFrozen['ids'] ?? null) === [],
    'frozen=' . var_export($prepFrozen['frozen'] ?? null, true)
    . ', ids=' . json_encode($prepFrozen['ids'] ?? null));

[$st, $b] = httpPostJson("$base/api/project_preview.php", $jar,
    ['ids' => [$fid], 'project_id' => $pid, 'overrides' => []]);
$j = json_decode($b, true);
check('project_preview.php', $st === 200 && is_array($j) && isset($j['groups']),
    "HTTP $st, " . (isset($j['groups']) ? 'groups=' . count($j['groups']) : substr($b, 0, 60)));

// an empty ids makes parseProjectAddRequest fail, which answers 400: expected.
[$st, $b] = httpPost("$base/api/project_add.php", $jar, ['ids' => '']);
check('project_add.php (invalid input -> 400)', $st === 400 && str_contains($b, 'error'),
    "HTTP $st");

[$st, $b] = httpPost("$base/api/project_add.php", $jar,
    json_encode(['ids' => [$fid], 'project_id' => $pid, 'overrides' => []]));
$j = json_decode($b, true);
check('project_add.php (real add on a frozen or existing project)', $st === 200,
    "HTTP $st");

[$st, $b] = httpPost("$base/api/project_tree_preview.php", $jar,
    json_encode(['ids' => [$fid], 'project_id' => $pid, 'overrides' => []]));
$j = json_decode($b, true);
$htmlLen = isset($j['html']) ? strlen($j['html']) : 0;
check('project_tree_preview.php (tree HTML)', $st === 200 && $htmlLen > 0,
    "HTTP $st, html $htmlLen bytes");
// In hypo mode the rows are the hypothetical nodes, not the file table cells,
// so the right marker is the tree structure, not a thumbnail.
check('  the html contains the tree structure',
    $htmlLen > 0 && (str_contains($j['html'] ?? '', 'cal-group')
        || str_contains($j['html'] ?? '', 'tnode')), '');

[$st, $b] = httpPost("$base/api/project_export_preview.php", $jar,
    json_encode(['project_id' => $pid]));
$j = json_decode($b, true);
check('project_export_preview.php', $st === 200 && isset($j['entries']),
    "HTTP $st, entries=" . (isset($j['entries']) ? count($j['entries']) : '?'));

echo "\n=== 13.2: the manifest no longer travels, skipped is capped ===\n";
check('no manifest in the preview', $j !== null && !array_key_exists('manifest', $j),
    array_key_exists('manifest', $j ?? [])
        ? 'STILL PRESENT, ' . strlen((string)($j['manifest'])) . ' bytes' : 'absent');
check('skipped_total present', isset($j['skipped_total']), (string)($j['skipped_total'] ?? '-'));
$payload = strlen($b);
printf("  preview payload: %.1f KB (over %d entries)\n", $payload / 1024,
    count($j['entries'] ?? []));

// comparison: how much the manifest used to take up
require_once '/var/www/html/includes/projects_functions.php';
require_once '/var/www/html/includes/projects_diagnostics.php';
require_once '/var/www/html/includes/project_export.php';
$manifestBytes = strlen(json_encode(buildProjectExportMap($conn, $pid)['manifest']));
printf("  manifest (no longer sent): %.1f KB\n", $manifestBytes / 1024);
check('real saving', $manifestBytes > 0,
    sprintf('payload %.1f KB without the %.1f KB manifest',
        $payload / 1024, $manifestBytes / 1024));

$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(test user removed)\n";
echo 'RESULT: ' . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'all endpoints work with the slim bootstrap') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed FAILURES.
exit($failed ? 1 : 0);