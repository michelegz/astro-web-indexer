<?php
header('Content-Type: application/json');

// Prevent direct access and ensure required parameters
if (!isset($_GET['hash']) || empty($_GET['hash'])) {
    http_response_code(400);
    echo json_encode(['error' => 'File hash is required.']);
    exit;
}

require_once '../includes/init.php';

try {
    $conn = connectDB();
    $hash = $_GET['hash'];
    $duplicates = getDuplicatesByHash($conn, $hash);
    
    echo json_encode($duplicates);

} catch (Exception $e) {
    http_response_code(500);
    error_log($e->getMessage());
    echo json_encode(['error' => __('error_fetching_duplicates')]);
}
