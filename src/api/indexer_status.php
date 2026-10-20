<?php

declare(strict_types=1);

/**
 * Live indexer status for the home-page card.
 *
 * The card is admin-only when authentication is on. That rule lives here as
 * well as in the partial: hiding markup is not access control, and this endpoint
 * carries the last worker error, which contains paths.
 */

ob_start();
require_once '../includes/api_bootstrap.php';
ob_end_clean();

require_once '../includes/indexer_functions.php';

if (isAuthEnabled() && !isAdmin()) {
    awiJson(['error' => 'Forbidden.'], 403);
}

try {
    $conn = connectDB();
    $status = getIndexerStatus($conn, true);
} catch (Throwable $e) {
    awiJson(['error' => 'Could not read the indexer status.'], 500);
}

header('Cache-Control: no-store');
awiJson($status);
