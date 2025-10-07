<?php
// A simple bootstrap to load only what's necessary for the API
ob_start();
require_once '../includes/config.php';
require_once '../includes/db_functions.php';
require_once '../includes/language_functions.php';
require_once '../includes/language.php';
ob_end_clean();

/**
 * Calculates the "astro session date" for a given observation timestamp.
 * An astro session runs from noon on day X to noon on day X+1.
 *
 * @param string|null $date_obs_str The observation timestamp (e.g., '2023-10-27 02:30:00').
 * @return string The session date in 'Y-m-d' format.
 */
function get_astro_session_date(?string $date_obs_str): string {
    $fallback_date = date('Y-m-d', strtotime('12 hours ago'));
    if (empty($date_obs_str)) {
        return $fallback_date;
    }
    try {
        $dt = new DateTime($date_obs_str);
        // If the observation was made before noon, it belongs to the previous calendar day's session.
        if ((int)$dt->format('H') < 12) {
            $dt->modify('-1 day');
        }
        return $dt->format('Y-m-d');
    } catch (Exception $e) {
        return $fallback_date;
    }
}


// --- Main script ---
header('Content-Type: text/csv; charset=utf-8');

// 1. Get file IDs from GET parameter
$ids_str = $_GET['ids'] ?? '';
if (empty($ids_str)) {
    // Return an empty but valid CSV if no IDs are provided
    echo "date,filter,number,duration,binning,gain,sensorCooling,fNumber,darks,flats,flatDarks,bias\n";
    exit;
}

$ids = array_filter(explode(',', $ids_str), 'is_numeric');

if (empty($ids)) {
    echo "date,filter,number,duration,binning,gain,sensorCooling,fNumber,darks,flats,flatDarks,bias\n";
    exit;
}

// 2. Fetch all data for the selected files
try {
    $conn = connectDB();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    $stmt = $conn->prepare(
        "SELECT id, object, date_obs, exptime, filter, imgtype, xbinning, egain, ccd_temp, focratio
         FROM files WHERE id IN ($placeholders)"
    );
    $stmt->execute($ids);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    echo "Error: Could not retrieve file data from database.\n";
    error_log("AstroBin CSV Export Error: " . $e->getMessage());
    exit;
}

// 3. Process data: separate lights from calibration frames
$lights = [];
$cal_frames = ['darks' => 0, 'flats' => 0, 'flatDarks' => 0, 'bias' => 0];

foreach ($files as $file) {
    $imgtype_upper = strtoupper(trim($file['imgtype'] ?? ''));
    switch ($imgtype_upper) {
        case 'LIGHT FRAME':
        case 'LIGHT':
            $lights[] = $file;
            break;
        case 'DARK FRAME':
        case 'DARK':
            $cal_frames['darks']++;
            break;
        case 'FLAT FIELD':
        case 'FLAT':
            $cal_frames['flats']++;
            break;
        case 'FLAT DARK':
        case 'DARK FLAT':
            $cal_frames['flatDarks']++;
            break;
        case 'BIAS FRAME':
        case 'BIAS':
            $cal_frames['bias']++;
            break;
    }
}

// 4. Aggregate light frames into sessions using the "astro-night" logic
$full_header = [
    'date', 'filter', 'number', 'duration', 'iso', 'binning', 'gain', 'sensorCooling', 'fNumber',
    'darks', 'flats', 'flatDarks', 'bias', 'bortle', 'meanSqm', 'meanFwhm', 'temperature'
];
$sessions = [];
if (!empty($lights)) {
    foreach ($lights as $light) {
        $session_date = get_astro_session_date($light['date_obs']);
        
        // Create a unique key for each session
        $key = sprintf(
            '%s|%s|%s|%s|%s',
            $session_date,
            $light['filter'] ?? 'N/A',
            (string)($light['exptime'] ?? 0),
            (string)($light['xbinning'] ?? 1),
            (string)($light['egain'] ?? 'N/A')
        );
        
        if (!isset($sessions[$key])) {
            // Initialize with all keys from the full header to ensure column order
            $sessions[$key] = array_fill_keys($full_header, '');
            
            // Populate with available data
            $sessions[$key]['date'] = $session_date;
            $sessions[$key]['number'] = 0;
            $sessions[$key]['duration'] = $light['exptime'] ?? 0;
            $sessions[$key]['binning'] = $light['xbinning'] ?? 1;
            $sessions[$key]['gain'] = $light['egain'] ?? '';
            $sessions[$key]['sensorCooling'] = !is_null($light['ccd_temp']) ? round($light['ccd_temp']) : '';
            $sessions[$key]['fNumber'] = $light['focratio'] ?? '';
        }
        $sessions[$key]['number']++;
    }
}

// 5. Create output file handle and write CSV
$output = fopen('php://output', 'w');

// Write header
fputcsv($output, $full_header);

if (empty($sessions)) {
    // If no light frames were selected, output a single line with just calibration data
    $cal_only_row = array_fill_keys($full_header, '');
    $cal_only_row['date'] = get_astro_session_date(null); // Default to "yesterday's" session
    $cal_only_row = array_merge($cal_only_row, $cal_frames);
    fputcsv($output, $cal_only_row);
} else {
    // Add calibration data to *every* session found
    foreach ($sessions as $session) {
        $session = array_merge($session, $cal_frames);
        // Ensure all columns are present, even if empty
        $row_to_write = array_merge(array_fill_keys($full_header, ''), $session);
        fputcsv($output, $row_to_write);
    }
}

fclose($output);
exit;
