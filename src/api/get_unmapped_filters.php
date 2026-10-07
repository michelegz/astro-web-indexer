<?php
// Returns the distinct FITS filter names in the given file selection that have
// no AstroBin ID mapping. Used by the export modal to warn before exporting.
ob_start();
require_once '../includes/config.php';
require_once '../includes/db_functions.php';
require_once '../includes/http_json.php';
require_once '../includes/language_functions.php';
require_once '../includes/language.php';
session_start();
require_once '../includes/auth.php';
ob_end_clean();
requireAuthApi();

header('Content-Type: application/json; charset=utf-8');

$ids = awiReadFileIds();
if (empty($ids)) {
    echo json_encode(['unmapped' => []]);
    exit;
}

try {
    $conn = connectDB();
    // Directory permissions, the same helper the file listing and the CSV export use.
    // This endpoint took raw ids off the query string with no filter at all, so a user
    // restricted to one root could ask which filter names are unmapped in a directory
    // they cannot see. It is a smaller leak than the CSV itself, which no longer has
    // any, but it is the same class.
    [$permSql, $permParams] = buildDirPermissionFilter('perm_dir');
    $where = [];
    $params = [];
    foreach (array_values($ids) as $i => $id) {
        $key = ':id' . $i;
        $where[] = "id = {$key}";
        $params[$key] = $id;
    }
    if ($permSql !== null) {
        $where[] = $permSql;
        $params += $permParams;
    }

    $stmt = $conn->prepare(
        "SELECT DISTINCT filter FROM files
         WHERE " . implode(' AND ', $where) . " AND filter IS NOT NULL AND TRIM(filter) != ''"
    );
    $stmt->execute($params);
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $mapped = [];
    foreach ($conn->query("SELECT filter_name FROM astrobin_filter_map")->fetchAll(PDO::FETCH_COLUMN) as $m) {
        $mapped[strtolower(trim((string)$m))] = true;
    }

    $unmapped = [];
    foreach ($names as $n) {
        if (!isset($mapped[strtolower(trim((string)$n))])) {
            $unmapped[] = $n;
        }
    }
    sort($unmapped);
    echo json_encode(['unmapped' => array_values($unmapped)]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not check filter mappings.']);
}
