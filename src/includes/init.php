<?php
// Autoload Composer dependencies using an absolute path
require_once '/var/www/html/vendor/autoload.php';

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/db_functions.php';
require_once __DIR__ . '/projects_functions.php';
require_once __DIR__ . '/projects_diagnostics.php';

// Start session before language (so session-stored preference is available)
session_start();

require_once __DIR__ . '/language_functions.php';
require_once __DIR__ . '/language.php';

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/template_functions.php';
require_once __DIR__ . '/columns.php';
require_once __DIR__ . '/file_cells.php';

// Enforce authentication
requireAuth();




// GET parameters
$dir = $_GET['dir'] ?? '';
$filterObject = $_GET['object'] ?? '';
$filterFilter = $_GET['filter'] ?? '';
$filterImgtype = $_GET['imgtype'] ?? '';
$dateObsFrom = $_GET['date_obs_from'] ?? '';
$dateObsTo = $_GET['date_obs_to'] ?? '';
$exptimeMin = (isset($_GET['exptime_min']) && is_numeric($_GET['exptime_min'])) ? (string)$_GET['exptime_min'] : '';
$exptimeMax = (isset($_GET['exptime_max']) && is_numeric($_GET['exptime_max'])) ? (string)$_GET['exptime_max'] : '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = max(10, intval($_GET['per_page'] ?? DEFAULT_PER_PAGE));
$sortBy = $_GET['sort_by'] ?? 'name';
$sortOrder = $_GET['sort_order'] ?? 'ASC';

// Star metrics master switch (STAR_METRICS_ENABLED=false hides the columns)
$starMetricsEnv = getenv('STAR_METRICS_ENABLED');
$showStarMetrics = $starMetricsEnv === false
    || !in_array(strtolower(trim((string)$starMetricsEnv)), ['0', 'false', 'no', 'off'], true);

// Column visibility: `hiddenCols` cookie (CSV of sortKeys) chosen via the
// Columns panel; legacy ?show_advanced=1 forces everything visible.
$toggleableKeys = getToggleableKeys();
$hiddenCols = resolveHiddenColumns($toggleableKeys);
$columnGroups = getColumnGroups();
$advKeys = [];
foreach ($columnGroups as $groupKey => $group) {
    if ($groupKey !== 'star' && $groupKey !== 'frame') {
        $advKeys = array_merge($advKeys, array_keys($group['columns']));
    }
}
$starKeys = array_keys($columnGroups['star']['columns']);
$visibleAdvKeys = array_values(array_diff($advKeys, $hiddenCols));
$visibleStarKeys = $showStarMetrics ? array_values(array_diff($starKeys, $hiddenCols)) : [];
$frameKeys = array_keys($columnGroups['frame']['columns']);
$visibleFrameKeys = array_values(array_diff($frameKeys, $hiddenCols));
$visibleBaseKeys = array_values(array_diff(array_keys(getBaseColumns()), $hiddenCols)); // 'name' can never be hidden
$tableColspan = 1 + count($visibleBaseKeys) + count($visibleAdvKeys) + count($visibleStarKeys) + count($visibleFrameKeys);

// Independent column visibility for the project integration-group tables
// (cookie `hiddenColsProjects`). Defaults to the old fixed igroup columns.
$hiddenColsProjects = resolveHiddenColumnsForCookie($toggleableKeys, 'hiddenColsProjects', getProjectsDefaultVisible());
$visibleProjectAdvKeys = array_values(array_diff($advKeys, $hiddenColsProjects));
$visibleProjectStarKeys = $showStarMetrics ? array_values(array_diff($starKeys, $hiddenColsProjects)) : [];
$visibleProjectFrameKeys = array_values(array_diff($frameKeys, $hiddenColsProjects));

$conn = connectDB();

// Get the complete folder tree for navigation
$folders = getAllFoldersAsTree($conn);

// Count total files for pagination
$totalRecords = countFiles($conn, $dir, $filterObject, $filterFilter, $filterImgtype, $dateObsFrom, $dateObsTo, $exptimeMin, $exptimeMax);
$totalExposure = sumExposureTime($conn, $dir, $filterObject, $filterFilter, $filterImgtype, $dateObsFrom, $dateObsTo, $exptimeMin, $exptimeMax);
// Exposure breakdown per filter on the currently filtered image set
$filterStats = getExposureStatsByFilter($conn, $dir, $filterObject, $filterFilter, $filterImgtype, $dateObsFrom, $dateObsTo, $exptimeMin, $exptimeMax);
// Per-file star metrics for the trend chart (same filters + table ordering)
$starTrend = [];
if ($showStarMetrics) {
    $starTrend = getStarTrend($conn, $dir, $filterObject, $filterFilter, $filterImgtype, $dateObsFrom, $dateObsTo, $exptimeMin, $exptimeMax, $sortBy, $sortOrder, 10000);
}
// LIGHT frames in the current filter set (trend card header)
$lightRecords = countFiles($conn, $dir, $filterObject, $filterFilter, 'LIGHT', $dateObsFrom, $dateObsTo, $exptimeMin, $exptimeMax);
$totalPages = max(1, ceil($totalRecords / $perPage));

// Query for files with filters, LIMIT and sorting
$files = getFiles($conn, $dir, $filterObject, $filterFilter, $filterImgtype, $dateObsFrom, $dateObsTo, $exptimeMin, $exptimeMax, $perPage, ($page - 1) * $perPage, $sortBy, $sortOrder);
