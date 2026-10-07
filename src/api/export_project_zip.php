<?php

// Disable execution time limit for this script, allowing for long zip creation times.
@set_time_limit(0);

ob_start();
// The response here is a file the browser downloads, not JSON the page reads: keep
// the login redirect, a 401 would be saved as a broken .zip.
$GLOBALS['AWI_API_AUTH_REDIRECT'] = true;
require_once '../includes/api_bootstrap.php';
ob_end_clean();

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

// CSRF: this form posts urlencoded, which a cross-origin page can submit without a
// preflight. Without the token any site could make a logged-in browser build a
// multi-GB archive server side (the response itself stays unreadable, so this is
// abuse of resources rather than data disclosure). Same pattern as projects.php.
if (!isset($_SESSION['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    die('Invalid or missing CSRF token.');
}

try {
    $conn = connectDB();
    $project = getProject($conn, $projectId);
    if ($project === null) {
        awiJson(['error' => __('projects_not_found')], 404);
    }
    if (!canAccessProject($conn, $projectId)) {
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
$validEntries = [];
$missing = [];
foreach ($map['entries'] as $e) {
    $relativePath = preg_replace('/\.\.(\/|\\\\)?/', '', (string)($e['src'] ?? ''));
    $relativePath = ltrim($relativePath, '/\\');
    if ($relativePath === '') {
        continue;
    }
    $fullPath = realpath($fitsRoot . DIRECTORY_SEPARATOR . $relativePath);
    if ($fullPath
        && $realRoot !== false
        && isPathWithinRoot($fullPath, $realRoot)
        && is_file($fullPath)
        && is_readable($fullPath)
        && canAccessPath($relativePath)) {
        $validFiles[] = ['zip_path' => (string)$e['zip_path'], 'full' => $fullPath];
        $validEntries[] = $e;
        continue;
    }
    // The builder is deliberately disk-free, so the preview counts files from the DB
    // and cannot know whether they are there. Record the gap instead of losing it:
    // without this the archive carried no trace of a file the preview had promised.
    $missing[] = [
        'zip_path' => (string)$e['zip_path'],
        'src' => (string)($e['src'] ?? ''),
        'reason' => (string)($e['src'] ?? '') === '' ? 'no_path' : 'not_readable',
    ];
}

// MANIFEST.json describes the archive, so it is rebuilt from the entries that are
// actually in it. Reusing $map['manifest'] meant it listed folders, calibration sets
// and sizes for files the disk check above had just rejected: the inventory
// contradicted the contents, and a set whose files were all missing produced no
// directory at all, since ZipStream only writes files.
$folders = [];
$archiveSize = 0;
foreach ($validFiles as $i => $vf) {
    $e = $validEntries[$i];
    $archiveSize += (int)filesize($vf['full']);
    $d = dirname((string)$e['zip_path']);
    $folders[$d]['files'][] = basename((string)$e['zip_path']);
    $folders[$d]['kinds'][$e['kind']] = true;
    if (!empty($e['scope'])) {
        $folders[$d]['scope'] = array_values(array_unique(array_merge(
            $folders[$d]['scope'] ?? [],
            (array)$e['scope']
        )));
    }
}
ksort($folders);

// A calibration set is in the archive only if at least one of its files is. The set
// name is a path segment of the entry, so this is a segment test, not a prefix one.
$survivingSets = [];
foreach ($map['sets'] as $name => $set) {
    $needle = '/' . $name . '/';
    foreach ($validEntries as $e) {
        if (str_contains('/' . $e['zip_path'] . '/', $needle)) {
            $survivingSets[$name] = $set;
            break;
        }
    }
}

// Duplicate copies: keep the paths that really landed.
$survivingPaths = array_column($validFiles, 'zip_path');
$duplicatedFiles = [];
foreach ($map['duplicated_files'] as $dup) {
    $paths = array_values(array_intersect((array)($dup['paths'] ?? []), $survivingPaths));
    if ($paths !== []) {
        $duplicatedFiles[] = ['name' => $dup['name'] ?? '', 'fid' => $dup['fid'] ?? null, 'paths' => $paths];
    }
}

$manifest = $map['manifest'];
$manifest['sets'] = $survivingSets;
$manifest['folders'] = $folders;
$manifest['duplicated_files'] = $duplicatedFiles;
// Size of what is actually here, not the DB estimate the preview shows.
$manifest['total_size'] = $archiveSize;
$manifest['files'] = count($validFiles);
if ($missing !== []) {
    // Explicit rather than silent: the preview total stays an upper bound by design.
    $manifest['missing'] = $missing;
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
        // Never let a bad byte void the whole archive: awiJsonString substitutes
        // invalid UTF-8 instead of returning false.
        data: awiJsonString($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    );
    $zip->finish();
} catch (Exception $e) {
    http_response_code(500);
    error_log("Zip creation error: " . $e->getMessage());
    die(__('zip_creation_error'));
}

exit;
