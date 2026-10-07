<?php
// Dates with at least one file (observation sessions) for the filter
// calendars. Respects the current filters EXCEPT the date range itself,
// so the red dots show every session available for the other selections.
header('Content-Type: application/json');

require_once '../includes/api_bootstrap.php';

try {
    $conn = connectDB();

    $dir = $_GET['dir'] ?? '';
    $object = $_GET['object'] ?? '';
    $filter = $_GET['filter'] ?? '';
    $imgtype = $_GET['imgtype'] ?? '';
    $exptimeMin = (isset($_GET['exptime_min']) && is_numeric($_GET['exptime_min'])) ? (string)$_GET['exptime_min'] : '';
    $exptimeMax = (isset($_GET['exptime_max']) && is_numeric($_GET['exptime_max'])) ? (string)$_GET['exptime_max'] : '';
    $instrume = $_GET['instrume'] ?? '';
    $telescop = $_GET['telescop'] ?? '';

    $dates = getObservationDateCounts($conn, $dir, $object, $filter, $imgtype, $exptimeMin, $exptimeMax, $instrume, $telescop);

    echo json_encode(['dates' => $dates]);
} catch (Exception $e) {
    http_response_code(500);
    error_log($e->getMessage());
    echo json_encode(['error' => 'error_fetching_observation_dates']);
}
