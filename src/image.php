<?php
// Include only necessary files for database connection
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_functions.php';

// Establish database connection
$conn = connectDB();

// Get the file ID and thumb type from the query string
$id = $_GET['id'] ?? null;
$type = $_GET['type'] ?? 'thumb';

if (!$id || !is_numeric($id)) {
    http_response_code(400);
    // Return a transparent 1x1 pixel GIF as a fallback to avoid broken image icons
    header('Content-Type: image/gif');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

try {
    // Fetch the image data from the database
    $stmt = $conn->prepare("SELECT thumb, thumb_crop FROM files WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $file = $stmt->fetch();

    if (!$file) {
        http_response_code(404);
        // Return a transparent 1x1 pixel GIF
        header('Content-Type: image/gif');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }

    $imageData = null;
    if ($type === 'thumb' && !empty($file['thumb'])) {
        $imageData = $file['thumb'];
    } elseif ($type === 'crop' && !empty($file['thumb_crop'])) {
        $imageData = $file['thumb_crop'];
    }

    if (!$imageData) {
        http_response_code(404);
        // Return a transparent 1x1 pixel GIF
        header('Content-Type: image/gif');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }

    // Set headers for caching
    $expires = 60 * 60 * 24 * 14; // 14 days
    header("Pragma: public");
    header("Cache-Control: public, max-age=".$expires);
    header('Expires: ' . gmdate('D, d M Y H;i:s', time() + $expires) . ' GMT');
    // Set an ETag to allow for more efficient cache validation
    $etag = md5($imageData);
    header("Etag: $etag");

    // Check if the browser has a cached version
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) == $etag) {
        http_response_code(304); // Not Modified
        exit;
    }

    // Serve the image
    header('Content-Type: image/png');
    echo $imageData;

} catch (PDOException $e) {
    // Log error here if you have a logging system
    http_response_code(500);
    // Return a transparent 1x1 pixel GIF
    header('Content-Type: image/gif');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}
