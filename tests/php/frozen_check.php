<?php
// Check §6 — the "frozen" mode must block the writes.
//
// It exercises projectAddPrepare() + projectAddFiles() exactly as
// src/api/project_add.php does (transaction included) and compares the state of the
// project before/after. It does not go through HTTP: $_SESSION is populated in-process,
// because from the CLI /tmp is not writable and session_start() would fail.
//
// It covers the reported case: frozen project + "new:<name>" override, which
// used to write project_setups and setup_overrides and then answer "added 0".
//
// Usage:  docker cp tmp/frozen_check.php awi-php:/var/www/html/
//         docker exec awi-php php /var/www/html/frozen_check.php
//
// It creates and then removes two test projects.

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
require_once '/var/www/html/includes/projects_functions.php';

// isLoggedIn()/canDownload() read $_SESSION: populating it is enough.
$_SESSION = ['user_id' => 1, 'username' => 'admin', 'is_admin' => 1,
    'can_download' => 1, 'allowed_dirs' => ['/']];

$conn = connectDB();

$fid = (int)$conn->query("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype = 'LIGHT' "
    . 'AND date_obs IS NOT NULL ORDER BY id LIMIT 1')->fetchColumn();
if (!$fid) {
    echo "no LIGHT file with a date: the test cannot run\n";
    exit(1);
}

// project_files is keyed by (file_id, level, node_id): there is no
// project_files.setup_id, and the lights end up at level 'filter', not 'setup'.
function snapshot(PDO $conn, int $pid): array
{
    $q = fn(string $sql) => (int)$conn->query($sql)->fetchColumn();
    return [
        'setups' => $q("SELECT COUNT(*) FROM project_setups WHERE project_id = $pid"),
        'panels' => $q("SELECT COUNT(*) FROM project_panels WHERE setup_id IN "
            . "(SELECT id FROM project_setups WHERE project_id = $pid)"),
        'links' => $q("SELECT COUNT(*) FROM project_files pf
            WHERE (pf.level='setup'  AND pf.node_id IN (SELECT id FROM project_setups WHERE project_id = $pid))
               OR (pf.level='panel'  AND pf.node_id IN (SELECT p.id FROM project_panels p
                     JOIN project_setups s ON s.id=p.setup_id WHERE s.project_id = $pid))
               OR (pf.level='filter' AND pf.node_id IN (SELECT ss.id FROM project_sessions ss
                     JOIN project_panels p ON p.id=ss.panel_id
                     JOIN project_setups s ON s.id=p.setup_id WHERE s.project_id = $pid))"),
        'overrides' => $q("SELECT COUNT(*) FROM setup_overrides WHERE setup_id IN "
            . "(SELECT id FROM project_setups WHERE project_id = $pid)"),
    ];
}

function purge(PDO $conn, int $pid): void
{
    $setups = $conn->query("SELECT id FROM project_setups WHERE project_id = $pid")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($setups as $s) {
        $panels = $conn->query("SELECT id FROM project_panels WHERE setup_id = $s")
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($panels as $p) {
            $sess = $conn->query("SELECT id FROM project_sessions WHERE panel_id = $p")
                ->fetchAll(PDO::FETCH_COLUMN);
            foreach ($sess as $ss) {
                $conn->prepare("DELETE FROM project_files WHERE level='filter' AND node_id = :n")
                    ->execute([':n' => $ss]);
            }
            $conn->prepare("DELETE FROM project_sessions WHERE panel_id = :p")->execute([':p' => $p]);
        }
        $conn->prepare("DELETE FROM project_files WHERE level='panel' AND node_id IN "
            . '(SELECT id FROM project_panels WHERE setup_id = :s)')->execute([':s' => $s]);
        $conn->prepare("DELETE FROM project_files WHERE level='setup' AND node_id = :s")
            ->execute([':s' => $s]);
        $conn->prepare("DELETE FROM setup_overrides WHERE setup_id = :s")->execute([':s' => $s]);
        $conn->prepare("DELETE FROM project_panels WHERE setup_id = :s")->execute([':s' => $s]);
    }
    $conn->prepare('DELETE FROM project_group_thresholds WHERE project_id = :p')
        ->execute([':p' => $pid]);
    $conn->prepare('DELETE FROM project_setups WHERE project_id = :p')->execute([':p' => $pid]);
    $conn->prepare('DELETE FROM projects WHERE id = :p')->execute([':p' => $pid]);
}

// replica of project_add.php. The overrides go through parseProjectAddRequest,
// so "new:<name>" is turned into customSetups as in production.
function doAdd(PDO $conn, int $pid, array $ids, array $overrides): array
{
    $req = parseProjectAddRequest([
        'project_id' => $pid, 'ids' => $ids, 'overrides' => $overrides,
    ]);
    $conn->beginTransaction();
    $prep = projectAddPrepare($conn, $req['project_id'], $req['ids'], $req['overrides'],
        $req['customSetups'], null);
    $pid2 = $prep['project_id'];
    if (!empty($prep['frozen'])) {
        $conn->rollBack();
        return ['outcome' => 'frozen', 'added' => 0];
    }
    $result = projectAddFiles($conn, $pid2, $prep['ids'], []);
    if ($conn->inTransaction()) {
        $conn->commit();
    }
    return ['outcome' => 'added', 'added' => $result['added']];
}

echo "test file: $fid\n\n";
$fail = false;

// A) frozen + simple add
$pid = createProject($conn, 'frozentest_' . bin2hex(random_bytes(4)), '§6');
$conn->prepare("UPDATE projects SET assign_mode='frozen' WHERE id=:id")->execute([':id' => $pid]);
$before = snapshot($conn, $pid);
$r = doAdd($conn, $pid, [$fid], []);
$after = snapshot($conn, $pid);
echo "A) frozen + simple add\n";
echo "   outcome: {$r['outcome']}, added={$r['added']}\n";
echo '   before: ' . json_encode($before) . "\n";
echo '   after : ' . json_encode($after) . "\n";
$okA = $before === $after;
echo '   ' . ($okA ? 'UNCHANGED (correct)' : 'CHANGED <<< BUG') . "\n\n";
$fail = $fail || !$okA;

// B) frozen + custom setup (the case that used to write)
$before = snapshot($conn, $pid);
$r = doAdd($conn, $pid, [$fid], [(string)$fid => 'new:My Rig']);
$after = snapshot($conn, $pid);
echo "B) frozen + custom setup\n";
echo "   outcome: {$r['outcome']}, added={$r['added']}\n";
echo '   before: ' . json_encode($before) . "\n";
echo '   after : ' . json_encode($after) . "\n";
$okB = $before === $after;
echo '   ' . ($okB ? 'UNCHANGED (correct)' : 'CHANGED <<< BUG') . "\n\n";
$fail = $fail || !$okB;

// C) control: the same add on a NOT frozen project
$pid2 = createProject($conn, 'opentest_' . bin2hex(random_bytes(4)), '§6');
$before2 = snapshot($conn, $pid2);
$r = doAdd($conn, $pid2, [$fid], []);
$after2 = snapshot($conn, $pid2);
echo "C) control: not frozen\n";
echo "   outcome: {$r['outcome']}, added={$r['added']}\n";
echo '   before: ' . json_encode($before2) . "\n";
echo '   after : ' . json_encode($after2) . "\n";
$okC = $after2['links'] > $before2['links'];
echo '   ' . ($okC ? 'it added (correct)' : 'it did NOT add <<< the guard blocks everything') . "\n\n";
$fail = $fail || !$okC;

// D) control: custom setup on a NOT frozen project.
// It needs a different file: in C the same file is already linked (dedupe).
$fid2 = (int)$conn->query("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype = 'LIGHT' "
    . "AND date_obs IS NOT NULL AND id <> $fid ORDER BY id LIMIT 1")->fetchColumn();
$before3 = snapshot($conn, $pid2);
$r = doAdd($conn, $pid2, [$fid2], [(string)$fid2 => 'new:Other Rig']);
$after3 = snapshot($conn, $pid2);
echo "D) control: not frozen + custom setup\n";
echo "   outcome: {$r['outcome']}, added={$r['added']}\n";
echo '   before: ' . json_encode($before3) . "\n";
echo '   after : ' . json_encode($after3) . "\n";
$okD = $after3['setups'] > $before3['setups'];
echo '   ' . ($okD ? 'setup created (correct)' : 'setup NOT created <<<') . "\n\n";
$fail = $fail || !$okD;

purge($conn, $pid);
purge($conn, $pid2);
echo "(test projects removed)\n\n";
echo 'RESULT: ' . ($fail ? 'FAILED' : 'all cases correct') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed the failure verdict.
exit($fail ? 1 : 0);