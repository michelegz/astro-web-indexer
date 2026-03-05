<?php
// Autoload Composer dependencies using an absolute path
require_once '/var/www/html/vendor/autoload.php';

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/db_functions.php';

// Start session before language (so session-stored preference is available)
session_start();

require_once __DIR__ . '/language_functions.php';
require_once __DIR__ . '/language.php';

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/template_functions.php';

// Enforce authentication
requireAuth();




// GET parameters
$dir = $_GET['dir'] ?? '';
$filterObject = $_GET['object'] ?? '';
$filterFilter = $_GET['filter'] ?? '';
$filterImgtype = $_GET['imgtype'] ?? '';
$dateObsFrom = $_GET['date_obs_from'] ?? '';
$dateObsTo = $_GET['date_obs_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = max(10, intval($_GET['per_page'] ?? DEFAULT_PER_PAGE));
$sortBy = $_GET['sort_by'] ?? 'name';
$sortOrder = $_GET['sort_order'] ?? 'ASC';
$showAdvanced = isset($_GET['show_advanced']);

$conn = connectDB();

// Get the complete folder tree for navigation
$folders = getAllFoldersAsTree($conn);

// Count total files for pagination
$totalRecords = countFiles($conn, $dir, $filterObject, $filterFilter, $filterImgtype, $dateObsFrom, $dateObsTo);
$totalExposure = sumExposureTime($conn, $dir, $filterObject, $filterFilter, $filterImgtype, $dateObsFrom, $dateObsTo);
$totalPages = max(1, ceil($totalRecords / $perPage));

// Query for files with filters, LIMIT and sorting
$files = getFiles($conn, $dir, $filterObject, $filterFilter, $filterImgtype, $dateObsFrom, $dateObsTo, $perPage, ($page - 1) * $perPage, $sortBy, $sortOrder);
