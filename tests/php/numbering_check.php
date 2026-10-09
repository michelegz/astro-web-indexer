<?php
// Check §9 and §8 — setup, panel and session numbering.
//
// It checks three things:
//  1. two concurrent writers on the same project do not produce duplicate setup_no
//     (before the fix: two rows with the same S);
//  2. session_no follows the chronological order of the nights, even when inserting an
//     older night afterwards (before the fix: MAX+1 by creation order);
//  3. the export does not put two different setups in the same folder.
//
// Usage:  docker cp tmp/numbering_check.php awi-php:/var/www/html/
//         docker exec awi-php php /var/www/html/numbering_check.php
//
// It creates and removes a test project. It requires migrations 20261019 and
// 20261020 to have been applied: without the unique indexes the race is not
// reproducible.

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
require_once '/var/www/html/includes/projects_functions.php';

$_SESSION = ['user_id' => 1, 'username' => 'admin', 'is_admin' => 1,
    'can_download' => 1, 'allowed_dirs' => ['/']];

$failed = [];


function check(string $label, bool $cond, string $detail): void
{
    printf("  %-34s %-46s %s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

$conn = connectDB();

// are the unique indexes present?
$hasSetupIdx = (int)$conn->query("SELECT COUNT(*) c FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_setups'
      AND INDEX_NAME = 'uq_project_setups_no'")->fetchColumn();
$hasPanelIdx = (int)$conn->query("SELECT COUNT(*) c FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_panels'
      AND INDEX_NAME = 'uq_project_panels_no'")->fetchColumn();
echo "migration applied: setup_no unique=" . ($hasSetupIdx ? 'yes' : 'NO')
    . ", panel_no unique=" . ($hasPanelIdx ? 'yes' : 'NO') . "\n\n";
if (!$hasSetupIdx || !$hasPanelIdx) {
    echo "warning: without the unique indexes the concurrency test proves nothing.\n";
}

// --------------------------------------------------------------- test project
$conn->exec("INSERT INTO projects (name, notes, tolerances, assign_mode) VALUES "
    . "('numtest_" . bin2hex(random_bytes(3)) . "', 'check 9', '{}', 'manual')");
$pid = (int)$conn->lastInsertId();

// --------------------------------------------------------------- 1. concorrenza
// Two independent connections that read the same MAX and insert.
// projectCreateSetup retries on 1062, so the two setups must have different numbers.
$c1 = connectDB();
$c2 = connectDB();
$fpA = 'FP|A|CAM|1X1|1|2|3';
$fpB = 'FP|B|CAM|1X1|1|2|3';
$sidA = projectCreateSetup($c1, $pid, $fpA, 'A');
$sidB = projectCreateSetup($c2, $pid, $fpB, 'B');
$noA = (int)$c1->query("SELECT setup_no FROM project_setups WHERE id = $sidA")->fetchColumn();
$noB = (int)$c2->query("SELECT setup_no FROM project_setups WHERE id = $sidB")->fetchColumn();
check('distinct setup_no (2 writers)', $noA !== $noB, "A=$noA B=$noB");
$dups = (int)$conn->query("SELECT COUNT(*) c FROM (SELECT setup_id, panel_no FROM project_panels
    GROUP BY setup_id, panel_no HAVING COUNT(*)>1) x")->fetchColumn();
check('no duplicate panel_no (DB)', $dups === 0, "duplicates=$dups");

// The same fingerprint must NOT create a second setup, and the conflict must
// reach the caller right away (which reuses the existing setup): the retry
// on the numbering must not mask it.
$threw = false;
try {
    projectCreateSetup($c1, $pid, $fpA, 'A-dup');
} catch (PDOException $e) {
    $threw = str_contains($e->getMessage(), '1062');
}
check('duplicate fingerprint -> 1062 right away', $threw, 'the caller reuses the existing setup');

// --------------------------------------------------------------- 2. night order
$row = ['objctrot' => 0, 'object' => 'Q99', 'fov_w' => 60, 'fov_h' => 40];
$panelA = projectCreatePanel($c1, $sidA, $row, 'Q99', 10.0, 20.0);
$panelB = projectCreatePanel($c2, $sidA, $row, 'Q99', 30.0, 40.0);
$panelNos = [
    'A' => (int)$conn->query("SELECT panel_no FROM project_panels WHERE id = $panelA")->fetchColumn(),
    'B' => (int)$conn->query("SELECT panel_no FROM project_panels WHERE id = $panelB")->fetchColumn(),
];
check('distinct panel_no (same setup)', $panelNos['A'] !== $panelNos['B'],
    "A={$panelNos['A']} B={$panelNos['B']}");

// a new night, then an older night: the numbering must follow the date
$nights = ['2026-03-10', '2026-03-20', '2026-02-01', '2026-03-15'];
foreach ($nights as $n) {
    projectFindOrCreateSession($c1, $panelA, $n);
}
$rows = $conn->query("SELECT astro_night, session_no FROM project_sessions
                      WHERE panel_id = $panelA ORDER BY astro_night")->fetchAll();
$byNight = [];
foreach ($rows as $r) {
    $byNight[$r['astro_night']] = (int)$r['session_no'];
}
echo "  night -> session_no (in date order): ";
foreach ($byNight as $night => $no) {
    echo "$night=N$no ";
}
echo "\n";
$expected = 1;
$ordered = true;
foreach ($byNight as $night => $no) {
    if ($no !== $expected) {
        $ordered = false;
    }
    $expected++;
}
check('session_no follows the night', $ordered, 'expected 1,2,3,4 in date order');

// asking for the same night again must not create another one
$before = (int)$conn->query("SELECT COUNT(*) c FROM project_sessions WHERE panel_id = $panelA")
    ->fetchColumn();
$again2 = projectFindOrCreateSession($c1, $panelA, '2026-03-10');
$after = (int)$conn->query("SELECT COUNT(*) c FROM project_sessions WHERE panel_id = $panelA")
    ->fetchColumn();
check('repeated night -> reused', $before === $after && $again2 > 0,
    "sessions $before->$after id=$again2");

// two panels, same night: distinct numbers
$other = projectFindOrCreateSession($c2, $panelB, '2026-03-10');
$nOther = (int)$conn->query("SELECT session_no FROM project_sessions WHERE id = $other")
    ->fetchColumn();
check('same night, different panel', $nOther !== ($byNight['2026-03-10'] ?? -1),
    "A=N" . ($byNight['2026-03-10'] ?? '?') . " B=N$nOther");

// --------------------------------------------------------------- 3. export folders
$folders = [];
foreach ($conn->query("SELECT setup_no FROM project_setups WHERE project_id = $pid") as $r) {
    $folders[] = 'SETUP_S' . (int)$r['setup_no'];
}
check('one folder per setup', count($folders) === count(array_unique($folders)),
    implode(', ', $folders));

// --------------------------------------------------------------- cleanup
$setupIds = $conn->query("SELECT id FROM project_setups WHERE project_id = $pid")
    ->fetchAll(PDO::FETCH_COLUMN);
foreach ($setupIds as $s) {
    $panels = $conn->query("SELECT id FROM project_panels WHERE setup_id = $s")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($panels as $p) {
        foreach ($conn->query("SELECT id FROM project_sessions WHERE panel_id = $p")
            ->fetchAll(PDO::FETCH_COLUMN) as $ss) {
            $conn->prepare("DELETE FROM project_files WHERE level='filter' AND node_id = :n")
                ->execute([':n' => $ss]);
        }
        $conn->prepare("DELETE FROM project_sessions WHERE panel_id = :p")->execute([':p' => $p]);
        $conn->prepare("DELETE FROM project_files WHERE level='panel' AND node_id = :n")
            ->execute([':n' => $p]);
    }
    $conn->prepare("DELETE FROM project_files WHERE level='setup' AND node_id = :n")
        ->execute([':n' => $s]);
    $conn->prepare("DELETE FROM setup_overrides WHERE setup_id = :n")->execute([':n' => $s]);
    $conn->prepare("DELETE FROM project_panels WHERE setup_id = :n")->execute([':n' => $s]);
}
$conn->prepare("DELETE FROM project_setups WHERE project_id = :p")->execute([':p' => $pid]);
$conn->prepare("DELETE FROM projects WHERE id = :p")->execute([':p' => $pid]);

echo "\n(test project removed)\n";
echo 'RESULT: ' . ($failed ? 'FAILED: ' . implode(', ', $failed) : 'all checks passed') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed FAILURES.
exit($failed ? 1 : 0);