<?php
// Check 13.1 — group thresholds.
//
// The unique index was (project_id, setup_id, panel_id, filter_name, exptime) with
// filter_name and exptime nullable. In MySQL/MariaDB two NULLs are not considered
// equal, so the constraint did not hold for groups with no filter anchor and/or no
// exposure anchor: saveGroupThresholds() used INSERT ... ON DUPLICATE KEY UPDATE,
// which never fired, and every save appended a row. Then
// getProjectThresholds() indexes by group and assigns without merging, so the duplicate
// row decided in a non-deterministic way which thresholds applied.
//
// Usage:  docker cp tmp/threshold_key_check.php awi-php:/tmp/
//         docker exec awi-php sh -c 'cd /tmp && php threshold_key_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/projects_functions.php';

$conn = connectDB();
$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-56s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

// A real setup and panel, to exercise projectOwnsNode() without building
// a test project.
$sid = (int)$conn->query('SELECT MIN(id) FROM project_setups')->fetchColumn();
$pid = $sid > 0
    ? (int)$conn->query('SELECT project_id FROM project_setups WHERE id = ' . $sid)->fetchColumn()
    : 0;
$pan = $sid > 0
    ? (int)$conn->query('SELECT MIN(id) FROM project_panels WHERE setup_id = ' . $sid)->fetchColumn()
    : 0;
check('real setup and panel found', $pid > 0 && $pan > 0, "proj=$pid setup=$sid panel=$pan");

/** Rows with a given group identity, NULLs included. */
function groupRows(PDO $conn, int $pid, int $sid, int $pan): int
{
    $s = $conn->prepare(
        'SELECT COUNT(*) FROM project_group_thresholds WHERE project_id = :p AND setup_id = :s '
        . 'AND panel_id = :n AND filter_name IS NULL AND exptime IS NULL'
    );
    $s->execute([':p' => $pid, ':s' => $sid, ':n' => $pan]);
    return (int)$s->fetchColumn();
}

/** Value of a metric for that group identity. */
function groupValue(PDO $conn, int $pid, int $sid, int $pan, string $col)
{
    $s = $conn->prepare(
        "SELECT `{$col}` FROM project_group_thresholds WHERE project_id = :p AND setup_id = :s "
        . 'AND panel_id = :n AND filter_name IS NULL AND exptime IS NULL ORDER BY id DESC'
    );
    $s->execute([':p' => $pid, ':s' => $sid, ':n' => $pan]);
    $v = $s->fetchColumn();
    return $v === false ? null : (float)$v;
}

// Everything inside a transaction: saveGroupThresholds() respects an already
// open transaction (it does not start its own and does not commit), so the final rollback
// leaves the DB as it was.
$conn->beginTransaction();
try {
    echo "\n=== repeated saves on a group with no filter and no exposure ===\n";
    // Three saves in a row: the final value must be the last one written and
    // the group must stay a single row.
    foreach ([2.0, 2.5, 3.0] as $hfr) {
        saveGroupThresholds($conn, $pid, $sid, $pan, null, null, ['hfr' => $hfr]);
    }
    $n = groupRows($conn, $pid, $sid, $pan);
    check('three saves -> a single row', $n === 1, "rows=$n" . ($n > 1 ? ' DUPLICATE' : ''));
    $v = groupValue($conn, $pid, $sid, $pan, 'hfr_max');
    check('the value is the one from the last save', $v === 3.0, 'hfr_max=' . var_export($v, true));

    // The public read must agree, and return a single entry per group.
    $all = getProjectThresholds($conn, $pid);
    $key = groupThresholdKey($sid, $pan, null, null);
    check('getProjectThresholds: one entry per group',
        count($all) === 1 && isset($all[$key]),
        'entries=' . count($all));
    check('getProjectThresholds: value consistent with the DB',
        isset($all[$key]) && ($all[$key]['hfr'] ?? null) === 3.0,
        'hfr=' . var_export($all[$key]['hfr'] ?? null, true));

    // The read specifies the order. Without ORDER BY, with duplicates present, the
    // row that wins is the one the engine returns last, and the read
    // assigns instead of merging: the result would depend on the physical order. On
    // InnoDB so far it happened to return the right one by chance, so this is not proof
    // of an observed defect but the statement of a dependency that no longer exists.
    //
    // The check looks at the SQL string, not at the function body: the body also
    // contains the comment explaining why, and a text search would confuse the two.
    $src = (string)file_get_contents('/var/www/html/includes/projects_functions.php');
    $fn = preg_match('/function getProjectThresholds\b[^{]*\{(.*?)\n\}/s', $src, $fm) ? $fm[1] : '';
    preg_match('/->prepare\(\s*"(.*?)"\s*\)/s', $fn, $sm);
    $sql = $sm[1] ?? '';
    check('getProjectThresholds sorts the SELECT by descending id',
        stripos($sql, 'ORDER BY id DESC') !== false,
        $sql === '' ? 'SQL not found' : ($sql === '' ? '' : preg_replace('/\s+/', ' ', $sql)));

    echo "\n=== the same thing with the filter present and the exposure absent ===\n";
    // One NULL in the tuple is enough: it is the most frequent case in practice, because
    // the anchor exposure is not always defined.
    foreach (['Ha' => [1.5, 1.8], 'OIII' => [2.2]] as $filter => $series) {
        foreach ($series as $hfr) {
            saveGroupThresholds($conn, $pid, $sid, $pan, $filter, null, ['hfr' => $hfr]);
        }
        $s = $conn->prepare('SELECT COUNT(*) FROM project_group_thresholds WHERE project_id = :p '
            . 'AND setup_id = :s AND panel_id = :n AND filter_name = :f AND exptime IS NULL');
        $s->execute([':p' => $pid, ':s' => $sid, ':n' => $pan, ':f' => $filter]);
        $cnt = (int)$s->fetchColumn();
        check("filter {$filter}: repeated saves -> a single row", $cnt === 1,
            "rows=$cnt" . ($cnt > 1 ? ' DUPLICATE' : ''));
    }

    echo "\n=== the DB really rejects the duplicate ===\n";
    // If the code no longer depends on the upsert, the constraint is the only defence
    // left: it has to hold on NULLs.
    $dupRejected = false;
    try {
        $conn->exec('INSERT INTO project_group_thresholds '
            . '(project_id, setup_id, panel_id, filter_name, exptime, hfr_max) '
            . "VALUES ({$pid}, {$sid}, {$pan}, NULL, NULL, 9.9)");
    } catch (PDOException $e) {
        $dupRejected = ($e->getCode() === '23000') || str_contains($e->getMessage(), '1062');
    }
    check('duplicate insert with NULL -> 1062', $dupRejected,
        $dupRejected ? 'rejected by the constraint' : 'ACCEPTED: the constraint does not cover NULLs');

    // And with the exposure filled in, where the constraint already held.
    $dupRejected2 = false;
    try {
        $conn->exec('INSERT INTO project_group_thresholds '
            . '(project_id, setup_id, panel_id, filter_name, exptime, hfr_max) '
            . "VALUES ({$pid}, {$sid}, {$pan}, 'Ha', 300.0, 9.9)");
        $conn->exec('INSERT INTO project_group_thresholds '
            . '(project_id, setup_id, panel_id, filter_name, exptime, hfr_max) '
            . "VALUES ({$pid}, {$sid}, {$pan}, 'Ha', 300.0, 8.8)");
    } catch (PDOException $e) {
        $dupRejected2 = ($e->getCode() === '23000') || str_contains($e->getMessage(), '1062');
    }
    check('duplicate insert without NULL -> 1062', $dupRejected2, '');

    echo "\n=== zeroing the thresholds removes the row ===\n";
    saveGroupThresholds($conn, $pid, $sid, $pan, null, null, []);
    check('all-NULL save -> row removed', groupRows($conn, $pid, $sid, $pan) === 0,
        'rows=' . groupRows($conn, $pid, $sid, $pan));

    echo "\n=== non-NULL metrics are not lost ===\n";
    saveGroupThresholds($conn, $pid, $sid, $pan, null, null, ['hfr' => 2.0, 'star_count' => 50]);
    check('partial save -> both metrics',
        groupValue($conn, $pid, $sid, $pan, 'hfr_max') === 2.0
        && groupValue($conn, $pid, $sid, $pan, 'stars_min') === 50.0,
        'hfr=' . var_export(groupValue($conn, $pid, $sid, $pan, 'hfr_max'), true)
        . ' stars=' . var_export(groupValue($conn, $pid, $sid, $pan, 'stars_min'), true));

    // The zeroing path must also clean up pre-existing duplicates: it is a
    // DELETE without LIMIT, so the row disappears either way.
    $conn->exec('DELETE FROM project_group_thresholds WHERE project_id = ' . $pid
        . ' AND setup_id = ' . $sid . ' AND panel_id = ' . $pan);
} catch (Throwable $e) {
    check('no unexpected exception', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
}

// After the rollback the group must be untouched.
$left = $conn->query('SELECT COUNT(*) FROM project_group_thresholds WHERE project_id = ' . $pid
    . ' AND setup_id = ' . $sid . ' AND panel_id = ' . $pan)->fetchColumn();
check('rollback: no threshold left behind', (int)$left === 0, 'rows=' . $left);

echo "\nRESULT: " . ($failed ? 'FAILED: ' . implode(', ', $failed) : 'threshold key correct') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed FAILURES.
exit($failed ? 1 : 0);
