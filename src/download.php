<?php
// includes/config.php
require_once __DIR__ . '/includes/config.php';

$fitsRoot = FITS_ROOT; // Dal config

if (!isset($_GET['files']) || !is_array($_GET['files']) || empty($_GET['files'])) {
    http_response_code(400);
    die(__('no_files_selected'));
}

$selectedRelativePaths = $_GET['files'];
$filesToZip = [];

foreach ($selectedRelativePaths as $relativePath) {
    // Sanifica il percorso per evitare path traversal
    $relativePath = str_replace('..', '', $relativePath);
    $fullPath = realpath($fitsRoot . '/' . $relativePath);

    // Assicurati che il file esista e sia all'interno di fitsRoot
    if ($fullPath && str_starts_with($full