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

// X-Original-URI is $request_uri, the raw request line: still percent-encoded and not
// path-normalised. Decoding it produced a path whose first segment could disagree with the
// one nginx actually resolved. Asking for /fits/M33%2F..%2FNGC7000%2F<file> decoded here
// to "M33/../NGC7000/<file>", whose first segment is M33, so the permission check passed
// while the file served came from NGC7000: any user granted a single directory could read
// the whole archive. Refuse any traversal segment, so the segment the check reads is the
// segment that gets served.
foreach (preg_split('#[/\\\\]#', $relativePath) as $segment) {
    if ($segment === '.' || $segment === '..') {
        http_response_code(403);
        exit;
    }
}

// Empty path = root listing: only allow if user has full access
if ($relativePath === '') {
    http_response_code(getAllowedDirs() === null ? 200 : 403);
    exit;
}

// Check directory permission
http_response_code(canAccessPath($relativePath) ? 200 : 403);
