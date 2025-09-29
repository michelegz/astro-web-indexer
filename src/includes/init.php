<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_functions.php';
require_once __DIR__ . '/template_functions.php';

// Include language system
require_once __DIR__ . '/language_functions.php';
require_once __DIR__ . '/language.php';

// Start session for language preference
session_start();

// GET parameters
$dir = $_GET['dir'] ?? '';
$filterObject = $_GET['object'] ?? '';
$filterFilter = $_GET['filter'] ?? '';
$filterImgtype = $_GET['imgtype'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = max(10, intval($_GET['per_page'] ?? DEFAULT_PER_PAGE));
$sortBy = $_GET['sort_by'] ?? 'name';
$sortOrder = $_GET['sort_order'] ?? 'ASC';

$conn = connectDB();

// Get folders for navigation
$folders = getFolders($conn, $dir);

// Count total files for pagination
$totalFiles = countFiles($conn, $dir, $filterObject, $filterFilter, $filterImgtype);
$totalPages = max(1, ceil($totalFiles / $perPage));

// Query for files with filters, LIMIT and sorting
$files = getFiles($conn, $dir, $filterObject, $filterFilter, $filterImgtype, $perPage, ($page - 1) * $perPage, $sortBy, $sortOrder);