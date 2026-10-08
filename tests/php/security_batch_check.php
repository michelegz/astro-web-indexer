<?php
// Check 13.36, 13.37, 13.38, 13.39 — security batch.
//
//  13.36 the ZIP form had no CSRF token and the endpoint did not ask for one
//  13.37 the path containment test accepted a sibling directory
//  13.38 json_encode without flags and without checking the false
//  13.39 the AstroBin CSV did not apply the directory permissions
//
// It does a real login and calls the endpoints over HTTP, plus a test of the real
// filesystem for the path boundary.
//
// Usage:  docker cp tmp/security_batch_check.php awi-php:/tmp/
//         docker exec awi-php sh -c 'cd /tmp && php security_batch_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

// This test runs both on the pre-fix code and on the correct one, so that it can
// demonstrate that it fails before and passes after. The functions introduced by the
// fixes must therefore not be a prerequisite: if they are missing, it falls back to the
// previous behavior and the corresponding assertions fail. file_exists is needed because
// a failed require is fatal and @ does not silence it.
if (file_exists('/var/www/html/includes/http_json.php')) {
    require_once '/var/www/html/includes/http_json.php';
}

function preFixIsPathWithinRoot(string $fullPath, string $realRoot): bool
{
    // The original predicate: prefix without a separator.
    return str_starts_with($fullPath, $realRoot);
}

if (!function_exists('isPathWithinRoot')) {
    function isPathWithinRoot(string $fullPath, string $realRoot): bool
    {
        return preFixIsPathWithinRoot($fullPath, $realRoot);
    }
    $GLOBALS['helperMissing'] = true;
} else {
    $GLOBALS['helperMissing'] = false;
}

if (!function_exists('awiJsonString')) {
    function awiJsonString($payload, int $flags = 0)
    {
        // The original encoding: no flags, no check.
        return json_encode($payload, $flags);
    }
    $GLOBALS['jsonHelperMissing'] = true;
} else {
    $GLOBALS['jsonHelperMissing'] = false;
}

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

// ---------------------------------------------------------------- 13.37
echo "\n=== 13.37: the path boundary requires the separator ===\n";

// Real structure on disk: a root and a sibling directory whose name
// starts with that of the root. It is the case that a str_starts_with() without a
// separator let through.
$labRoot = '/tmp/awi_rootlab';
$rootDir = $labRoot . '/fits';
$sibling = $labRoot . '/fits-archive';
@mkdir($rootDir . '/sub', 0777, true);
@mkdir($sibling, 0777, true);
file_put_contents($rootDir . '/inside.fits', 'x');
file_put_contents($rootDir . '/sub/deep.fits', 'x');
file_put_contents($sibling . '/outside.fits', 'x');

if (!empty($GLOBALS['helperMissing'])) {
    echo "  (isPathWithinRoot absent: the pre-fix code is being tried)\n";
}

$realRoot = realpath($rootDir);
check('root and sibling created in /tmp', $realRoot !== false && is_dir($sibling),
    $realRoot . ' / ' . basename($sibling));

// The production predicate, applied to the three real cases.
$inside = (string)realpath($rootDir . '/inside.fits');
$deep = (string)realpath($rootDir . '/sub/deep.fits');
$outside = (string)realpath($sibling . '/outside.fits');

check('file inside the root accepted', isPathWithinRoot($inside, (string)$realRoot));
check('nested file accepted', isPathWithinRoot($deep, (string)$realRoot));
check('file in the sibling REJECTED (the defect)', !isPathWithinRoot($outside, (string)$realRoot),
    $outside);
check('the root itself accepted', isPathWithinRoot((string)$realRoot, (string)$realRoot));

// The old predicate, to show that the case really exists and is not a test
// that passes by construction.
$oldAccepts = preFixIsPathWithinRoot($outside, (string)$realRoot);
check('the previous predicate accepted the sibling (why the fix)',
    $oldAccepts, $oldAccepts ? 'confirmed' : 'NOT reproduced');

// And the two call sites must use the new predicate.
foreach (['/var/www/html/download.php', '/var/www/html/api/export_project_zip.php'] as $srcFile) {
    $src = (string)file_get_contents($srcFile);
    $usesNew = str_contains($src, 'isPathWithinRoot(');
    $usesBare = (bool)preg_match('/str_starts_with\(\s*\$(fullPath|filePath)\s*,\s*(realpath\(\$fitsRoot\)|\$realRoot\s*\)\s*)/', $src);
    check(basename(dirname($srcFile)) . '/' . basename($srcFile) . ' uses the predicate',
        $usesNew && !$usesBare, $usesBare ? 'STILL the bare comparison' : '');
}

// ---------------------------------------------------------------- 13.36
echo "\n=== 13.36: the ZIP download asks for the CSRF token ===\n";

$tag = 'secbat_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
// can_download true: without it the endpoint would answer 403 for the permission and the
// test would pass without ever reaching the CSRF check.
$uid = createUser($conn, $tag, $plain, false, true, ['/']);
$pid = (int)$conn->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();

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
    // Explicit http_build_query(): with an array curl would send multipart.
    $body = is_array($payload) ? http_build_query($payload) : (string)$payload;
    $ctype = is_array($payload) ? 'application/x-www-form-urlencoded' : 'application/json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . $ctype],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

/**
 * POST that stops after the first $want bytes.
 * The archive of a real project easily exceeds the 512M memory_limit and
 * curl would buffer all of it: only the initial signature is needed here.
 */
function httpPostHead(string $url, string $jar, array $payload, int $want = 8): array
{
    $head = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => false, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
        CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$head, $want): int {
            $head .= substr($chunk, 0, max(0, $want - strlen($head)));
            return strlen($chunk);
        },
    ]);
    curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return [$st, $head, $ctype];
}

$jar = '/tmp/sb_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
[$s, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
httpPost("$base/login.php", $jar, ['username' => $tag, 'password' => $plain,
    'csrf_token' => $m[1] ?? '']);
// projects.php redirects to the home: that is where the forms with the token are.
[$sHome, $home] = httpGet("$base/?panel=projects", $jar);
check('valid session (projects home rendered)',
    $sHome === 200 && str_contains($home, 'csrf_token') && !str_contains($home, 'name="password"'),
    'HTTP ' . $sHome . ', ' . strlen($home) . ' bytes');

// The detail panel arrives via AJAX, so the ZIP form is not in the initial
// HTML: the token is taken from any form on the page, and the field in the
// ZIP form is verified on the source.
[$sPage, $page] = httpGet("$base/?panel=projects", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page, $fm);
check('CSRF token available in the page', isset($fm[1]),
    isset($fm[1]) ? substr($fm[1], 0, 12) . '...' : 'ABSENT');

$src = (string)file_get_contents('/var/www/html/projects.php');
// The form block has to be isolated first: with a .*? lazy up to "csrf_token" the
// comparison would cross </form> and hook the token of a later form,
// making the assertion pass for the wrong reason.
$zipBlock = preg_match('/<form[^>]*id="zipDownloadForm"[^>]*>(.*?)<\/form>/s', $src, $zm)
    ? $zm[1] : '';
$hasZipToken = $zipBlock !== '' && str_contains($zipBlock, 'name="csrf_token"');
check('the ZIP form contains the csrf_token field', $hasZipToken,
    $hasZipToken ? 'source updated' : 'ABSENT: the form does not carry the token');

// POST without a token: it must be rejected. Before, the archive went out, so here
// only the signature is read: pre-fix the response would be a whole ZIP and the test
// would exhaust memory instead of reporting the defect.
[$st, $head] = httpPostHead("$base/api/export_project_zip.php", $jar, ['project_id' => $pid], 4);
check('without a token -> 403', $st === 403, 'HTTP ' . $st . ', signature=' . bin2hex($head));

// POST with a wrong token.
[$st, $head] = httpPostHead("$base/api/export_project_zip.php", $jar,
    ['project_id' => $pid, 'csrf_token' => str_repeat('0', 64)], 4);
check('with a fake token -> 403', $st === 403, 'HTTP ' . $st . ', signature=' . bin2hex($head));

// Invalid project_id without a token: the 400 comes first, and that is fine.
[$st, $b] = httpPost("$base/api/export_project_zip.php", $jar, ['project_id' => 0]);
check('missing project_id -> 400 (validation before CSRF)', $st === 400, "HTTP $st");

// With the right token the archive really goes out: the fix must not block the use.
if (isset($fm[1])) {
    [$st, $head, $ctype] = httpPostHead("$base/api/export_project_zip.php", $jar,
        ['project_id' => $pid, 'csrf_token' => $fm[1]], 4);
    check('with a valid token -> ZIP archive', $st === 200 && strncmp($head, "PK\x03\x04", 4) === 0,
        'HTTP ' . $st . ', signature="' . bin2hex($head) . '", ' . $ctype);
}

// ---------------------------------------------------------------- 13.38
echo "\n=== 13.38: the JSON encoding must not empty the response ===\n";

// Non-UTF-8 bytes: files.name arrives from the filesystem and is a VARCHAR on a
// utf8mb4 connection, so it can contain bytes that MySQL does not recode.
$bad = "light \xB1\x31 sub";

// The pre-fix way: no flags, no false check.
$preFix = json_encode(['name' => $bad]);
check('without a flag json_encode returns false', $preFix === false,
    $preFix === false ? 'confirmed, echo false = empty body' : 'not reproduced');

// With the flag: the response stays usable.
$withFlag = json_encode(['name' => $bad], JSON_INVALID_UTF8_SUBSTITUTE);
check('with JSON_INVALID_UTF8_SUBSTITUTE it encodes', is_string($withFlag) && $withFlag !== '',
    (string)$withFlag);

$round = json_decode((string)$withFlag, true);
check('the result is decodable and preserves the structure',
    is_array($round) && array_key_exists('name', $round));

// A value that stays unencodable: the fallback must return valid JSON,
// not false, otherwise addFileFromPath would fail and abort the archive.
$manifest = awiJsonString(['blob' => "\xB1\x31"]);
check('awiJsonString never returns false', is_string($manifest) && $manifest !== ''
    && json_decode($manifest, true) !== null, substr((string)$manifest, 0, 60));

// The four projects endpoints must have moved to awiJson, not to
// echo json_encode: otherwise the false stays uncovered.
foreach (['project_add.php', 'project_preview.php', 'project_tree_preview.php',
    'project_export_preview.php'] as $ep) {
    $src = (string)file_get_contents('/var/www/html/api/' . $ep);
    $bare = substr_count($src, 'echo json_encode');
    check($ep . ' without a bare echo json_encode', $bare === 0, $bare ? "$bare left" : '');
}

// And they must still answer properly.
[$st, $b] = httpPost("$base/api/project_export_preview.php", $jar, json_encode(['project_id' => $pid]));
$j = json_decode($b, true);
check('project_export_preview.php -> valid JSON',
    $st === 200 && is_array($j) && isset($j['entries']),
    'HTTP ' . $st . ', entries=' . (isset($j['entries']) ? count($j['entries']) : '?'));

// ---------------------------------------------------------------- 13.39
echo "\n=== 13.39: the AstroBin CSV applies the directory permissions ===\n";

// This section is only meaningful when directory permissions are actually enforced.
// With AUTH_MODE=none isAuthEnabled() is false, getAllowedDirs() returns null and
// buildDirPermissionFilter() deliberately emits no filter, so a restricted user gets
// every row: that is the documented behaviour of the 'none' mode, not a defect.
// Without this guard the check below reports a leak that cannot exist in this
// deployment, and the run goes red for the wrong reason.
if (!isAuthEnabled()) {
    $mode = defined('AUTH_MODE') ? AUTH_MODE : 'unset';
    echo "  SKIPPED: 13.39 needs AUTH_MODE=full, this deployment has '$mode'.\n";
    echo "  With permissions off no directory filter is applied by design, so there\n";
    echo "  is nothing to assert. Re-run with AUTH_MODE=full to cover it.\n";
} else {

// User with access to one root only. A root other than '/' is needed because
// the administrator has no restrictions.
$roots = $conn->query("SELECT DISTINCT SUBSTRING_INDEX(path, '/', 1) AS r FROM files
                       WHERE path IS NOT NULL AND path LIKE '%/%' ORDER BY r")
    ->fetchAll(PDO::FETCH_COLUMN);
$allowed = null;
$denied = null;
foreach ($roots as $r) {
    $other = null;
    foreach ($roots as $cand) {
        if ($cand !== $r) {
            $other = $cand;
            break;
        }
    }
    if ($other !== null) {
        $allowed = (string)$r;
        $denied = (string)$other;
        break;
    }
}

if ($allowed === null || $denied === null) {
    check('two distinct roots in the DB', false, 'insufficient fixture');
} else {
    $fidOk = (int)$conn->query("SELECT id FROM files WHERE SUBSTRING_INDEX(path,'/',1) = "
        . $conn->quote($allowed) . " AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    $fidNo = (int)$conn->query("SELECT id FROM files WHERE SUBSTRING_INDEX(path,'/',1) = "
        . $conn->quote($denied) . " AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();

    check('test file in both roots', $fidOk > 0 && $fidNo > 0,
        "allowed={$allowed}/#{$fidOk} denied={$denied}/#{$fidNo}");

    // User restricted to the allowed root only.
    $utag = 'secperm_' . bin2hex(random_bytes(3));
    $upass = 'pw' . bin2hex(random_bytes(6));
    $uuid = createUser($conn, $utag, $upass, false, true, [$allowed]);

    $jar2 = '/tmp/sp_' . bin2hex(random_bytes(4));
    @file_put_contents($jar2, '');
    [$s, $lhtml] = httpGet("$base/login.php", $jar2);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $lhtml, $m2);
    httpPost("$base/login.php", $jar2, ['username' => $utag, 'password' => $upass,
        'csrf_token' => $m2[1] ?? '']);

    // Only the allowed file: it must come through.
    [$st, $b] = httpGet("$base/api/export_astrobin_csv.php?ids=$fidOk", $jar2);
    $rows = array_filter(explode("\n", $b));
    check('authorized file -> rows in the CSV', $st === 200 && count($rows) > 1,
        'HTTP ' . $st . ', ' . count($rows) . ' rows');

    // Only the denied file: before, a row full of metadata came back anyway.
    [$st, $b] = httpGet("$base/api/export_astrobin_csv.php?ids=$fidNo", $jar2);
    $rows = array_values(array_filter(explode("\n", $b)));
    $dataRow = null;
    foreach ($rows as $r) {
        if (!str_starts_with($r, 'date,')) {
            $dataRow = $r;
            break;
        }
    }
    // Only the allowed row: the calibrations-only one, with future dates and the
    // counters at zero. Any data of the denied file would fill it.
    $isEmptyCalRow = $dataRow !== null
        && str_starts_with($dataRow, date('Y-m-d', strtotime('12 hours ago')) . ',')
        && str_contains($dataRow, ',,0,') ;
    check('NOT authorized file -> no metadata', $st === 200 && $isEmptyCalRow,
        'HTTP ' . $st . ', row="' . substr((string)$dataRow, 0, 90) . '"');

    // Both together: only the authorized one.
    [$st, $b] = httpGet("$base/api/export_astrobin_csv.php?ids=$fidOk,$fidNo", $jar2);
    check('mixed list -> only the row of the authorized file', $st === 200
        && substr_count(trim($b), "\n") === 1,
        'data rows=' . max(0, substr_count(trim($b), "\n")));

    $conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uuid]);
    $conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uuid]);
    @unlink($jar2);
}

}

// ---------------------------------------------------------------- cleanup
$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
exec('rm -rf ' . escapeshellarg($labRoot));
echo "\n(test artifacts and users removed)\n";
echo 'RESULT: ' . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'security batch ok');
echo "\n";
// The exit code is what run.sh records. Without it the script falls off the end
// and returns 0 even with failed checks, so a red check is reported as PASS:
// a test that cannot fail is not a test.
exit($failed ? 1 : 0);
