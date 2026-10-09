<?php
// Check the four non-projects endpoints migrated from init.php to api_bootstrap.php.
//
// init.php is the home bootstrap: besides auth and the shared includes it runs
// the whole file-table pipeline (folder tree, three aggregates, star trend
// up to 10,000 rows, LIGHT count, paginated file query) and assigns variables that
// no JSON endpoint reads. These four were including it whole.
//
// Not one of the four uses a single one of those variables: the check is on the symbols
// init.php assigns ($files, $folders, $totalRecords, $toggleableKeys, $hiddenCols,
// $columnGroups, $visibleAdvKeys, $showStarMetrics, $tableColspan).
//
// Usage:  docker cp tmp/api_bootstrap_all_check.php awi-php:/tmp/
//         docker exec awi-php php /tmp/api_bootstrap_all_check.php

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

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
    curl_close($ch);
    return [$st, $b];
}

function httpPost(string $url, string $jar, $payload, bool $form = true): array
{
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

// ---------------------------------------------------------------- static check
echo "\n=== the four no longer load the home data block ===\n";

$targets = ['get_duplicates.php', 'update_visibility.php',
    'sff_get_filters.php', 'find_calibration_files.php'];

// Symbols that init.php assigns and that no JSON endpoint should read.
$initVars = ['$files', '$folders', '$totalRecords', '$toggleableKeys', '$hiddenCols',
    '$columnGroups', '$visibleAdvKeys', '$visibleStarKeys', '$visibleFrameKeys',
    '$visibleBaseKeys', '$advKeys', '$starKeys', '$frameKeys', '$showStarMetrics',
    '$tableColspan', '$hiddenColsProjects'];

foreach ($targets as $t) {
    $src = (string)file_get_contents('/var/www/html/api/' . $t);
    check("$t: usa api_bootstrap.php",
        str_contains($src, 'api_bootstrap.php'), '');
    check("  and not init.php", !str_contains($src, "includes/init.php"), '');
    $used = [];
    foreach ($initVars as $v) {
        // Reads only: an assignment like $conn = connectDB() does not count.
        if (preg_match('/(?<![A-Za-z0-9_$])' . preg_quote($v, '/') . '\b/', $src)
            && !preg_match('/^\s*' . preg_quote($v, '/') . '\s*=[^=]/m', $src)) {
            $used[] = $v;
        }
    }
    check("  does not read init.php variables", $used === [],
        $used === [] ? '' : implode(', ', $used));
}

// init.php must stay used by the pages: the home is not an endpoint.
$home = (string)file_get_contents('/var/www/html/index.php');
check('index.php continua a usare init.php',
    str_contains($home, 'init.php'), '');
check('projects.php too', str_contains((string)file_get_contents('/var/www/html/projects.php'), 'init.php'), '');

// ---------------------------------------------------------------- functional check
echo "\n=== the four answer on the happy path ===\n";

$tag = 'apiboot4_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $plain, false, true, ['/']);
$jar = '/tmp/ab4_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
[$s, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
httpPost("$base/login.php", $jar, ['username' => $tag, 'password' => $plain,
    'csrf_token' => $m[1] ?? '']);
[$sHome, $home2] = httpGet("$base/?panel=projects", $jar);
check('valid session', $sHome === 200 && !str_contains($home2, 'name="password"'), 'HTTP ' . $sHome);

$row = $conn->query("SELECT id, file_hash FROM files
                     WHERE file_hash IS NOT NULL AND file_hash <> '' ORDER BY id LIMIT 1")
    ->fetch(PDO::FETCH_ASSOC);
$fid = (int)($row['id'] ?? 0);
// The test file starts hidden, so the update_visibility call has a real
// change to verify. The row ends up as it was: "show" sets is_hidden back to 0.
if ($fid > 0) {
    $conn->prepare('UPDATE files SET is_hidden = 1 WHERE id = :id')->execute([':id' => $fid]);
}
$hash = (string)($row['file_hash'] ?? '');
check('test file', $fid > 0 && $hash !== '', "id=$fid hash=" . substr($hash, 0, 12));

// get_duplicates: JSON with the list of duplicates
[$st, $b] = httpGet("$base/api/get_duplicates.php?hash=" . rawurlencode($hash), $jar);
$j = json_decode($b, true);
check('get_duplicates.php -> 200 with JSON',
    $st === 200 && is_array($j),
    'HTTP ' . $st . ', ' . strlen($b) . ' bytes, keys: '
    . (is_array($j) ? implode(',', array_slice(array_keys($j), 0, 4)) : substr($b, 0, 50)));

// update_visibility: ids arrive as an array. It is worth calling it for real, and then
// putting things back: it uses show, which is idempotent.
// update_visibility: ids arrive as an array. It is worth calling it for real. "show" sets
// is_hidden to 0, so it is idempotent and leaves no trace: nothing to restore.
// (The column is is_hidden, not visibility: I had guessed the name and the test died
// on a Column not found, which is the fastest way to learn the schema.)
$beforeHidden = (int)$conn->query("SELECT COALESCE(is_hidden, 0) FROM files WHERE id = $fid")
    ->fetchColumn();
[$st, $b] = httpPost("$base/api/update_visibility.php", $jar,
    ['ids' => [$fid], 'action' => 'show', 'hash' => $hash], false);
$j = json_decode($b, true);
$afterHidden = (int)$conn->query("SELECT COALESCE(is_hidden, 0) FROM files WHERE id = $fid")
    ->fetchColumn();
check('update_visibility.php -> 200 with JSON',
    $st === 200 && is_array($j) && !isset($j['error']),
    'HTTP ' . $st . ', ' . substr(trim($b), 0, 70));
check('  and it applied the change',
    $beforeHidden === 1 && $afterHidden === 0,
    "is_hidden $beforeHidden -> $afterHidden");

// sff_get_filters wants id and type (not file_id/search_type), and the file must be
// a LIGHT or it answers 404.
$lightId = (int)$conn->query("SELECT id FROM files WHERE imgtype LIKE 'LIGHT%'
                               AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
check('test LIGHT', $lightId > 0, "id=$lightId");
[$st, $b] = httpGet("$base/api/sff_get_filters.php?id=$lightId&type=light", $jar);
check('sff_get_filters.php -> 200',
    $st === 200,
    'HTTP ' . $st . ', ' . strlen($b) . ' bytes, '
    . substr(preg_replace('/\s+/', ' ', trim($b)), 0, 60));

// find_calibration_files: an invalid search_type must be a clean 400, not a
// warning that ends up in the body and breaks the JSON. That is the defect this migration
// brought to light.
[$st, $b] = httpPost("$base/api/find_calibration_files.php", $jar,
    ['file_id' => $fid, 'search_type' => 'light'], false);
$jBad = json_decode($b, true);
check('invalid search_type -> 400 with valid JSON',
    $st === 400 && is_array($jBad) && isset($jBad['valid']),
    'HTTP ' . $st . ', ' . substr(preg_replace('/\s+/', ' ', trim($b)), 0, 70));
check('  and no warning in the body',
    stripos($b, 'Warning') === false && !str_contains(trim($b), '<br'),
    str_contains($b, 'Warning') ? 'STILL A WARNING' : '');

// find_calibration_files: JSON payload with file_id and search_type
[$st, $b] = httpPost("$base/api/find_calibration_files.php", $jar,
    ['file_id' => $fid, 'search_type' => 'lights'], false);
$j = json_decode($b, true);
check('find_calibration_files.php -> 200 with JSON',
    $st === 200 && is_array($j),
    'HTTP ' . $st . ', ' . strlen($b) . ' bytes, ' . substr(trim($b), 0, 60));

// None of the four may emit markup or warnings before the JSON.
echo "\n=== none emits junk HTML ===\n";
foreach ([
    'get_duplicates.php' => "$base/api/get_duplicates.php?hash=" . rawurlencode($hash),
] as $ep => $url) {
    [$st, $b] = httpGet($url, $jar);
    $noise = [];
    foreach (['Warning', 'Notice', 'Deprecated', 'Fatal error'] as $lvl) {
        if (stripos($b, "<b>$lvl</b>") !== false || stripos($b, "$lvl:") !== false) {
            $noise[] = $lvl;
        }
    }
    check("$ep: no warning", $noise === [], $noise === [] ? '' : implode('/', $noise));
}
[$st, $b] = httpGet("$base/api/sff_get_filters.php", $jar);
check('sff_get_filters.php: no warning',
    stripos($b, 'Warning') === false && stripos($b, 'Notice') === false,
    '');

$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(test artifacts and user removed)\n";
echo 'RESULT: ' . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'the four endpoints no longer pay for the home block') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed FAILURES.
exit($failed ? 1 : 0);