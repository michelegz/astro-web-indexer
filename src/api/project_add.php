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

// Per-file setup override: {fileId: setupId | "new:Custom name"}. The setup
// must belong to the target project; rows are upserted into setup_overrides
// (explicit choice wins). "new:" creates a custom setup with a synthetic
// fingerprint suffix: it never auto-matches (engine matches exact
// fingerprints only), so custom setups fill exclusively by hand/override.
$overrides = [];
$customSetups = [];
if (isset($data['overrides']) && is_array($data['overrides'])) {
    foreach ($data['overrides'] as $fid => $sid) {
        if (!is_numeric($fid) || (int)$fid <= 0) {
            continue;
        }
        $fid = (int)$fid;
        if (is_string($sid) && str_starts_with($sid, 'new:')) {
            $name = substr(str_replace('|', ' ', trim(substr($sid, 4))), 0, 64);
            if ($name === '') {
                continue;
            }
            $customSetups[$fid] = $name;
        } elseif (is_numeric($sid) && (int)$sid > 0) {
            $overrides[$fid] = (int)$sid;
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
    'frozen' => 'projects_add_reason_frozen',
];

try {
    $conn = connectDB();
    if ($projectId > 0 && getProject($conn, $projectId) !== null && !canAccessProject($conn, $projectId)) {
        http_response_code(403);
        echo json_encode(['error' => __('projects_no_access')]);
        exit;
    }
    if ($newProject !== null) {
        $projectId = createProject($conn, $newProject['name'], $newProject['notes']);
    }
    $customSkipped = [];
    if (!empty($customSetups)) {
        $fpRow = $conn->prepare(
            "SELECT id, name, instrume, telescop, cameraid, xbinning, ybinning, gain, `offset`, xpixsz FROM files WHERE id = :fid"
        );
        // Existing custom names in this project (case-insensitive): creating
        // a duplicate is blocked with a message instead of silently reusing.
        $existingCustoms = [];
        $cs = $conn->prepare(
            "SELECT id, setup_no, fingerprint FROM project_setups "
            . "WHERE project_id = :pid AND fingerprint LIKE '%|CUSTOM:%'"
        );
        $cs->execute([':pid' => $projectId]);
        foreach ($cs->fetchAll() as $srow) {
            $pos = strrpos((string)$srow['fingerprint'], '|CUSTOM:');
            if ($pos !== false) {
                $existingCustoms[mb_strtolower(trim(substr((string)$srow['fingerprint'], $pos + 8)))] = [
                    'id' => (int)$srow['id'],
                    'no' => $srow['setup_no'],
                ];
            }
        }
        // Only files actually being added can seed a custom setup.
        $allowed = array_flip($ids);
        $blockedFids = [];
        $customSkipped = [];
        foreach ($customSetups as $fid => $name) {
            if (!isset($allowed[$fid])) {
                continue;
            }
            $lname = mb_strtolower($name);
            if (isset($existingCustoms[$lname])) {
                $customSkipped[] = ['fid' => $fid, 'name' => null, 'no' => $existingCustoms[$lname]['no']];
                $blockedFids[$fid] = true;
                continue;
            }
            $fpRow->execute([':fid' => $fid]);
            $frow = $fpRow->fetch();
            if ($frow === false) {
                continue;
            }
            $fp = projectBuildFingerprint($frow) . '|CUSTOM:' . $name;
            $exists = $conn->prepare(
                "SELECT id, setup_no FROM project_setups WHERE project_id = :pid AND fingerprint = :fp"
            );
            $exists->execute([':pid' => $projectId, ':fp' => $fp]);
            $exRow = $exists->fetch();
            if ($exRow !== false) {
                // Same-request duplicate (or exact race already committed):
                // blocked like a pre-existing name.
                $customSkipped[] = [
                    'fid' => $fid,
                    'name' => (string)($frow['name'] ?? ''),
                    'no' => $exRow['setup_no'],
                ];
                $blockedFids[$fid] = true;
                continue;
            }
            try {
                $sid = projectCreateSetup($conn, $projectId, $fp, $name);
            } catch (PDOException $e) {
                // Concurrent creation won the race: re-check, then block cleanly.
                $exists->execute([':pid' => $projectId, ':fp' => $fp]);
                $exRow = $exists->fetch();
                if ($exRow === false) {
                    throw $e;
                }
                $customSkipped[] = [
                    'fid' => $fid,
                    'name' => (string)($frow['name'] ?? ''),
                    'no' => $exRow['setup_no'],
                ];
                $blockedFids[$fid] = true;
                continue;
            }
            $existingCustoms[$lname] = ['id' => $sid, 'no' => null];
            // Fetch the fresh setup_no for a potential later message.
            $norow = $conn->prepare("SELECT setup_no FROM project_setups WHERE id = :sid");
            $norow->execute([':sid' => $sid]);
            $existingCustoms[$lname]['no'] = $norow->fetchColumn();
            $overrides[$fid] = $sid;
        }
        if (!empty($blockedFids)) {
            $ids = array_values(array_filter($ids, fn($id) => !isset($blockedFids[$id])));
            // Resolve file names for blocked entries missing them.
            $nm = $conn->prepare("SELECT name FROM files WHERE id = :fid");
            foreach ($customSkipped as &$csk) {
                if ($csk['name'] === null) {
                    $nm->execute([':fid' => $csk['fid']]);
                    $nrow = $nm->fetch();
                    $csk['name'] = $nrow !== false ? (string)$nrow['name'] : '#' . $csk['fid'];
                }
            }
            unset($csk);
        }
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
} catch (Exception $e) {
    http_response_code(500);
    error_log($e->getMessage());
    echo json_encode(['error' => 'Database query failed.']);
}
