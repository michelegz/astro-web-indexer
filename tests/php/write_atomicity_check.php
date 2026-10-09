<?php
// Check 13.3, 13.4, 13.32 — write atomicity and Error handling.
//
//  13.3 saveProjectFilterAliases wrote pair by pair and validated/committed in
//       more than one statement: a loop blowing up on the fourth pair left the first three
//       committed, and the error message told the user everything had gone
//       well. Plus the window in which a removed alias had not been reinserted yet.
//  13.4 enqueueSuggestRequest was a read-then-write: two writers could both
//       pass the SELECT and queue two pending rows, hence two Python backfills.
//  13.32 in project_tree_preview the buffer opened around the tree include was not
//       closed on error, and the catches were on Exception: in PHP 8
//       TypeError and ParseError are Error and were not caught, also leaving the
//       transaction open.
//
// Usage:  docker cp tmp/write_atomicity_check.php awi-php:/tmp/
//         docker exec awi-php sh -c 'cd /tmp && php write_atomicity_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/projects_functions.php';

// saveProjectFilterAliases uses __() for the loop's message. language.php wants
// a session (which does not start from the CLI), and here only the throwing branch is
// checked: the key is enough, not the translated text.
if (!function_exists('__')) {
    function __(string $key, array $replace = []): string
    {
        return $key;
    }
}

$conn = connectDB();
$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-58s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

$pid = (int)$conn->query('SELECT MIN(id) FROM projects')->fetchColumn();
check('test project found', $pid > 0, "id=$pid");

// =====================================================================
// 13.3 — filter aliases: no partial application
// =====================================================================
echo "\n=== 13.3: a loop must not leave the earlier pairs behind ===\n";

// Known starting state: no alias for this project.
$conn->beginTransaction();
try {
    foreach (getProjectFilterAliases($conn, $pid) as $a => $c) {
        $conn->prepare('DELETE FROM project_filter_aliases WHERE project_id = ? AND alias = ?')
            ->execute([$pid, $c === null ? '' : $a]);
    }

    // First pair valid, second creating a cycle with the first (the canonical
    // 'Ha' is already an alias). The validation must reject EVERYTHING.
    $threw = null;
    try {
        saveProjectFilterAliases($conn, $pid, [
            'H-ALPHA' => 'Ha',   // valid
            'OIII-3nm' => 'H-ALPHA', // cycle: 'H-ALPHA' is already an alias
        ]);
    } catch (InvalidArgumentException $e) {
        $threw = $e->getMessage();
    }
    check('the cycle is rejected', $threw !== null, $threw === null ? 'NOT rejected' : '');

    $left = getProjectFilterAliases($conn, $pid);
    check('no pair partially applied', $left === [],
        $left === [] ? '' : 'leftovers: ' . implode(', ', array_map(
            fn($a, $c) => "$a=>$c", array_keys($left), $left)));
} finally {
    $conn->rollBack();
}

echo "\n=== 13.3: a valid set is applied whole ===\n";
$conn->beginTransaction();
try {
    saveProjectFilterAliases($conn, $pid, ['H-ALPHA' => 'Ha', 'OIII-3nm' => 'OIII']);
    $got = getProjectFilterAliases($conn, $pid);
    check('both pairs written',
        ($got['h-alpha'] ?? null) === 'Ha' && ($got['oiii-3nm'] ?? null) === 'OIII',
        json_encode($got));

    // Overwriting must not create a second row for the same alias.
    saveProjectFilterAliases($conn, $pid, ['H-ALPHA' => 'H-alpha2']);
    $n = (int)$conn->query("SELECT COUNT(*) FROM project_filter_aliases WHERE project_id = " . $pid
        . " AND LOWER(alias) = 'h-alpha'")->fetchColumn();
    check('the rewrite does not duplicate the row', $n === 1, 'rows=' . $n);

    // Deletion with an empty canonical.
    saveProjectFilterAliases($conn, $pid, ['OIII-3nm' => '']);
    $got = getProjectFilterAliases($conn, $pid);
    check('empty canonical removes the pair', !isset($got['oiii-3nm']), json_encode($got));
} finally {
    $conn->rollBack();
}

echo "\n=== 13.3: writes inside an outer transaction ===\n";
// If the caller already has a transaction, the function must not commit: the
// caller's rollback must undo everything. This is the case of project_add.php.
// PDO has no nested transactions, so this is simulated by opening a single
// transaction and calling the function inside it.
$conn->beginTransaction();
check('caller transaction open', $conn->inTransaction());
saveProjectFilterAliases($conn, $pid, ['L' => 'L-Test']);
$stillInside = (int)$conn->query('SELECT COUNT(*) FROM project_filter_aliases WHERE project_id = '
    . $pid . " AND alias = 'L'")->fetchColumn();
$conn->rollBack();
$afterRollback = (int)$conn->query('SELECT COUNT(*) FROM project_filter_aliases WHERE project_id = '
    . $pid . " AND alias = 'L'")->fetchColumn();
check('the function did not commit on its own (rollback undoes everything)',
    $stillInside === 1 && $afterRollback === 0,
    "inside=$stillInside after rollback=$afterRollback");

// =====================================================================
// 13.4 — one pending request per project
// =====================================================================
echo "\n=== 13.4: the constraint rejects the second pending ===\n";

$conn->beginTransaction();
try {
    // Cleanup: no pending request for this project.
    $conn->exec("UPDATE suggest_requests SET status = 'done' WHERE project_id = {$pid}"
        . " AND status = 'pending'");

    // Two direct inserts, as two concurrent writers would do.
    $first = $conn->exec("INSERT INTO suggest_requests (project_id, reason, status) "
        . "VALUES ({$pid}, 'manual', 'pending')");
    $secondRejected = false;
    try {
        $conn->exec("INSERT INTO suggest_requests (project_id, reason, status) "
            . "VALUES ({$pid}, 'panel_created', 'pending')");
    } catch (PDOException $e) {
        $secondRejected = ($e->getCode() === '23000') || str_contains($e->getMessage(), '1062');
    }
    check('the second pending is rejected by the DB',
        $first === 1 && $secondRejected,
        $secondRejected ? '1062' : 'ACCEPTED: two pending for the same project');

    // The non-pending state stays free: the constraint covers only the queue.
    $conn->exec("UPDATE suggest_requests SET status = 'done' WHERE project_id = {$pid}"
        . " AND status = 'pending'");
    $ok = 0;
    for ($i = 0; $i < 3; $i++) {
        $conn->exec("INSERT INTO suggest_requests (project_id, reason, status) "
            . "VALUES ({$pid}, 'manual', 'done')");
        $ok++;
    }
    check('closed rows are not limited to one', $ok === 3, "inserted=$ok");

    // enqueueSuggestRequest must coalesce: two calls, a single pending row.
    $conn->exec("UPDATE suggest_requests SET status = 'done' WHERE project_id = {$pid}"
        . " AND status = 'pending'");
    $r1 = enqueueSuggestRequest($conn, $pid, 'manual');
    $r2 = enqueueSuggestRequest($conn, $pid, 'manual');
    $pend = (int)$conn->query("SELECT COUNT(*) FROM suggest_requests WHERE project_id = {$pid}"
        . " AND status = 'pending'")->fetchColumn();
    check('enqueueSuggestRequest coalesces two calls',
        $r1 === true && $r2 === true && $pend === 1,
        "first={$r1} second={$r2} pending={$pend}");
} finally {
    $conn->rollBack();
}

echo "\n=== 13.4: the real error is no longer hidden ===\n";
// The final catch must record the error instead of returning false silently:
// otherwise the queue stops enqueuing and nobody notices.
$src = (string)file_get_contents('/var/www/html/includes/projects_functions.php');
$fn = preg_match('/function enqueueSuggestRequest\b[^{]*\{(.*)\n\}/s', $src, $fm) ? $fm[1] : '';
check('enqueueSuggestRequest records unexpected errors',
    str_contains($fn, "error_log(") && str_contains($fn, '1062'),
    str_contains($fn, "error_log(") ? '' : 'no error_log');

// =====================================================================
// 13.32 — the tree preview must not emit HTML before the JSON
// =====================================================================
echo "\n=== 13.32: buffer and catch in the four JSON endpoints ===\n";

foreach (['project_add.php', 'project_preview.php', 'project_tree_preview.php',
    'project_export_preview.php'] as $ep) {
    $srcEp = (string)file_get_contents('/var/www/html/api/' . $ep);
    // No catch on Exception: in PHP 8 it does not cover Error, so a TypeError
    // would escape as fatal and leave the transaction open.
    $bareException = (bool)preg_match('/catch\s*\(\s*Exception\b/', $srcEp);
    check("$ep: no catch (Exception)", !$bareException,
        $bareException ? 'STILL on Exception' : '');
}

// The delicate part: the buffer around the include must be closed on error too,
// otherwise the partial HTML precedes the JSON and neither is parseable.
$tree = (string)file_get_contents('/var/www/html/api/project_tree_preview.php');
check('the tree buffer is closed in finally',
    str_contains($tree, '} finally {') && str_contains($tree, 'ob_get_level()'),
    '');
check('the rollback is in finally, not only in the catch',
    (bool)preg_match('/} finally \{.*?rollBack.*?\}/s', $tree), '');

// And the endpoint must still answer properly on the happy path.
$base = 'http://nginx';
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
/**
 * $form = true sends urlencoded (login.php and the forms), otherwise JSON like the
 * four endpoints do. Not inferred from the type: the endpoints have array payloads, which
 * on their own would pass for a form.
 */
function httpPost(string $url, string $jar, $payload, bool $form = false): array
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

$tag = 'watomic_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $plain, false, true, ['/']);
$fid = (int)$conn->query("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype='LIGHT'
                           AND date_obs IS NOT NULL ORDER BY id LIMIT 1")->fetchColumn();
$jar = '/tmp/wa_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
[$s, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
httpPost("$base/login.php", $jar, ['username' => $tag, 'password' => $plain,
    'csrf_token' => $m[1] ?? ''], true);

[$st, $b] = httpPost("$base/api/project_tree_preview.php", $jar,
    ['ids' => [$fid], 'project_id' => $pid, 'overrides' => []]);
$j = json_decode($b, true);
check('tree preview: 200 with clean JSON',
    $st === 200 && is_array($j) && isset($j['html']) && strlen($j['html']) > 0,
    'HTTP ' . $st . ', html ' . strlen($j['html'] ?? '') . ' bytes');
check('tree preview: no HTML before the JSON',
    !str_contains(substr($b, 0, 40), '<div'), substr(trim($b), 0, 40));

// And the tree preview rollback must leave no trace: the project must have
// exactly the rows it had before.
$before = (int)$conn->query('SELECT COUNT(*) FROM project_files WHERE node_id = '
    . $pid . " AND level = 'project'")->fetchColumn();
[$st2, $b2] = httpPost("$base/api/project_tree_preview.php", $jar,
    ['ids' => [$fid], 'project_id' => $pid, 'overrides' => []]);
$after = (int)$conn->query('SELECT COUNT(*) FROM project_files WHERE node_id = '
    . $pid . " AND level = 'project'")->fetchColumn();
check('two previews leave no hypothetical links', $before === $after,
    "before={$before} after={$after}");

$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(test artifacts and user removed)\n";
echo 'RESULT: ' . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'atomic writes and Error handling') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed FAILURES.
exit($failed ? 1 : 0);
