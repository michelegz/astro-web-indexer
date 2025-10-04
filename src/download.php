<?php

// Disable execution time limit for this script, allowing for long zip creation times.
@set_time_limit(0);

// includes/config.php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use ZipStream\ZipStream;

$fitsRoot = FITS_ROOT;

if (!isset($_REQUEST['files']) || !is_array($_REQUEST['files']) || empty($_REQUEST['files'])) {
    http_response_code(400);
    die(__('no_files_selected'));
}

$selectedRelativePaths = $_REQUEST['files'];
$validFiles = [];

// Validazione preliminare
foreach ($selectedRelativePaths as $relativePath) {
    // Rimuovi tutti i tentativi di directory traversal
    $relativePath = preg_replace('/\.\.(\/|\\\\)?/', '', $relativePath);
    $relativePath = ltrim($relativePath, '/\\');
    
    $fullPath = realpath($fitsRoot . DIRECTORY_SEPARATOR . $relativePath);
    
    // Verifica sicura del percorso
    if ($fullPath && 
        str_starts_with($fullPath, realpath($fitsRoot)) && 
        is_file($fullPath) &&
        is_readable($fullPath)) {
        $validFiles[$relativePath] = $fullPath;
    }
}

if (empty($validFiles)) {
    http_response_code(400);
    die(__('no_valid_files'));
}


// Crea ZIP
$zip = new ZipStream(
    outputName: 'fits_files.zip',

);

try {
    foreach ($validFiles as $relativePath => $fullPath) {
        $zip->addFileFromPath(
            fileName: $relativePath,
            path: $fullPath
        );
    }
    
    $zip->finish();
} catch (Exception $e) {
    http_response_code(500);
    error_log("Zip creation error: " . $e->getMessage());
    die(__('zip_creation_error'));
}

exit;