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
    if ($fullPath && str_starts_with($fullPath, $fitsRoot) && is_file($fullPath)) {
        $filesToZip[] = [
            'path' => $fullPath,
            'name' => basename($relativePath)
        ];
    }
}

if (empty($filesToZip)) {
    http_response_code(400);
    die(__('no_valid_files_selected'));
}

// Crea un file ZIP temporaneo
$zipFilename = tempnam(sys_get_temp_dir(), 'fits_download_') . '.zip';
$zip = new ZipArchive();

if ($zip->open($zipFilename, ZipArchive::CREATE) !== TRUE) {
    http_response_code(500);
    die(__('cannot_create_zip'));
}

// Aggiungi i file al ZIP
foreach ($filesToZip as $file) {
    $zip->addFile($file['path'], $file['name']);
}

$zip->close();

// Invia il file ZIP al browser
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="fits_files.zip"');
header('Content-Length: ' . filesize($zipFilename));
header('Pragma: no-cache');
header('Expires: 0');

readfile($zipFilename);

// Elimina il file temporaneo
unlink($zipFilename);
exit;