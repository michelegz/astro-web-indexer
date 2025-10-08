<?php
// api/find_calibration_files.php

header('Content-Type: application/json');

// Bootstrap the application
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/sff_results_table.php';

// --- Read Input ---
$json_data = file_get_contents('php://input');
$params = json_decode($json_data, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload.']);
    exit;
}

$fileId = $params['file_id'] ?? null;
$searchType = $params['search_type'] ?? null;
$filters = $params['filters'] ?? [];

if (!$fileId || !$searchType) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters: file_id and search_type.']);
    exit;
}

// --- Get Reference File Data ---
$conn = connectDB();
$stmt = $conn->prepare("SELECT * FROM files WHERE id = :id");
$stmt->execute([':id' => $fileId]);
$refFile = $stmt->fetch();

if (!$refFile) {
    http_response_code(404);
    echo json_encode(['error' => 'Reference file not found.']);
    exit;
}

// --- Build SQL Query Dynamically ---
$sqlWhere = [];
$sqlParams = [];

// --- Base conditions ---
$sqlWhere[] = "is_hidden = 0";
$sqlWhere[] = "deleted_at IS NULL";

// Base IMGTYPE filter
$imgTypes = [
    'lights'         => 'LIGHT',
    'bias'           => 'BIAS',
    'darks'          => 'DARK',
    'flats'          => 'FLAT'
];
$sqlWhere[] = "imgtype = :imgtype";
$sqlParams[':imgtype'] = $imgTypes[$searchType];


// Process each filter from the frontend
foreach ($filters as $filter) {
    $id = $filter['id'];

    // Simple toggle filters (exact match)
    if ($filter['type'] === 'toggle') {
        $escapedId = "`{$id}`";
        $sqlWhere[] = "{$escapedId} = :{$id}";
        $sqlParams[":{$id}"] = $refFile[$id];
    }
    
    // Slider filters (tolerance-based)
    elseif (strpos($filter['type'], 'slider') !== false) {
        $refValue = (float)($filter['ref_value'] ?? $refFile[$id]);
        $tolerance = (float)$filter['tolerance'];
        
        if ($filter['type'] === 'slider_percent') {
            $delta = $refValue * ($tolerance / 100);
            $min = $refValue - $delta;
            $max = $refValue + $delta;
            $escapedId = "`{$id}`";
            $sqlWhere[] = "{$escapedId} BETWEEN :{$id}_min AND :{$id}_max";
            $sqlParams[":{$id}_min"] = $min;
            $sqlParams[":{$id}_max"] = $max;
        }
        elseif ($filter['type'] === 'slider_degrees') {
            $min = $refValue - $tolerance;
            $max = $refValue + $tolerance;
            $escapedId = "`{$id}`";
            $sqlWhere[] = "{$escapedId} BETWEEN :{$id}_min AND :{$id}_max";
            $sqlParams[":{$id}_min"] = $min;
            $sqlParams[":{$id}_max"] = $max;
        }
        elseif ($filter['type'] === 'slider_days') {
            $refDate = new DateTime($refFile[$id]); // Use the dynamic ID here
            $minDate = clone $refDate;
            $minDate->modify("-{$tolerance} days");
            $maxDate = clone $refDate;
            $maxDate->modify("+{$tolerance} days");
            
            $escapedId = "`{$id}`";
            $sqlWhere[] = "{$escapedId} BETWEEN :{$id}_min AND :{$id}_max"; // And here
            $sqlParams[":{$id}_min"] = $minDate->format('Y-m-d H:i:s');
            $sqlParams[":{$id}_max"] = $maxDate->format('Y-m-d H:i:s');
        }
    }
}

// --- Execute Query ---
try {
    // Select all columns needed for the new results table layout
    $sql = "SELECT id, name, path, date_obs, exptime, ccd_temp, xbinning, ybinning, width, height, cameraid, thumb 
            FROM files 
            WHERE " . implode(' AND ', $sqlWhere) . " ORDER BY date_obs DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($sqlParams);
    $results = $stmt->fetchAll();

    // Find and move the reference file to the top, if present
    $reference_file_index = -1;
    foreach ($results as $index => $file) {
        if ($file['id'] == $fileId) {
            $reference_file_index = $index;
            break;
        }
    }

    if ($reference_file_index !== -1) {
        $reference_file = $results[$reference_file_index];
        $reference_file['is_reference'] = true; // Add a flag
        unset($results[$reference_file_index]); // Remove from original position
        array_unshift($results, $reference_file); // Add to the beginning
    }

    // Calculate total exposure time
    $totalExposure = array_sum(array_column($results, 'exptime'));

    // Render the HTML table into a variable
    ob_start();
    render_sff_results_table($results);
    $html = ob_get_clean();

    // Return JSON response
    echo json_encode([
        'html' => $html,
        'total_exposure' => $totalExposure,
        'count' => count($results)
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    // Return a JSON error message
    echo json_encode(['error' => 'Database query failed: ' . $e->getMessage()]);
}
