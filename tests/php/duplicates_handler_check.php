<?php
// Check 13.44 / 13.45 — a single handler for the duplicates.
//
// table.php contained two complete <script> blocks of the same function. The first
// bound the click directly to every .duplicate-badge; the second uses delegation
// on the body, which is the right one because it also catches badges added after
// DOMContentLoaded. Both were active, so a click on an existing badge
// ran the two handlers: double fetch and double render of the modal.
//
// The old block was also the less recent version: it lacked the sorting that
// puts the reference file first, the disabling of the buttons during the action,
// the badge refresh without reload, and above all escapeHTML(), which in the old
// block was an empty stub with the comment "same as before". That renderer without
// escaping was still reachable.
//
// Usage:  docker cp tmp/duplicates_handler_check.php awi-php:/tmp/
//         docker exec awi-php sh -c 'cd /tmp && php duplicates_handler_check.php'

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

function httpPost(string $url, string $jar, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

echo "\n=== 13.44 / 13.45: a single script block for the duplicates ===\n";

$src = (string)file_get_contents('/var/www/html/includes/table.php');
check("a single <script> block in table.php",
    substr_count($src, '<script>') === 1,
    substr_count($src, '<script>') . ' blocks');
check('no stubbed escapeHTML',
    !str_contains($src, 'same as before'),
    str_contains($src, 'same as before') ? 'STILL the stub' : '');
check('  escapeHTML is implemented',
    (bool)preg_match('/function escapeHTML\(str\)\s*\{\s*if \(!str\)/', $src), '');
check('a single handler: the delegation on the body',
    substr_count($src, "document.body.addEventListener('click'") === 1,
    substr_count($src, "document.body.addEventListener('click'") . ' delegations');
check('  no direct binding for every badge',
    !str_contains($src, "querySelectorAll('.duplicate-badge').forEach"),
    '');
check('the sorting with the reference file first is there',
    str_contains($src, 'a.path === referencePath'), '');
check('the buttons get disabled during the action',
    str_contains($src, 'hideBtn.disabled = true'), '');

echo "\n=== the real page no longer contains the two handlers ===\n";

$tag = 'dupchk_' . bin2hex(random_bytes(3));
$pw = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $pw, false, true, ['/']);
$jar = '/tmp/dc_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
[$s, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
httpPost("$base/login.php", $jar, ['username' => $tag, 'password' => $pw,
    'csrf_token' => $m[1] ?? '']);

[$s, $home] = httpGet("$base/", $jar);
$noise = [];
foreach (['Warning', 'Notice', 'Deprecated', 'Fatal error'] as $lvl) {
    if (stripos($home, "<b>$lvl</b>") !== false) {
        $noise[] = $lvl;
    }
}
check('the home page renders without PHP warnings',
    $s === 200 && $noise === [],
    'HTTP ' . $s . ', ' . strlen($home) . ' bytes' . ($noise ? ': ' . implode('/', $noise) : ''));

// The badge references must appear once per handler, not twice.
$badgeRefs = substr_count($home, "'.duplicate-badge'") + substr_count($home, '.duplicate-badge');
printf("  references to .duplicate-badge in the page: %d\n", $badgeRefs);
check('the "same as before" stub is not in the page',
    !str_contains($home, 'same as before'), '');
check('escapeHTML appears only once',
    substr_count($home, 'function escapeHTML') === 1,
    substr_count($home, 'function escapeHTML') . ' definitions');

// And the modal must exist only once.
check('a single duplicatesModal element',
    substr_count($home, 'id="duplicatesModal"') === 1,
    substr_count($home, 'id="duplicatesModal"') . ' occurrences');

$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(fixtures and test user removed)\n";
echo 'RESULT: ' . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'a single handler for the duplicates') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed FAILURES.
exit($failed ? 1 : 0);