<?php
header('Content-Type: application/json');

ob_start();
require_once '../includes/api_bootstrap.php';
ob_end_clean();

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    awiJson(['error' => 'Method Not Allowed'], 405);
}

// Dry-run only: same shared builder as the ZIP download, never writes.
$data = json_decode(file_get_contents('php://input'), true);
$projectId = isset($data['project_id']) ? (int)$data['project_id'] : 0;
if ($projectId <= 0) {
    awiJson(['error' => 'Invalid input. Required: project_id (int).'], 400);
}

try {
    $conn = connectDB();
    if (getProject($conn, $projectId) === null) {
        awiJson(['error' => __('projects_not_found')], 404);
    }
    if (!canAccessProject($conn, $projectId)) {
        awiJson(['error' => __('projects_no_access')], 403);
    }
    $map = buildProjectExportMap($conn, $projectId);
    // 'manifest' is deliberately absent: only export_project_zip.php writes it into
    // the archive, and it repeats sets/tiles/skipped/duplicated_files plus a folder
    // map, which roughly doubles a response nobody reads (memory_limit is 512M).
    // 'skipped' is capped for the same reason: the UI renders a collapsible list,
    // so a count plus a bounded sample is what it actually needs.
    $skippedAll = $map['skipped'];
    $skippedShown = array_slice($skippedAll, 0, 200);
    awiJson([
        'success' => true,
        'entries' => $map['entries'],
        'sets' => $map['sets'],
        'tiles' => $map['tiles'] ?? [],
        'skipped' => $skippedShown,
        'skipped_total' => count($skippedAll),
        'duplicated_files' => $map['duplicated_files'],
        'total_size' => $map['total_size'],
    ]);
} catch (Throwable $e) {
    error_log($e->getMessage());
    awiJson(['error' => 'Database query failed.'], 500);
}
