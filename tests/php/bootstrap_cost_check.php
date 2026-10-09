<?php
// Check 13.41 — the slim bootstrap avoids the home page data block.
//
// It measures how many SELECTs init.php would run before reaching the endpoint
// code (folder tree, three aggregates, star trend, LIGHT count, paginated file
// query): all things a JSON endpoint never reads.
//
// Usage:  docker cp tmp/bootstrap_cost_check.php awi-php:/tmp/
//         docker exec awi-php sh -c 'cd /tmp && php bootstrap_cost_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

$base = 'http://nginx';
$conn = connectDB();
$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-46s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

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

function httpPostForm(string $url, string $jar, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
    curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st];
}

function httpPostJson(string $url, string $jar, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 90]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

function selects(PDO $conn): int
{
    $r = $conn->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetchAll(PDO::FETCH_ASSOC);
    return (int)($r[0]['Value'] ?? 0);
}

$tag = 'bscost_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $plain, false, true, ['/']);

$jar = '/tmp/bs_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
[, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
[$sLogin] = httpPostForm("$base/login.php", $jar,
    ['username' => $tag, 'password' => $plain, 'csrf_token' => $m[1] ?? '']);
check('login succeeded', $sLogin === 302, "HTTP $sLogin");

$pid = (int)$conn->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();
$fid = (int)$conn->query("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype='LIGHT'
                           AND date_obs IS NOT NULL ORDER BY id LIMIT 1")->fetchColumn();
echo "  project $pid, file $fid\n\n";

// warm-up: compile the classes once, so the comparison is clean
httpPostJson("$base/api/project_preview.php", $jar,
    ['ids' => [$fid], 'project_id' => $pid, 'overrides' => []]);

[$stSlim] = httpPostJson("$base/api/project_preview.php", $jar,
    ['ids' => [$fid], 'project_id' => $pid, 'overrides' => []]);
check('endpoint answers 200 with the slim bootstrap', $stSlim === 200, "HTTP $stSlim");

// the init.php data block, measured separately
$before = selects($conn);
$t0 = microtime(true);
getAllFoldersAsTree($conn);
countFiles($conn, '', '', '', '', '', '', '', '');
sumExposureTime($conn, '', '', '', '', '', '', '', '');
getExposureStatsByFilter($conn, '', '', '', '', '', '', '', '');
getStarTrend($conn, '', '', '', '', '', '', '', '', 'name', 'ASC', 10000);
countFiles($conn, '', '', '', 'LIGHT', '', '', '', '');
getFiles($conn, '', '', '', '', '', '', '', '', 100, 0, 'name', 'ASC');
$initMs = (microtime(true) - $t0) * 1000;
$initSelects = selects($conn) - $before;

echo "\n  cost of the init.php data block (in every request):\n";
printf("    %d SELECT, %.0f ms\n\n", $initSelects, $initMs);
check('init.php ran useless queries', $initSelects > 0, "$initSelects SELECT per request");
check('the block had a real cost', $initMs > 0,
    sprintf('%.0f ms wasted per request, across all 5 endpoints', $initMs));

$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(test user removed)\n";
echo 'RESULT: ' . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'the slim bootstrap avoids the home data block') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed FAILURES.
exit($failed ? 1 : 0);