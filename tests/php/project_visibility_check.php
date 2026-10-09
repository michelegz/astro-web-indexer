<?php
// Check 13.8 — a project must not vanish because of a suggestion the
// user cannot see.
//
// Before: canAccessProject() denied the project if ANY pending or accepted
// suggestion pointed at a file outside the allowed directories. The indexer runs
// without a user context, so it can legitimately have proposals on files that
// this user does not see: their project vanished without any message.
//
// After: out-of-scope suggestions do not deny the project and are filtered
// out by the review queue. Links remain a veto.
//
// Usage:  docker cp tmp/project_visibility_check.php awi-php:/tmp/
//         docker exec awi-php sh -c 'cd /tmp && php project_visibility_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
require_once '/var/www/html/includes/projects_functions.php';
require_once '/var/www/html/includes/projects_diagnostics.php';

$conn = connectDB();
$failed = [];

// The whole point of 13.8 is the out-of-scope filtering, and that filter is
// getAllowedDirs() -> canAccessPath(). With AUTH_MODE=none isAuthEnabled() is false,
// getAllowedDirs() returns null and canAccessPath() returns true for every path by
// design, so the restricted user is handed the out-of-scope suggestion and the checks
// below report a leak that cannot exist in this deployment. Same guard as §13.39 in
// security_batch_check.php: skip with a note, and assert for real under AUTH_MODE=full.
if (!isAuthEnabled()) {
    $mode = defined('AUTH_MODE') ? AUTH_MODE : 'unset';
    echo "\n=== does the project stay visible to the restricted user? ===\n";
    echo "  SKIPPED: 13.8 needs AUTH_MODE=full, this deployment has '$mode'.\n";
    echo "  With permissions off every path is allowed by design, so the review queue\n";
    echo "  has nothing to filter. Re-run with AUTH_MODE=full to cover it.\n";
    echo "\nRESULT: skipped, needs AUTH_MODE=full\n";
    exit(0);
}

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-50s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

/** Two top-level folders, so the scope is distinguishable. */
$roots = $conn->query("SELECT DISTINCT SUBSTRING_INDEX(path, '/', 1) r FROM files
                       WHERE deleted_at IS NULL AND path LIKE '%/%' LIMIT 2")
    ->fetchAll(PDO::FETCH_COLUMN);
if (count($roots) < 2) {
    echo "need at least two top-level folders: found " . count($roots) . "\n";
    exit(1);
}
[$visibleRoot, $hiddenRoot] = $roots;
echo "  allowed root: $visibleRoot   hidden root: $hiddenRoot\n\n";

// files: one visible, one not (prepared prefix, no inline quoting)
$st = $conn->prepare("SELECT id FROM files WHERE deleted_at IS NULL AND path LIKE ?
                     AND imgtype = 'LIGHT' AND date_obs IS NOT NULL ORDER BY id LIMIT 1");
$st->execute([$visibleRoot . '/%']);
$visFile = (int)$st->fetchColumn();
$st->execute([$hiddenRoot . '/%']);
$hidFile = (int)$st->fetchColumn();
if (!$visFile || !$hidFile) {
    echo "need LIGHT files in both folders (vis=$visFile hid=$hidFile)\n";
    exit(1);
}
echo "  file visibile $visFile, file nascosto $hidFile\n\n";

// test project: one setup, and pending suggestions towards both files
$conn->prepare("INSERT INTO projects (name, notes, tolerances, assign_mode)
             VALUES (:n, '', '{}', 'suggest')")
    ->execute([':n' => 'vis_' . bin2hex(random_bytes(3))]);
$pid = (int)$conn->lastInsertId();
$conn->prepare("INSERT INTO project_setups (project_id, fingerprint, setup_no, label)
             VALUES (:p, 'VISTEST|1|CAM|1X1|1|2|3', 1, 'vis')")->execute([':p' => $pid]);
$sid = (int)$conn->lastInsertId();
foreach ([[$visFile, 'in-scope'], [$hidFile, 'out-of-scope']] as [$fid, $tag]) {
    $conn->prepare("INSERT INTO project_suggestions
        (project_id, file_id, level, node_id, role, status, reason)
        VALUES (:p, :f, 'setup', :n, 'sub', 'pending', :r)")
        ->execute([':p' => $pid, ':f' => $fid, ':n' => $sid, ':r' => $tag]);
}

function asUser(array $session, callable $fn)
{
    $_SESSION = $session;
    return $fn();
}

$admin = ['user_id' => 1, 'username' => 'admin', 'is_admin' => 1,
    'can_download' => 1, 'allowed_dirs' => ['/']];
// restricted user: only the visible folder
$restricted = ['user_id' => 99, 'username' => 'restricted', 'is_admin' => 0,
    'can_download' => 1, 'allowed_dirs' => [$visibleRoot]];

echo "=== does the project stay visible to the restricted user? ===\n";
$adminSees = asUser($admin, fn() => canAccessProject($conn, $pid));
$restrictedSees = asUser($restricted, fn() => canAccessProject($conn, $pid));
check('admin sees the project', $adminSees === true);
check('restricted user sees the project', $restrictedSees === true,
    $restrictedSees ? 'visible' : 'GONE <<< the bug');

echo "\n=== does the review queue filter the out-of-scope suggestion? ===\n";
$treeRestricted = asUser($restricted, fn() => getProjectTree($conn, $pid, true));
$treeAdmin = asUser($admin, fn() => getProjectTree($conn, $pid, true));

// The paths are collected straight from the tree (setup-level pending
// ones live in setups[].calibrations[]) instead of going through json_encode: on real
// data the serialization can fail or be truncated and mask the count.
$collect = function (array $tree): array {
    $out = [];
    foreach (($tree['setups'] ?? []) as $su) {
        foreach (($su['calibrations'] ?? []) as $c) {
            if (!empty($c['pending'])) {
                $out[] = (string)($c['path'] ?? '');
            }
        }
    }
    return $out;
};
$adminPending = $collect($treeAdmin);
$restrictedPending = $collect($treeRestricted);
echo '  admin: ' . count($adminPending) . " pending -> " . implode(', ', $adminPending) . "\n";
echo '  restricted: ' . count($restrictedPending) . " pending -> "
    . implode(', ', $restrictedPending) . "\n";

check('admin sees both suggestions', count($adminPending) === 2,
    count($adminPending) . ' out of 2');
$leak = array_values(array_filter($restrictedPending,
    fn($p) => str_starts_with($p, $hiddenRoot . '/')));
check('no out-of-scope path for the restricted user', empty($leak),
    empty($leak) ? 'filtered' : 'LEAK: ' . implode(', ', $leak));
check('the restricted user still sees their own suggestion',
    count($restrictedPending) === 1, count($restrictedPending) . ' out of 1 expected');

// cleanup
$conn->prepare('DELETE FROM project_suggestions WHERE project_id = :p')->execute([':p' => $pid]);
$conn->prepare('DELETE FROM project_files WHERE node_id = :n AND level = :l')
    ->execute([':n' => $sid, ':l' => 'setup']);
$conn->prepare('DELETE FROM project_setups WHERE project_id = :p')->execute([':p' => $pid]);
$conn->prepare('DELETE FROM projects WHERE id = :p')->execute([':p' => $pid]);
echo "\n(test project removed)\n";
echo 'RESULT: ' . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'the project stays visible and the queue is filtered') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed FAILURES.
exit($failed ? 1 : 0);