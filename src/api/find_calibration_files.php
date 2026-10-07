<?php
// api/find_calibration_files.php

header('Content-Type: application/json');

// Bootstrap the application
require_once __DIR__ . '/../includes/api_bootstrap.php';
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

// Apply directory-level permission filter
list($permSql, $permParams) = buildDirPermissionFilter('cf');
if ($permSql !== null) {
    $sqlWhere[] = $permSql;
    $sqlParams = array_merge($sqlParams, $permParams);
}

// Base IMGTYPE filter
$imgTypes = [
    'lights'         => 'LIGHT',
    'bias'           => 'BIAS',
    'darks'          => 'DARK',
    'flats'          => 'FLAT'
];
// search_type was only checked for presence, so any unrecognised value fell through
// to $imgTypes[$searchType] and raised "Undefined array key". display_errors is on, and
// that warning is printed into the response body ahead of the JSON, so the client got a
// SyntaxError instead of an error it could act on. Reject the value, and name the
// valid ones: the caller sends this from a select, and the values were never documented.
if (!isset($imgTypes[$searchType])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Invalid search_type.',
        'valid' => array_keys($imgTypes),
    ]);
    exit;
}
$sqlWhere[] = "imgtype = :imgtype";
$sqlParams[':imgtype'] = $imgTypes[$searchType];


// Process each filter from the frontend
foreach ($filters as $filter) {
    $id = $filter['id'];

    // The filter id becomes a column name below, so it has to be one of ours. Before
    // this, the only thing standing between the JSON body and "{$escapedId}" inside the
    // query was the isset($refFile[$id]) check below, which held only because real
    // column names happen to contain no backtick. That is an incidental property of a
    // guard written for a different reason: it skipped filters the reference file had
    // no value for. Whitelist it explicitly, and say what was wrong.
    if (!is_string($id) || !isset($allFilters[$id])) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Invalid filter id.',
            'valid' => array_keys($allFilters),
        ]);
        exit;
    }

    // CRITICAL: Do not apply a filter if the reference file has no value for it.
    if (!isset($refFile[$id]) || $refFile[$id] === null) {
        continue;
    }

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
        
        // Epsilon for float comparison when tolerance is zero
        $epsilon = 0.001;

        if ($filter['type'] === 'slider_percent') {
            $delta = $refValue * ($tolerance / 100) + ($tolerance == 0 ? $epsilon : 0);
            $min = $refValue - $delta;
            $max = $refValue + $delta;
            $escapedId = "`{$id}`";
            $sqlWhere[] = "{$escapedId} BETWEEN :{$id}_min AND :{$id}_max";
            $sqlParams[":{$id}_min"] = $min;
            $sqlParams[":{$id}_max"] = $max;
        }
        elseif ($filter['type'] === 'slider_absolute') {
            $effective_tolerance = $tolerance + ($tolerance == 0 ? $epsilon : 0);
            $min = max(0, $refValue - $effective_tolerance);
            $max = min(100, $refValue + $effective_tolerance);
            $escapedId = "`{$id}`";
            // Use explicit >= and <= which is more robust for floats than BETWEEN
            $sqlWhere[] = "({$escapedId} >= :{$id}_min AND {$escapedId} <= :{$id}_max)";
            $sqlParams[":{$id}_min"] = $min;
            $sqlParams[":{$id}_max"] = $max;
        }
        elseif ($filter['type'] === 'slider_degrees') {
            $effective_tolerance = $tolerance + ($tolerance == 0 ? $epsilon : 0);
            $min = $refValue - $effective_tolerance;
            $max = $refValue + $effective_tolerance;
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
