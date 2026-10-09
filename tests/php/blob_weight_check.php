<?php
// Check 13.16 — the blob reduction must pay off on the data really
// transferred, not just on paper: if the driver sent the blob anyway and
// the alias only overwrote it in memory, the saving would be illusory.
//
// Usage:  docker cp tmp/blob_weight_check.php awi-php:/tmp/
//         docker exec awi-php sh -c 'cd /tmp && php blob_weight_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';

$conn = connectDB();
$pid = (int)$conn->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();

$WHERE = "(pf.level = 'project' AND pf.node_id = :pid) "
    . "OR (pf.level = 'setup' AND pf.node_id IN (SELECT id FROM project_setups WHERE project_id = :pid2)) "
    . "OR (pf.level = 'panel' AND pf.node_id IN (SELECT pp.id FROM project_panels pp "
    . "JOIN project_setups ps ON ps.id = pp.setup_id WHERE ps.project_id = :pid3)) "
    . "OR (pf.level IN ('session','filter') AND pf.node_id IN (SELECT ss.id FROM project_sessions ss "
    . "JOIN project_panels pp ON pp.id = ss.panel_id "
    . "JOIN project_setups ps ON ps.id = pp.setup_id WHERE ps.project_id = :pid4))";

function measure(PDO $conn, string $where, int $pid, string $label): int
{
    $params = [':pid' => $pid, ':pid2' => $pid, ':pid3' => $pid, ':pid4' => $pid];
    $st = $conn->prepare($where);
    $st->execute($params);
    $rows = $st->fetchAll();
    // the strlen of the serialized value approximates the bytes the driver delivered.
    $bytes = strlen(serialize($rows));
    printf("  %-46s rows=%-5d serialized=%9.1f KB\n", $label, count($rows), $bytes / 1024);
    return $bytes;
}

echo "  project $pid\n\n";

$base = "SELECT pf.level, pf.node_id, pf.filter_name, pf.role, pf.is_light, pf.enabled, f.*, f.id AS file_id ";
$join = "FROM project_files pf JOIN files f ON f.id = pf.file_id WHERE $WHERE";

// with the real blobs
$withBlobs = measure($conn, $base . $join, $pid, 'before (real blobs)');
// with OCTET_LENGTH
$fixed = $base . ', OCTET_LENGTH(f.thumb) AS thumb, OCTET_LENGTH(f.thumb_crop) AS thumb_crop '
    . $join;
$withLen = measure($conn, $fixed, $pid, 'after (OCTET_LENGTH)');

$saved = $withBlobs - $withLen;
printf("\n  saving: %.1f KB (%.0fx)\n", $saved / 1024, $withBlobs / max(1, $withLen));

// estimate for a large project: the saving scales with the linked rows
$perRow = $withBlobs > 0 ? ($withBlobs / max(1, (int)$conn->query(
    "SELECT COUNT(*) n FROM project_files pf JOIN files f ON f.id = pf.file_id
      WHERE f.deleted_at IS NULL")->fetchColumn())) : 0;
printf("  per linked row: %.1f KB\n", $perRow / 1024);
printf("  on 4000 files: %.0f MB saved (against a 512M memory_limit)\n",
    $perRow * 4000 / 1024 / 1024);

$ok = $saved > 0;
echo "\nRESULT: " . ($ok ? 'real saving on the transferred data'
    : 'NO SAVING <<< the blob travels anyway') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed the failure verdict.
exit($ok ? 0 : 1);