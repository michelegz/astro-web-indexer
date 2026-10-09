<?php
// Exercise sweep of the four endpoints migrated to api_bootstrap.php in commit
// a42b36c, on the happy paths AND on the error paths.
//
// find_calibration_files.php had a dangling reference to $allFilters and died with a
// TypeError on every search with filters. Static analysis did not catch it, and my
// previous test passed because it checked the shape of the code with a regex. So
// no regex here: every response is really executed and the body is read
// looking for PHP traces, which is the symptom the user sees as
// "Unexpected token <".
//
// Usage:  docker cp tmp/api_bootstrap_sweep.php awi-php:/tmp/
//         docker exec awi-php php /tmp/api_bootstrap_sweep.php

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

$failed = [];
function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-52s %s\n", $label, $detail);
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

// Any PHP trace in the body is a defect: it is exactly what breaks the
// client's JSON.parse(), and on sff_get_filters.php it ends up in the modal.
function phpTraces(string $body): array
{
    $out = [];
    foreach (['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:',
        'Uncaught', 'Undefined variable', 'Undefined array key',
        'must be of type array, null given', 'Stack trace'] as $m) {
        if (stripos($body, $m) !== false) {
            $out[] = $m;
        }
    }
    return $out;
}

function call(string $url, string $jar, ?array $payload, string $ctype): array
{
    $ch = curl_init($url);
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 300];
    if ($payload !== null) {
        $opt[CURLOPT_POST] = true;
        $opt[CURLOPT_POSTFIELDS] = $ctype === 'form'
            ? http_build_query($payload) : json_encode($payload);
        $opt[CURLOPT_HTTPHEADER] = ['Content-Type: ' . ($ctype === 'form'
            ? 'application/x-www-form-urlencoded' : 'application/json')];
    }
    curl_setopt_array($ch, $opt);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return [$st, $b, $ct];
}

// sff_get_filters.php reads id and type from INPUT_GET and get_duplicates.php wants hash
// from $_GET: they are not JSON endpoints and do not accept POST. The first version of
// this sweep queried them with POST and got 400 on every row, including those labelled
// "happy path": I was measuring the rejection path and had called it success.
function callGet(string $url, string $jar, array $query): array
{
    return call($url . '?' . http_build_query($query), $jar, null, 'form');
}

$conn = connectDB();
$tag = 'sweep_' . bin2hex(random_bytes(3));
$pw = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $pw, false, true, ['/']);
$jar = '/tmp/sw_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
call('http://nginx/login.php', $jar, null, 'form');
[, $h] = call('http://nginx/login.php', $jar, null, 'form');
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $h, $m);
call('http://nginx/login.php', $jar,
    ['username' => $tag, 'password' => $pw, 'csrf_token' => $m[1] ?? ''], 'form');

$light = (int)$conn->query("SELECT id FROM files WHERE imgtype LIKE 'LIGHT%'
    AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
// The success path of get_duplicates uses the file_hash column: not 'hash', which
// does not exist in the files table (my first query got it wrong and the test died with
// "Unknown column 'hash'", which I could have mistaken for a defect of the endpoint).
$fileHash = (string)$conn->query("SELECT file_hash FROM files
    WHERE file_hash IS NOT NULL AND LENGTH(file_hash) > 8 AND deleted_at IS NULL
    ORDER BY id LIMIT 1")->fetchColumn();

// [label, endpoint, payload, expected type, method]
$cases = [
    ['get_duplicates: real file_hash', 'get_duplicates.php', ['hash' => $fileHash], 'json', 'get'],
    ['get_duplicates: nonexistent hash', 'get_duplicates.php', ['hash' => 'deadbeef'], 'json', 'get'],
    ['get_duplicates: empty hash', 'get_duplicates.php', ['hash' => ''], 'json', 'get'],
    ['get_duplicates: missing parameter', 'get_duplicates.php', [], 'json', 'get'],
    ['update_visibility: unknown action', 'update_visibility.php',
        ['action' => 'frobnicate', 'id' => 1, 'is_hidden' => 1], 'json', 'post'],
    ['update_visibility: non-numeric id', 'update_visibility.php',
        ['action' => 'toggle_visibility', 'id' => 'abc'], 'json', 'post'],
    ['update_visibility: empty payload', 'update_visibility.php', [], 'json', 'post'],
    ['sff_get_filters: real id and type', 'sff_get_filters.php',
        ['id' => $light, 'type' => 'lights', 'lang' => 'en'], 'html', 'get'],
    ['sff_get_filters: bias', 'sff_get_filters.php',
        ['id' => $light, 'type' => 'bias', 'lang' => 'en'], 'html', 'get'],
    ['sff_get_filters: invalid search_type', 'sff_get_filters.php',
        ['id' => $light, 'type' => 'plasma', 'lang' => 'en'], 'html', 'get'],
    ['sff_get_filters: missing id', 'sff_get_filters.php',
        ['type' => 'lights', 'lang' => 'en'], 'html', 'get'],
    ['sff_get_filters: non-numeric id', 'sff_get_filters.php',
        ['id' => 'abc', 'type' => 'lights', 'lang' => 'en'], 'html', 'get'],
    ['find_calibration: happy path with a filter', 'find_calibration_files.php',
        ['file_id' => $light, 'search_type' => 'lights',
            'filters' => [['id' => 'xbinning', 'type' => '=', 'ref_value' => '1', 'tolerance' => '1']]],
        'json', 'post'],
    ['find_calibration: empty payload', 'find_calibration_files.php', [], 'json', 'post'],
    ['find_calibration: nonexistent file_id', 'find_calibration_files.php',
        ['file_id' => 999999999, 'search_type' => 'lights'], 'json', 'post'],
];

foreach ($cases as [$label, $ep, $payload, $expect, $method]) {
    echo "\n$label\n";
    $url = "http://nginx/api/$ep";
    [$st, $b, $ct] = $method === 'get'
        ? callGet($url, $jar, $payload) : call($url, $jar, $payload, 'json');
    $traces = phpTraces($b);
    check('  no PHP trace in the body', $traces === [],
        $traces ? implode(', ', $traces) : '');
    check('  and no error <br />', !preg_match('#<br\s*/?>\s*<b>|<b>(Warning|Fatal|Notice)#i', $b), '');
    if ($expect === 'json' && $traces === []) {
        $j = json_decode($b, true);
        check('  JSON response or empty body', $j !== null || trim($b) === '',
            $j === null ? 'not JSON: ' . substr(preg_replace('/\s+/', ' ', $b), 0, 60) : 'JSON ok');
    }
    if ($expect === 'html') {
        check('  and it produces markup, not an error', strlen($b) > 0 && $traces === [],
            strlen($b) . ' bytes, HTTP ' . $st);
    }
    printf("  (HTTP %d, %s byte, %s)\n", $st, number_format(strlen($b)), $ct ?: 'no content-type');
}

$conn->prepare('DELETE FROM user_permissions WHERE user_id=:id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id=:id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(test user removed)\n";
echo 'RESULT: ' . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'no endpoint died with a filter') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed FAILURES.
exit($failed ? 1 : 0);