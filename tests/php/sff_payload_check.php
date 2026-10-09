<?php
// Check: find_calibration_files.php no longer carries the blobs in the response.
//
// The calibration search selected the thumb field (MEDIUMBLOB) for every row and inlined
// it as a data: URI inside the HTML, which in turn ended up inside a JSON
// string. Measured on this archive:
//
//   236 LIGHT files with a thumbnail, 8.3 MB of blobs
//   base64 adds 33%: 11.4 MB
//   response: 12.2 MB
//
// The text rows are a few tens of KB: the rest was bitmap. Now the table
// points at /image.php, which serves the same bytes while checking canAccessPath() and the
// browser downloads them only for the thumbnails it draws.
//
// Usage:  docker cp tmp/sff_payload_check.php awi-php:/tmp/
//         docker exec awi-php php /tmp/sff_payload_check.php

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-56s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

$base = 'http://nginx';
$conn = connectDB();

echo "\n=== the blob must no longer cross the query or the response ===\n";

$api = (string)file_get_contents('/var/www/html/api/find_calibration_files.php');
$tpl = (string)file_get_contents('/var/www/html/includes/sff_results_table.php');

// No base64 of the thumb: that was the dominant cost.
check('no base64_encode($file[thumb]) in the table',
    !str_contains($tpl, 'base64_encode($file[\'thumb\'])')
    && !str_contains($tpl, 'base64_encode($file["thumb"])'), '');
check('no inline data:image',
    !str_contains($tpl, 'data:image'), '');

// The reference to the endpoint that serves the bytes.
check('the thumbnail points at /image.php',
    (bool)preg_match('#<img src="/image\.php\?id=#', $tpl), '');

// The query must no longer select the blob, only a flag.
check('the query selects has_thumb, not thumb',
    str_contains($api, 'AS has_thumb'), '');
// Inspect the real column list, not the whole file: the flag
// (thumb IS NOT NULL ...) legitimately contains the word 'thumb', and a rough textual
// comparison would take it for the blob (false positive, already happened with
// the regular expression I wrote first).
preg_match('/\$sql\s*=\s*"(.*?)"\s*\.\s*implode/s', $api, $sm);
$selectList = $sm[1] ?? '';
$columns = array_map('trim', explode(',', $selectList));
$bare = array_values(array_filter($columns,
    fn($c) => strcasecmp($c, 'thumb') === 0 || stripos($c, 'thumb ') === 0));
check('  and the thumb field is no longer among the columns', $bare === [],
    $bare ? 'ANCORA SELEZIONATO: ' . implode(' | ', $bare) : 'colonne: ' . implode(', ', $columns));

// The existence check keeps working: the flag, not the blob.
check('the table checks has_thumb',
    str_contains($tpl, "!empty(\$file['has_thumb'])"), '');
check('the N/A branch stays',
    str_contains($tpl, "text-gray-500 text-xs\">N/A"), '');

echo "\n=== the real response ===\n";

$tag = 'sffpay_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $plain, false, true, ['/']);
$jar = '/tmp/sp_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');

function httpGet(string $url, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 300]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}
function httpPost(string $url, string $jar, $payload, bool $form = false): array
{
    // login.php reads from $_POST urlencoded: with an array and CURLOPT_POSTFIELDS it would
    // be multipart and the login would fail silently. The format is a flag, not inferred
    // from the type (traps #7 and #16).
    $body = $form ? http_build_query((array)$payload) : json_encode($payload);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . ($form
            ? 'application/x-www-form-urlencoded' : 'application/json')],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 300]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

[$s, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
httpPost("$base/login.php", $jar, ['username' => $tag, 'password' => $plain,
    'csrf_token' => $m[1] ?? ''], true);

$lightId = (int)$conn->query("SELECT id FROM files WHERE imgtype LIKE 'LIGHT%'
                               AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
check('test LIGHT', $lightId > 0, "id=$lightId");

// The widest search possible: a single filter, so everything comes back.
[$st, $b] = httpPost("$base/api/find_calibration_files.php", $jar,
    ['file_id' => $lightId, 'search_type' => 'lights', 'filters' => []]);
$j = json_decode($b, true);
$bytes = strlen($b);
printf("  response: HTTP %d, %s bytes (%.1f KB)\n", $st, number_format($bytes), $bytes / 1024);
check('the search answers 200', $st === 200, 'HTTP ' . $st);
check('  and stays under 1 MB', $bytes < 1048576,
    number_format($bytes) . ' byte, ' . ($bytes / 1048576) . ' MB');

// The comparison with the measured baseline: 12.2 MB before.
$baseline = 12200000;
$ratio = $bytes > 0 ? $baseline / $bytes : 0;
printf("  before: ~12.2 MB  ->  now: %.1f KB  (%.0fx reduction)\n",
    $bytes / 1024, $ratio);
check('at least a 20x reduction', $ratio > 20, sprintf('%.0fx', $ratio));

// And the table must still reference the thumbnails, not have lost them.
$hasImg = str_contains($b, '/image.php?id=');
check('the table still contains the <img> towards image.php', $hasImg,
    substr_count($b, '/image.php?id=') . ' riferimenti');
check('  and no leftover data: URI', !str_contains($b, 'data:image'), '');

$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);

// The change only makes sense if /image.php really serves the bytes with the URL now put in
// the table: otherwise the image breaks and the checks above would pass
// anyway. Same parameter used by file_cells.php:207, but verified here.
echo "\n=== /image.php serves the referenced thumbnail ===\n";
$thumbId = (int)$conn->query("SELECT id FROM files
    WHERE deleted_at IS NULL AND LENGTH(thumb) > 0 ORDER BY id LIMIT 1")->fetchColumn();
[$s2, $img] = httpGet("$base/image.php?id=$thumbId&type=thumb", $jar);
printf("  GET /image.php?id=%d&type=thumb -> HTTP %d, %d byte\n", $thumbId, $s2, strlen($img));
check('image.php answers 200', $s2 === 200, 'HTTP ' . $s2);
check('  and returns a PNG', substr($img, 1, 3) === 'PNG',
    $s2 === 200 ? substr($img, 1, 3) : 'nessun corpo');
check('  and the bytes are the thumbnail, not a placeholder',
    $s2 === 200 && strlen($img) > 200, strlen($img) . ' byte');

$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(test artifacts and user removed)\n";
echo 'RESULT: ' . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'the payload no longer contains the blobs') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed FAILURES.
exit($failed ? 1 : 0);
