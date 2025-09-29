<?php
// Database configuration
define('DB_HOST', 'mariadb');
define('DB_NAME', 'awi_db');
define('DB_USER', 'awi_user');
define('DB_PASS', 'awi_password');

// File system configuration
define('FITS_ROOT', '/var/fits'); // Root directory for FITS files

// Site configuration
define('DEFAULT_LANGUAGE', 'en'); // Default fallback language
define('HEADER_TITLE', getenv('HEADER_TITLE') ?: 'Astro Web Indexer'); // Site title from environment variable

// Pagination configuration
define('DEFAULT_PER_PAGE', 20); // Default number of items per page
define('PER_PAGE_OPTIONS', [10, 20, 50, 100, 500, 10000]); // Available options for items per page
?>