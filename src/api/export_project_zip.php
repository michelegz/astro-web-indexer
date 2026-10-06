<?php

// Disable execution time limit for this script, allowing for long zip creation times.
@set_time_limit(0);

ob_start();
require_once '../includes/init.php';
ob_end_clean();

require_once __DIR__ . '/../vendor/autoload.php';

use ZipStream\ZipStream;
use ZipStream\CompressionMethod;

// Streaming the original FITS bytes: same permission as download.php, which
// refuses them to users without can_download. Directory-level project access
// (checked below) is a different permission and does not imply this one.
if (!canDownload()) {
    http_response_code(403);
    die(__('download_not_allowed'));
}

// Only accept POST requests (form submit from the export modal).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

$projectId = (int)($_POST['project_id'] ?? 0);
if ($projectId <= 0) {
    http_response_code(400);
    die('Invalid input. Required: project_id (int).');
}

try {
    $conn = connectDB();
    $project = getProject($conn, $projectId);
    if ($project === null || !canAccessProject($conn, $projectId)) {
        http_response_code(403);
        die(__('projects_no_access'));
    }
    // Same shared builder as the preview: the archive matches the preview.
    $map = buildProjectExportMap($conn, $projectId);
} catch (Exception $e) {
    http_response_code(500);
    error_log("Project ZIP Export Error: " . $e->getMessage());
    die('Error: Could not build project export map.');
}

$fitsRoot = FITS_ROOT;
$realRoot = realpath($fitsRoot);

// Same safe-path validation as download.php.
$validFiles = [];
foreach ($map['entries'] as $e) {
    $relativePath = preg_replace('/\.\.(\/|\\\\)?/', '', (string)($e['src'] ?? ''));
    $relativePath = ltrim($relativePath, '/\\');
    if ($relativePath === '') {
        continue;
    }
    $fullPath = realpath($fitsRoot . DIRECTORY_SEPARATOR . $relativePath);
    if ($fullPath
        && $realRoot !== false
        && str_starts_with($fullPath, $realRoot)
        && is_file($fullPath)
        && is_readable($fullPath)
        && canAccessPath($relativePath)) {
        $validFiles[] = ['zip_path' => (string)$e['zip_path'], 'full' => $fullPath];
    }
}

$safeName = preg_replace('/[^A-Za-z0-9_]+/', '_', (string)($project['name'] ?? 'project'));
$zip = new ZipStream(
    outputName: ($safeName !== '' ? $safeName : 'project') . '.zip',
    defaultCompressionMethod: CompressionMethod::STORE,
);

try {
    foreach ($validFiles as $vf) {
        $zip->addFileFromPath(
            fileName: $vf['zip_path'],
            path: $vf['full'],
        );
    }
    $zip->addFile(
        fileName: 'MANIFEST.json',
        data: json_encode($map['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    );
    $zip->finish();
} catch (Exception $e) {
    http_response_code(500);
    error_log("Zip creation error: " . $e->getMessage());
    die(__('zip_creation_error'));
}

exit;
