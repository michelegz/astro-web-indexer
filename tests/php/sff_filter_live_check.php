<?php
// BEHAVIOURAL check of the calibration search with filters.
//
// This is the test that was missing. calib_filter_id_check.php checked the whitelist with
// a regex on the source and passed, while find_calibration_files.php died with
// "Uncaught TypeError: array_keys(): Argument #1 ($array) must be of type array, null
// given" on every search with at least one filter, which is the normal path of the SFF
// modal. $allFilters was a dangling reference: the shape of the code was correct, the
// variable did not exist. Only a real request reveals it.
//
// Usage:  docker cp tmp/sff_filter_live_check.php awi-php:/tmp/
//         docker exec awi-php php /tmp/sff_filter_live_check.php

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

$failed = [];
function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-58s %s\n", $label, $detail);
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

$conn = connectDB();
$tag = 'sfflive_' . bin2hex(random_bytes(3));
$pw = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $pw, false, true, ['/']);
$jar = '/tmp/sl_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');

function req(string $url, string $jar, ?array $payload = null): array
{
    $ch = curl_init($url);
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 300];
    if ($payload !== null) {
        $form = isset($payload['__form']);
        unset($payload['__form']);
        $opt[CURLOPT_POST] = true;
        $opt[CURLOPT_POSTFIELDS] = $form ? http_build_query($payload) : json_encode($payload);
        $opt[CURLOPT_HTTPHEADER] = ['Content-Type: ' . ($form
            ? 'application/x-www-form-urlencoded' : 'application/json')];
    }
    curl_setopt_array($ch, $opt);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

req('http://nginx/login.php', $jar);
[, $h] = req('http://nginx/login.php', $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $h, $m);
req('http://nginx/login.php', $jar, ['__form' => true, 'username' => $tag,
    'password' => $pw, 'csrf_token' => $m[1] ?? '']);

$ref = $conn->query("SELECT id, xbinning FROM files
    WHERE imgtype LIKE 'LIGHT%' AND deleted_at IS NULL AND xbinning > 0
    ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);

function search(string $jar, int $id, string $type, array $filters): array
{
    [$st, $b] = req('http://nginx/api/find_calibration_files.php', $jar,
        ['file_id' => $id, 'search_type' => $type, 'filters' => $filters]);
    return [$st, $b, json_decode($b, true)];
}

echo "\n=== the modal's path: a search with at least one filter ===\n";
// The payload sff.js really builds: filters with id, type, ref_value, tolerance.
[$st, $b, $j] = search($jar, (int)$ref['id'], 'lights',
    [['id' => 'xbinning', 'type' => '=', 'ref_value' => $ref['xbinning'], 'tolerance' => '1']]);

check('the response is valid JSON, not a PHP error page', $j !== null,
    $j === null ? substr(preg_replace('/\s+/', ' ', $b), 0, 90) . '...' : '');
check('  and it answers 200', $st === 200, 'HTTP ' . $st);
check('  with the expected keys', is_array($j) && isset($j['html'], $j['count']),
    is_array($j) ? implode(', ', array_keys($j)) : '');
if (is_array($j) && isset($j['html'])) {
    check('  and the html contains the references to image.php',
        str_contains($j['html'], '/image.php?id='), substr_count($j['html'], '/image.php?id=') . ' references');
    check('  and no data: URI', !str_contains($j['html'], 'data:image'), '');
}
check('the body contains no PHP traces', !preg_match('/<(br|b) ?\/?>|Fatal error|Warning:|Uncaught/',
    $b), '');

echo "\n=== several filters together, as the modal sends them ===\n";
[$st2, , $j2] = search($jar, (int)$ref['id'], 'lights', [
    ['id' => 'xbinning', 'type' => '=', 'ref_value' => $ref['xbinning'], 'tolerance' => '1'],
    ['id' => 'ccd_temp', 'type' => '=', 'ref_value' => '-10', 'tolerance' => '2'],
]);
check('two filters together answer 200 and JSON', $st2 === 200 && $j2 !== null,
    'HTTP ' . $st2 . ($j2 === null ? ', not JSON' : ''));

echo "\n=== the whitelist must still reject, with clean JSON ===\n";
foreach (['bogus_column', '', 'xbinning` OR 1=1 -- '] as $bad) {
    [$st3, $b3, $j3] = search($jar, (int)$ref['id'], 'lights',
        [['id' => $bad, 'type' => '=', 'ref_value' => '1', 'tolerance' => '1']]);
    $clean = $j3 !== null && isset($j3['error']);
    check('id "' . $bad . '" rejected', $st3 === 400 && $clean,
        'HTTP ' . $st3 . ($clean ? ' with a JSON error' : ' NOT A JSON RESPONSE'));
}
[$st4, , $j4] = search($jar, (int)$ref['id'], 'lights',
    [['id' => 'nope', 'type' => '=', 'ref_value' => '1', 'tolerance' => '1']]);
check('the error response lists the valid ids', isset($j4['valid']) && is_array($j4['valid'])
    && in_array('xbinning', $j4['valid'], true), isset($j4['valid']) ? count($j4['valid']) . ' ids' : '');

echo "\n=== the branch without filters keeps working too ===\n";
[$st5, , $j5] = search($jar, (int)$ref['id'], 'lights', []);
check('empty filters answers 200 and JSON', $st5 === 200 && $j5 !== null, 'HTTP ' . $st5);

$conn->prepare('DELETE FROM user_permissions WHERE user_id=:id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id=:id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(fixture and test user removed)\n";
echo 'RESULT: ' . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'the filtered search answers, and the whitelist rejects') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed FAILURES.
exit($failed ? 1 : 0);