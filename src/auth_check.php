<?php
/**
 * Internal auth check endpoint for nginx auth_request.
 * Returns 200 if access is allowed, 403 if denied.
 * nginx sends the original URI in X-Original-URI header.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/language_functions.php';
require_once __DIR__ . '/includes/language.php';
session_start();
require_once __DIR__ . '/includes/auth.php';

// No auth required → allow everything
if (!isAuthEnabled()) {
    http_response_code(200);
    exit;
}

// Not logged in → deny
if (!isLoggedIn()) {
    http_response_code(403);
    exit;
}

// Extract relative path from the original URI (strip /fits/ prefix and URL-decode)
$originalUri = $_SERVER['HTTP_X_ORIGINAL_URI'] ?? '';
$relativePath = urldecode(preg_replace('#^/fits/#', '', $originalUri));
$relativePath = rtrim($relativePath, '/');

// Empty path = root listing: only allow if user has full access
if ($relativePath === '') {
    http_response_code(getAllowedDirs() === null ? 200 : 403);
    exit;
}

// Check directory permission
http_response_code(canAccessPath($relativePath) ? 200 : 403);
