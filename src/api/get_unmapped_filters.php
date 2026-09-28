<?php
// Returns the distinct FITS filter names in the given file selection that have
// no AstroBin ID mapping. Used by the export modal to warn before exporting.
ob_start();
require_once '../includes/config.php';
require_once '../includes/db_functions.php';
require_once '../includes/language_functions.php';
require_once '../includes/language.php';
session_start();
require_once '../includes/auth.php';
ob_end_clean();
requireAuthApi();

header('Content-Type: application/json; charset=utf-8');

$ids = array_filter(explode(',', $_GET['ids'] ?? ''), 'is_numeric');
if (empty($ids)) {
    echo json_encode(['unmapped' => []]);
    exit;
}

try {
    $conn = connectDB();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $conn->prepare(
        "SELECT DISTINCT filter FROM files
         WHERE id IN ($placeholders) AND filter IS NOT NULL AND TRIM(filter) != ''"
    );
    $stmt->execute(array_values($ids));
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
