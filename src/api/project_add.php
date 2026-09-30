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

if (!isset($data['ids']) || !is_array($data['ids'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input. Required: ids (array of int), project_id (int) or new_project ({name, notes}).']);
    exit;
}

$projectId = isset($data['project_id']) ? (int)$data['project_id'] : 0;
$ids = array_values(array_filter($data['ids'], 'is_int'));

if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No valid IDs provided.']);
    exit;
}

// Cap batch size to keep the request bounded.
$ids = array_slice($ids, 0, 2000);

// Per-file setup override: {fileId: setupId}. The setup must belong to the
// target project; rows are upserted into setup_overrides (explicit choice wins).
$overrides = [];
if (isset($data['overrides']) && is_array($data['overrides'])) {
    foreach ($data['overrides'] as $fid => $sid) {
        if (is_numeric($fid) && is_numeric($sid) && (int)$fid > 0 && (int)$sid > 0) {
            $overrides[(int)$fid] = (int)$sid;
        }
    }
}

// Inline new project: {name, notes}. Created first; if zero files are added
// it simply stays empty.
$newProject = null;
if (isset($data['new_project']) && is_array($data['new_project'])) {
    $name = substr(trim((string)($data['new_project']['name'] ?? '')), 0, 255);
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input. new_project.name is required.']);
        exit;
    }
    $newProject = ['name' => $name, 'notes' => trim((string)($data['new_project']['notes'] ?? ''))];
}

if ($projectId <= 0 && $newProject === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input. Provide project_id or new_project.']);
    exit;
}

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
    if ($newProject !== null) {
        $projectId = createProject($conn, $newProject['name'], $newProject['notes']);
    }
    if (!empty($overrides)) {
        $check = $conn->prepare("SELECT id FROM project_setups WHERE id = :sid AND project_id = :pid");
        $upsert = $conn->prepare(
            "INSERT INTO setup_overrides (file_id, setup_id) VALUES (:fid, :sid) "
            . "ON DUPLICATE KEY UPDATE setup_id = VALUES(setup_id)"
        );
        // Only files actually being added can carry an override.
        $allowed = array_flip($ids);
        foreach ($overrides as $fid => $sid) {
            if (!isset($allowed[$fid])) {
                continue;
            }
            $check->execute([':sid' => $sid, ':pid' => $projectId]);
            if ($check->fetch() === false) {
                continue; // setup from another project: ignore silently
            }
            $upsert->execute([':fid' => $fid, ':sid' => $sid]);
        }
    }
    $result = projectAddFiles($conn, $projectId, $ids);
    $skipped = [];
    foreach ($result['skipped'] as $s) {
        $key = $reasonKeys[$s['reason']] ?? 'projects_add_reason_error';
        $skipped[] = ['name' => $s['name'], 'message' => __($key)];
    }
    echo json_encode([
        'success' => true,
        'project_id' => $projectId,
        'added' => $result['added'],
        'skipped' => $skipped,
        'message' => __('projects_add_added', ['count' => $result['added']]),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    error_log($e->getMessage());
    echo json_encode(['error' => 'Database query failed.']);
}
