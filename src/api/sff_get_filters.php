<?php
// api/sff_get_filters.php

// Bootstrap the application
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/sff_filter_template.php';

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

// --- Filter Configuration ---
// This array defines all possible filters based on your criteria.
$allFilters = [
    // Common
    'instrume'   => ['label' => __('instrume'),   'type' => 'toggle', 'default_on' => true],
    'cameraid'   => ['label' => __('camera_id'),    'type' => 'toggle', 'default_on' => true],
    'ccd_temp'   => ['label' => __('ccd_temp'),     'type' => 'slider_degrees', 'default_on' => true, 'min' => 0, 'max' => 30, 'step' => 1, 'unit' => '°', 'default_tolerance' => 2],
    'xbinning'   => ['label' => __('xbinning'),    'type' => 'toggle', 'default_on' => true],
    'ybinning'   => ['label' => __('ybinning'),    'type' => 'toggle', 'default_on' => true],
    'width'      => ['label' => __('dimensions_width'),        'type' => 'slider_percent', 'default_on' => false, 'min' => 0, 'max' => 50, 'step' => 1, 'unit' => '%', 'default_tolerance' => 5],
    'height'     => ['label' => __('dimensions_height'),       'type' => 'slider_percent', 'default_on' => false, 'min' => 0, 'max' => 50, 'step' => 1, 'unit' => '%', 'default_tolerance' => 5],
    'date_obs'   => ['label' => __('date_obs'),    'type' => 'slider_days',    'default_on' => false, 'min' => 0, 'max' => 365, 'step' => 1, 'unit' => 'd', 'default_tolerance' => 30],

    // Light specific
    'object'     => ['label' => __('object'),       'type' => 'toggle', 'default_on' => true],
    'filter'     => ['label' => __('filter'),       'type' => 'toggle', 'default_on' => true],
    'exptime'    => ['label' => __('exposure'),     'type' => 'slider_percent', 'default_on' => true, 'min' => 0, 'max' => 50, 'step' => 1, 'unit' => '%', 'default_tolerance' => 10],
    'ra'         => ['label' => __('ra'),           'type' => 'slider_degrees', 'default_on' => false, 'min' => 0, 'max' => 15, 'step' => 1, 'unit' => '°', 'default_tolerance' => 1],
    'dec'        => ['label' => __('dec'),          'type' => 'slider_degrees', 'default_on' => false, 'min' => 0, 'max' => 15, 'step' => 1, 'unit' => '°', 'default_tolerance' => 1],
    'objctrot'   => ['label' => __('rotation'),     'type' => 'slider_degrees', 'default_on' => false, 'min' => 0, 'max' => 180, 'step' => 1, 'unit' => '°', 'default_tolerance' => 2],
    'fov_w'      => ['label' => __('fov_width'),    'type' => 'slider_percent', 'default_on' => false, 'min' => 0, 'max' => 50, 'step' => 1, 'unit' => '%', 'default_tolerance' => 5],
    'fov_h'      => ['label' => __('fov_height'),   'type' => 'slider_percent', 'default_on' => false, 'min' => 0, 'max' => 50, 'step' => 1, 'unit' => '%', 'default_tolerance' => 5],
    
    // Flat specific (filter is already common)
    // 'filter' is handled by the common group
];

// Define which filters apply to which search type
$filtersForType = [
    'lights' => ['object', 'filter', 'instrume', 'cameraid', 'exptime', 'ccd_temp', 'xbinning', 'ybinning', 'ra', 'dec', 'objctrot', 'fov_w', 'fov_h', 'width', 'height', 'date_obs'],
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

