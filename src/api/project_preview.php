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

// Dry-run only: analyses the selection against the project structure,
// never writes. project_id null/0 = brand-new project (all groups new).
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['ids']) || !is_array($data['ids'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input. Required: ids (array of int), project_id (int, 0 for new).']);
    exit;
}

$projectId = isset($data['project_id']) ? (int)$data['project_id'] : 0;
$ids = array_values(array_filter($data['ids'], 'is_int'));

if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No valid IDs provided.']);
    exit;
}

$ids = array_slice($ids, 0, 2000);

try {
    $conn = connectDB();
    if ($projectId > 0 && getProject($conn, $projectId) !== null && !canAccessProject($conn, $projectId)) {
        http_response_code(403);
        echo json_encode(['error' => __('projects_no_access')]);
        exit;
    }
    $preview = projectPreviewFiles($conn, $projectId > 0 ? $projectId : null, $ids);
    $setups = $projectId > 0 ? getProjectSetups($conn, $projectId) : [];
    $frozen = false;
    if ($projectId > 0) {
        $proj = getProject($conn, $projectId);
        $frozen = $proj !== null && getProjectAssignMode($proj) === 'frozen';
    }
    echo json_encode([
        'success' => true,
        'frozen' => $frozen,
        'groups' => $preview['groups'],
        'skipped' => $preview['skipped'],
        'setups' => array_map(fn($s) => [
            'id' => (int)$s['id'],
            'label' => ($s['label'] !== null && $s['label'] !== '') ? (string)$s['label'] : substr((string)$s['fingerprint'], 0, 48),
            'fingerprint' => (string)$s['fingerprint'],
        ], $setups),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    error_log($e->getMessage());
    echo json_encode(['error' => 'Database query failed.']);
}
