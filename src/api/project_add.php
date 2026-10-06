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

try {
    $req = parseProjectAddRequest(is_array($data) ? $data : []);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
$projectId = $req['project_id'];
$ids = $req['ids'];
$overrides = $req['overrides'];
$customSetups = $req['customSetups'];
$newProject = $req['new_project'];

if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No valid IDs provided.']);
    exit;
}

try {
    $conn = connectDB();
    if ($projectId > 0 && getProject($conn, $projectId) !== null && !canAccessProject($conn, $projectId)) {
        http_response_code(403);
        echo json_encode(['error' => __('projects_no_access')]);
        exit;
    }
    // projectAddPrepare writes (project, custom setups, setup_overrides) outside
    // any transaction, so wrap it together with the add: a failure between the
    // two must not leave a committed empty project behind.
    $conn->beginTransaction();
    $prep = projectAddPrepare($conn, $projectId, $ids, $overrides, $customSetups, $newProject);
    $projectId = $prep['project_id'];
    $ids = $prep['ids'];
    $customSkipped = $prep['customSkipped'];

    if (!empty($prep['frozen'])) {
        $conn->rollBack();
        $project = getProject($conn, $projectId);
        echo json_encode([
            'success' => true,
            'project_id' => $projectId,
            'added' => 0,
            'skipped' => [['name' => (string)($project['name'] ?? ''),
                'message' => __(projectAddReasonKey('frozen'))]],
            'message' => __('projects_add_added', ['count' => 0]),
        ]);
        exit;
    }

    $result = projectAddFiles($conn, $projectId, $ids, $req['groupFpOverrides'] ?? []);
    if ($conn->inTransaction()) {
        $conn->commit();
    }
    $skipped = [];
    foreach ($result['skipped'] as $s) {
        $skipped[] = ['name' => $s['name'], 'message' => __(projectAddReasonKey($s['reason']))];
    }
    foreach ($customSkipped as $s) {
        $skipped[] = [
            'name' => $s['name'],
            'message' => __('projects_add_reason_custom_exists', ['no' => $s['no'] ?? '?']),
        ];
    }
    echo json_encode([
        'success' => true,
        'project_id' => $projectId,
        'added' => $result['added'],
        'skipped' => $skipped,
        'message' => __('projects_add_added', ['count' => $result['added']]),
    ]);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    error_log($e->getMessage());
    echo json_encode(['error' => 'Database query failed.']);
}
