<?php
// Verifica 13.16 — la riduzione dei blob deve valere sui dati realmente
// trasferiti, non solo sul theory: se il driver inviasse comunque il blob e
// l'alias lo sovrascrivesse solo in memoria, il risparmio sarebbe illusorio.
//
// Uso:  docker cp tmp/blob_weight_check.php awi-php:/tmp/
//       docker exec awi-php sh -c 'cd /tmp && php blob_weight_check.php'

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
    // strlen del serializzato approssima i byte che il driver ha consegnato.
    $bytes = strlen(serialize($rows));
    printf("  %-46s righe=%-5d serializzato=%9.1f KB\n", $label, count($rows), $bytes / 1024);
    return $bytes;
}

echo "  progetto $pid\n\n";

$base = "SELECT pf.level, pf.node_id, pf.filter_name, pf.role, pf.is_light, pf.enabled, f.*, f.id AS file_id ";
$join = "FROM project_files pf JOIN files f ON f.id = pf.file_id WHERE $WHERE";

// con i blob veri
$withBlobs = measure($conn, $base . $join, $pid, 'prima (blob veri)');
// con OCTET_LENGTH
$fixed = $base . ', OCTET_LENGTH(f.thumb) AS thumb, OCTET_LENGTH(f.thumb_crop) AS thumb_crop '
    . $join;
$withLen = measure($conn, $fixed, $pid, 'dopo (OCTET_LENGTH)');

$saved = $withBlobs - $withLen;
printf("\n  risparmio: %.1f KB (%.0fx)\n", $saved / 1024, $withBlobs / max(1, $withLen));

// stima per un progetto grande: il risparmio scala con le righe linkate
$perRow = $withBlobs > 0 ? ($withBlobs / max(1, (int)$conn->query(
    "SELECT COUNT(*) n FROM project_files pf JOIN files f ON f.id = pf.file_id
      WHERE f.deleted_at IS NULL")->fetchColumn())) : 0;
printf("  per riga linkata: %.1f KB\n", $perRow / 1024);
printf("  su 4000 file: %.0f MB risparmiati (contro un memory_limit di 512M)\n",
    $perRow * 4000 / 1024 / 1024);

echo "\nRISULTATO: " . ($saved > 0 ? 'risparmio reale sui dati trasferiti'
    : 'NESSUN RISPARMIO <<< il blob viaggia comunque');