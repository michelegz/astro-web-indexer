<?php
// api/sff_get_filters.php

// Bootstrap the application
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../includes/sff_filter_template.php';
require_once __DIR__ . '/../includes/sff_filters.php';

// --- Input Validation ---
$fileId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$searchType = htmlspecialchars(filter_input(INPUT_GET, 'type', FILTER_DEFAULT) ?? '');

if (!$fileId || !$searchType) {
    http_response_code(400);
    echo __('sff_error_missing_params');
    exit;
}

// --- Database Query ---
$conn = connectDB();
$stmt = $conn->prepare("SELECT * FROM files WHERE id = :id AND imgtype = 'LIGHT'");
$stmt->execute([':id' => $fileId]);
$referenceFile = $stmt->fetch();

if (!$referenceFile) {
    http_response_code(404);
    echo __('sff_error_no_light_frame');
    exit;
}

// The catalogue and the per-type filter lists live in includes/sff_filters.php, because
// find_calibration_files.php validates incoming filter ids against the same list and
// cannot include this endpoint: it echoes HTML on load.
$allFilters = sff_all_filters();
$filtersForType = sff_filters_for_type();

// Define which filters apply to which search type
$filtersForType = [
    'lights' => ['object', 'filter', 'instrume', 'cameraid', 'exptime', 'ccd_temp', 'xbinning', 'ybinning', 'ra', 'dec', 'objctrot', 'fov_w', 'fov_h', 'moon_phase', 'width', 'height', 'date_obs'],
    'bias'           => ['instrume', 'cameraid', 'ccd_temp', 'xbinning', 'ybinning', 'width', 'height', 'date_obs'],
    'darks'          => ['instrume', 'cameraid', 'exptime', 'ccd_temp', 'xbinning', 'ybinning', 'width', 'height', 'date_obs'],
    'flats'          => ['filter', 'instrume', 'cameraid','ccd_temp', 'xbinning', 'ybinning', 'objctrot', 'width', 'height', 'date_obs'],
];

// --- Render Filters ---
header('Content-Type: text/html');


$activeFilterKeys = $filtersForType[$searchType] ?? [];
if (empty($activeFilterKeys)) {
    echo '<p class="text-red-500">' . __('sff_error_invalid_search_type') . '</p>';
    exit;
}

// Start capturing output
ob_start();

foreach ($activeFilterKeys as $key) {
    if (isset($allFilters[$key])) {
        $config = $allFilters[$key];
        $config['id'] = $key; // Add id to the config array
        
        render_sff_filter($config, $referenceFile[$key] ?? null);
    }
}

// Get the captured output
$html = ob_get_clean();

echo $html;

