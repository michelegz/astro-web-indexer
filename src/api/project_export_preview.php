<?php
header('Content-Type: application/json');

ob_start();
require_once '../includes/api_bootstrap.php';
ob_end_clean();

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// Dry-run only: same shared builder as the ZIP download, never writes.
$data = json_decode(file_get_contents('php://input'), true);
$projectId = isset($data['project_id']) ? (int)$data['project_id'] : 0;
if ($projectId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input. Required: project_id (int).']);
    exit;
}

try {
    $conn = connectDB();
    if (getProject($conn, $projectId) === null || !canAccessProject($conn, $projectId)) {
        http_response_code(403);
        echo json_encode(['error' => __('projects_no_access')]);
        exit;
    }
    $map = buildProjectExportMap($conn, $projectId);
    // 'manifest' is deliberately absent: only export_project_zip.php writes it into
    // the archive, and it repeats sets/tiles/skipped/duplicated_files plus a folder
    // map, which roughly doubles a response nobody reads (memory_limit is 512M).
    // 'skipped' is capped for the same reason: the UI renders a collapsible list,
    // so a count plus a bounded sample is what it actually needs.
    $skippedAll = $map['skipped'];
    $skippedShown = array_slice($skippedAll, 0, 200);
    echo json_encode([
        'success' => true,
        'entries' => $map['entries'],
        'sets' => $map['sets'],
        'tiles' => $map['tiles'] ?? [],
        'skipped' => $skippedShown,
        'skipped_total' => count($skippedAll),
        'duplicated_files' => $map['duplicated_files'],
        'total_size' => $map['total_size'],
    ]);
} catch (Exception $e) {
    http_response_code(500);
    error_log($e->getMessage());
    echo json_encode(['error' => 'Database query failed.']);
}
