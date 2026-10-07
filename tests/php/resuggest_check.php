<?php
declare(strict_types=1);

// Checks resuggestDismissed() + getDismissedCount() through the real code
// path, including the "only dismissed are touched" invariant. Runs in a
// transaction that is always rolled back.
//
// The project used to be hardcoded to 36, which no longer exists: the insert died on
// project_suggestions_ibfk_1 (project_id -> projects.id) and the test could not run at
// all. Same class of problem as cols_test.php's missing helper: a fixture pinned to an id
// that ages out. So the project is picked from the database instead, and it must be one
// with no existing suggestions, because the assertions below are exact counts.

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/language_functions.php';
require_once __DIR__ . '/includes/language.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/projects_functions.php';

while (ob_get_level() > 0) {
    ob_end_clean();
}

$conn = connectDB();
$fail = [];
$check = function (bool $ok, string $msg) use (&$fail): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail[] = $msg;
    }
};

// A project that exists and has no suggestions, so the baseline counts are predictable.
$PROJECT = (int)$conn->query(
    'SELECT p.id FROM projects p
     LEFT JOIN project_suggestions s ON s.project_id = p.id
     GROUP BY p.id HAVING COUNT(s.project_id) = 0 ORDER BY p.id LIMIT 1'
)->fetchColumn();
if ($PROJECT <= 0) {
    echo "  FAIL nessun progetto senza suggerimenti: non posso stabilire un baseline noto\n";
    echo "FAILURES: 1\n";
    exit(1);
}

// Real file ids, for project_suggestions_ibfk_2 (file_id -> files.id). The four old ids
// still exist, but pinning them again would only postpone the same failure.
$FIDS = array_map(
    'intval',
    $conn->query('SELECT id FROM files WHERE deleted_at IS NULL ORDER BY id LIMIT 4')->fetchAll(PDO::FETCH_COLUMN)
);
if (count($FIDS) < 4) {
    echo "  FAIL servono 4 file esistenti, trovati " . count($FIDS) . "\n";
    echo "FAILURES: 1\n";
    exit(1);
}
echo "progetto $PROJECT, file " . implode(', ', $FIDS) . "\n";

$conn->beginTransaction();
try {
    $node = (int)$conn->query("SELECT id FROM project_setups WHERE project_id = $PROJECT ORDER BY setup_no LIMIT 1")->fetchColumn();
    $ins = $conn->prepare(
        "INSERT INTO project_suggestions (project_id, file_id, level, node_id, role, reason, status, config_hash) "
        . "VALUES (:p, :f, 'setup', :n, 'sub', 'probe', :s, 'x')"
    );
    $ins->execute([':p' => $PROJECT, ':f' => $FIDS[0], ':n' => $node, ':s' => 'dismissed']);
    $ins->execute([':p' => $PROJECT, ':f' => $FIDS[1], ':n' => $node, ':s' => 'pending']);
    $ins->execute([':p' => $PROJECT, ':f' => $FIDS[2], ':n' => $node, ':s' => 'accepted']);
    $ins->execute([':p' => $PROJECT, ':f' => $FIDS[3], ':n' => $node, ':s' => 'dismissed']);

    $check(getDismissedCount($conn, $PROJECT) === 2, 'getDismissedCount counts only dismissed (2)');
    $check(getPendingCount($conn, $PROJECT) === 1, 'getPendingCount still sees the pending row');

    $removed = resuggestDismissed($conn, $PROJECT);
    $check($removed === 2, 'resuggestDismissed removed exactly the 2 dismissed rows (got ' . $removed . ')');

    $left = $conn->query("SELECT status, COUNT(*) c FROM project_suggestions WHERE project_id = $PROJECT GROUP BY status")
        ->fetchAll();
    $byStatus = [];
    foreach ($left as $r) {
        $byStatus[$r['status']] = (int)$r['c'];
    }
    $check(($byStatus['pending'] ?? 0) === 1, 'pending row untouched');
    $check(($byStatus['accepted'] ?? 0) === 1, 'accepted row untouched');
    $check(!isset($byStatus['dismissed']), 'no dismissed row left');

    $again = resuggestDismissed($conn, $PROJECT);
    $check($again === 0, 'second call is a no-op (idempotent)');
} finally {
    $conn->rollBack();
    echo "\nrolled back\n";
}
echo $fail === [] ? "BUTTON OK\n" : "FAILURES: " . count($fail) . "\n";