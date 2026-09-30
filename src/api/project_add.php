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

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['project_id']) || !is_int($data['project_id']) || !isset($data['ids']) || !is_array($data['ids'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input. Required: project_id (int), ids (array of int).']);
    exit;
}

$projectId = $data['project_id'];
$ids = array_values(array_filter($data['ids'], 'is_int'));

if ($projectId <= 0 || empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No valid IDs provided.']);
    exit;
}

// Cap batch size to keep the request bounded.
$ids = array_slice($ids, 0, 2000);

$reasonKeys = [
    'no_project' => 'projects_add_reason_no_project',
    'not_found' => 'projects_add_reason_not_found',
    'imgtype' => 'projects_add_reason_imgtype',
    'forbidden' => 'projects_add_reason_forbidden',
    'no_date' => 'projects_add_reason_no_date',
    'already' => 'projects_add_reason_already',
    'error' => 'projects_add_reason_error',
];

try {
    $conn = connectDB();
    $result = projectAddFiles($conn, $projectId, $ids);
    $skipped = [];
    foreach ($result['skipped'] as $s) {
        $key = $reasonKeys[$s['reason']] ?? 'projects_add_reason_error';
        $skipped[] = ['name' => $s['name'], 'message' => __($key)];
    }
    echo json_encode([
        'success' => true,
        'added' => $result['added'],
        'skipped' => $skipped,
        'message' => __('projects_add_added', ['count' => $result['added']]),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    error_log($e->getMessage());
    echo json_encode(['error' => 'Database query failed.']);
}
