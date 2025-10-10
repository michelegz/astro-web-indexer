<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db_functions.php';

$baseDir = $_GET['dir'] ?? '';

// Basic security: prevent directory traversal
if (strpos($baseDir, '..') !== false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid directory path.']);
    exit;
}

try {
    $conn = connectDB();
    $folders = getFolders($conn, $baseDir);
    // PDO connection is closed by setting it to null
    $conn = null;

    echo json_encode($folders);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'An internal error occurred.']);
}
