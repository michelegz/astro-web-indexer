<?php
header('Content-Type: application/json');

ob_start();
require_once '../includes/init.php';
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
    echo json_encode([
        'success' => true,
        'entries' => $map['entries'],
        'sets' => $map['sets'],
        'tiles' => $map['tiles'] ?? [],
        'skipped' => $map['skipped'],
        'duplicated_files' => $map['duplicated_files'],
        'total_size' => $map['total_size'],
        'manifest' => $map['manifest'],
    ]);
} catch (Exception $e) {
    http_response_code(500);
    error_log($e->getMessage());
    echo json_encode(['error' => 'Database query failed.']);
}
