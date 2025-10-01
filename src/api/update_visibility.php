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



// Get and decode the JSON payload
$data = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!isset($data['ids']) || !is_array($data['ids']) || !isset($data['action']) || !in_array($data['action'], ['hide', 'show']) || !isset($data['hash'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input. Required: ids (array), action (hide/show), hash (string).']);
    exit;
}

$ids = $data['ids'];
$action = $data['action'];
$hash = $data['hash'];
$newState = ($action === 'hide') ? 1 : 0;

// Filter out non-integer IDs for security
$ids = array_filter($ids, 'is_int');

if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No valid IDs provided.']);
    exit;
}

try {
    $conn = connectDB();
    $conn->beginTransaction();

    // Create a string of placeholders (?,?,?)
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $conn->prepare("UPDATE files SET is_hidden = ? WHERE id IN ($placeholders)");
    
    // Bind the new state and then all the IDs
    $params = array_merge([$newState], $ids);
    $stmt->execute($params);

    // After updating, recalculate both duplicate counts for the hash
    $totalStmt = $conn->prepare("SELECT COUNT(*) as count FROM files WHERE file_hash = ? AND deleted_at IS NULL");
    $totalStmt->execute([$hash]);
    $newTotalCount = (int)($totalStmt->fetch()['count'] ?? 0);

    $visibleStmt = $conn->prepare("SELECT COUNT(*) as count FROM files WHERE file_hash = ? AND is_hidden = 0 AND deleted_at IS NULL");
    $visibleStmt->execute([$hash]);
    $newVisibleCount = (int)($visibleStmt->fetch()['count'] ?? 0);

    // Now update these counts for all duplicates
    $updateCountsStmt = $conn->prepare("UPDATE files SET total_duplicate_count = ?, visible_duplicate_count = ? WHERE file_hash = ?");
    $updateCountsStmt->execute([$newTotalCount, $newVisibleCount, $hash]);

    $conn->commit();

    echo json_encode([
        'success' => true, 
        'message' => 'Visibility updated successfully.',
        'new_total_count' => $newTotalCount,
        'new_visible_count' => $newVisibleCount
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    error_log($e->getMessage());
    echo json_encode(['error' => __('db_connection_error', ['error' => $e->getMessage()])]);
}
