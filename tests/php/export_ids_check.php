<?php
// Check 13.10 — the file ids do not travel in the request line.
//
// The ids used to go in ?ids=1,2,3: a few digits each, so a few thousand
// files put tens of KB on the request line, past the 8 KB the server accepts, and
// the request died with 414 before reaching a single line of application code.
// The test uses 4000 ids, which in GET would never be processed.
//
// It also covers the missing permission on get_unmapped_filters.php, which asked for the
// raw ids without any directory filter: a user restricted to one root
// could ask which unmapped filter names exist in a directory they
// cannot see.
//
// Usage:  docker cp tmp/export_ids_check.php awi-php:/tmp/
//         docker exec awi-php sh -c 'cd /tmp && php export_ids_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/http_json.php';

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

function httpPost(string $url, string $jar, array $payload, bool $form = false): array
{
    $body = $form ? http_build_query($payload) : json_encode($payload);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . ($form
            ? 'application/x-www-form-urlencoded' : 'application/json')],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 180]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

function httpGet(string $url, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 180]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

// =====================================================================
// the load that broke the GET
// =====================================================================
echo "\n=== 13.10: 4000 ids via POST, which in GET would never get through ===\n";

$ids = range(1, 4000);
$queryString = implode(',', $ids);
// If this string sat in a request line, the server would reject it before us.
$queryBytes = strlen($queryString);
check('the query string alone exceeds the 8 KB limit',
    $queryBytes > 8192, $queryBytes . ' bytes');

// The original defect: a GET with that string does not reach the application.
// No session is needed: the rejection happens at the HTTP server level.
$jarAnon = '/tmp/eia_' . bin2hex(random_bytes(4));
@file_put_contents($jarAnon, '');
[$st414, $b414] = httpGet("$base/api/export_astrobin_csv.php?ids=$queryString", $jarAnon);
@unlink($jarAnon);
check('GET with 18 KB of query string -> does not reach the application',
    $st414 >= 400,
    'HTTP ' . $st414 . (str_contains($b414, 'Request-URI Too Large') ? ' (414)' : ''));

// Valid user with access to all roots.
$tag = 'expids_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $plain, false, true, ['/']);
$jar = '/tmp/ei_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
[$s, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
httpPost("$base/login.php", $jar, ['username' => $tag, 'password' => $plain,
    'csrf_token' => $m[1] ?? ''], true);
[$sHome, $home] = httpGet("$base/?panel=projects", $jar);
check('valid session', $sHome === 200 && !str_contains($home, 'name="password"'), 'HTTP ' . $sHome);

[$st, $b] = httpPost("$base/api/export_astrobin_csv.php", $jar, ['ids' => $ids]);
$rows = array_values(array_filter(explode("\n", trim($b))));
check('CSV export with 4000 ids -> 200 and rows',
    $st === 200 && count($rows) > 1 && str_starts_with(trim($b), 'date,'),
    'HTTP ' . $st . ', rows=' . max(0, count($rows) - 1));
check('  no 414', $st !== 414, 'HTTP ' . $st);

[$st, $b] = httpPost("$base/api/get_unmapped_filters.php", $jar, ['ids' => $ids]);
$j = json_decode($b, true);
check('unmapped filters with 4000 ids -> 200 and JSON',
    $st === 200 && is_array($j) && isset($j['unmapped']),
    'HTTP ' . $st . ', unmapped=' . count($j['unmapped'] ?? []));

// A small GET stays supported: a hand-written URL must keep working.
$oneId = (int)$conn->query("SELECT id FROM files WHERE deleted_at IS NULL
    AND imgtype LIKE 'LIGHT%' ORDER BY id LIMIT 1")->fetchColumn();
[$st, $b] = httpGet("$base/api/export_astrobin_csv.php?ids=$oneId", $jar);
check('GET with a single id -> still supported',
    $st === 200 && str_starts_with(trim($b), 'date,'), 'HTTP ' . $st);

// And the "no ids" case must give the empty CSV, not an error.
[$st, $b] = httpPost("$base/api/export_astrobin_csv.php", $jar, ['ids' => []]);
check('no ids -> CSV with only the header', $st === 200 && trim($b) === 'date,filter,number,duration,binning,gain,sensorCooling,fNumber,darks,flats,flatDarks,bias',
    'HTTP ' . $st);

// The junk must not turn into id 0.
[$st, $b] = httpPost("$base/api/export_astrobin_csv.php", $jar, ['ids' => ['abc', '', '-3', '0', $oneId]]);
check('non-numeric ids discarded, not cast to 0', $st === 200 && str_starts_with(trim($b), 'date,'),
    'HTTP ' . $st);

// =====================================================================
// the permission that was missing on get_unmapped_filters.php
// =====================================================================
echo "\n=== 13.10: get_unmapped_filters.php also filters by directory ===\n";

// Two distinct roots in the DB, as in security_batch_check.php.
$roots = $conn->query("SELECT DISTINCT SUBSTRING_INDEX(path, '/', 1) AS r FROM files
                       WHERE path IS NOT NULL AND path LIKE '%/%' ORDER BY r")
    ->fetchAll(PDO::FETCH_COLUMN);
$allowed = $denied = null;
foreach ($roots as $r) {
    foreach ($roots as $cand) {
        if ($cand !== $r) {
            $allowed = (string)$r;
            $denied = (string)$cand;
            break 2;
        }
    }
}

if ($allowed === null) {
    check('two distinct roots in the DB', false, 'insufficient fixture');
} else {
    // A file in the denied root, with an existing filter: if the id gets through, the
    // filter name ends up in the response.
    $row = $conn->query("SELECT id, filter FROM files
        WHERE SUBSTRING_INDEX(path,'/',1) = " . $conn->quote($denied)
        . " AND deleted_at IS NULL AND filter IS NOT NULL AND TRIM(filter) != ''
          ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $fidNo = (int)($row['id'] ?? 0);
    $filterNo = trim((string)($row['filter'] ?? ''));

    check('file with a filter in the denied root', $fidNo > 0 && $filterNo !== '',
        "denied root={$denied} file=#{$fidNo} filter=\"{$filterNo}\"");

    // The directory permission assertions only mean something when directory permissions
    // are actually enforced. With AUTH_MODE=none isAuthEnabled() is false,
    // getAllowedDirs() returns null and buildDirPermissionFilter() deliberately emits
    // no filter, so a restricted user gets every row: that is the documented behaviour
    // of the 'none' mode, not a defect. Without this guard the check below reports a
    // leak that cannot exist in this deployment, and the run goes red for the wrong
    // reason. Same guard as §13.39 in security_batch_check.php.
    if (!isAuthEnabled()) {
        $mode = defined('AUTH_MODE') ? AUTH_MODE : 'unset';
        echo "  SKIPPED: the permission half needs AUTH_MODE=full, this deployment has '$mode'.\n";
        echo "  With permissions off no directory filter is applied by design, so there\n";
        echo "  is nothing to assert. Re-run with AUTH_MODE=full to cover it.\n";
    } else {

    // User restricted to the allowed root only.
    $utag = 'eiperm_' . bin2hex(random_bytes(3));
    $upass = 'pw' . bin2hex(random_bytes(6));
    $uuid = createUser($conn, $utag, $upass, false, true, [$allowed]);

    $jar2 = '/tmp/ep_' . bin2hex(random_bytes(4));
    @file_put_contents($jar2, '');
    [$s, $lhtml] = httpGet("$base/login.php", $jar2);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $lhtml, $m2);
    httpPost("$base/login.php", $jar2, ['username' => $utag, 'password' => $upass,
        'csrf_token' => $m2[1] ?? ''], true);

    [$st, $b] = httpPost("$base/api/get_unmapped_filters.php", $jar2, ['ids' => [$fidNo]]);
    $j = json_decode($b, true);
    $leaked = is_array($j) && in_array($filterNo, (array)($j['unmapped'] ?? []), true);
    check('filter of the denied root is NOT revealed',
        $st === 200 && is_array($j) && !$leaked,
        'HTTP ' . $st . ', leaked=' . var_export($leaked, true)
        . ', response=' . substr($b, 0, 90));

    // A file of the allowed root must instead pass the filter (or not appear
    // because its filter is mapped: in both cases it must not be an error).
    $rowOk = $conn->query("SELECT id FROM files
        WHERE SUBSTRING_INDEX(path,'/',1) = " . $conn->quote($allowed)
        . " AND deleted_at IS NULL AND filter IS NOT NULL AND TRIM(filter) != ''
          ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ((int)($rowOk['id'] ?? 0) > 0) {
        [$st, $b] = httpPost("$base/api/get_unmapped_filters.php", $jar2,
            ['ids' => [(int)$rowOk['id']]]);
        check('file of the allowed root -> 200 without errors',
            $st === 200 && is_array(json_decode($b, true)), 'HTTP ' . $st);
    }

    $conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uuid]);
    $conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uuid]);
    @unlink($jar2);
    }
}

$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);

// The JS must no longer put the ids in the URL.
$js = (string)file_get_contents('/var/www/html/assets/js/astrobin_export.js');
check('the JS no longer builds an id query string',
    !str_contains($js, 'idsQueryString'), '');
check('  and it posts both requests', substr_count($js, "postJson('/api/") === 2,
    substr_count($js, "postJson('/api/") . ' postJson calls');

echo "\nRESULT: " . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'the ids travel in the body') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed FAILURES.
exit($failed ? 1 : 0);