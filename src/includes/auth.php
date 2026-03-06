<?php
/**
 * Authentication and authorization functions.
 *
 * Supports two modes controlled by AUTH_MODE:
 *   'none'  – No authentication, full access (legacy behavior).
 *   'full'  – Login required for everyone, with directory-level permissions.
 */

/**
 * Check if authentication is enabled.
 */
function isAuthEnabled(): bool
{
    return defined('AUTH_MODE') && AUTH_MODE !== 'none';
}

/**
 * Return true if the current session belongs to a logged-in user.
 */
function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

/**
 * Return true if the current user is admin.
 */
function isAdmin(): bool
{
    return isLoggedIn() && !empty($_SESSION['is_admin']);
}

/**
 * Return true if the current user can download files.
 */
function canDownload(): bool
{
    if (!isAuthEnabled()) return true;
    if (isAdmin()) return true;
    return isLoggedIn() && !empty($_SESSION['can_download']);
}

/**
 * Get the list of allowed root directories for the current user.
 * Returns null if the user has unrestricted access (admin or auth disabled).
 */
function getAllowedDirs(): ?array
{
    if (!isAuthEnabled()) return null;
    if (isAdmin()) return null;

    $dirs = $_SESSION['allowed_dirs'] ?? [];
    // '/' means full access
    if (in_array('/', $dirs, true)) return null;
    return $dirs;
}

/**
 * Build a SQL condition fragment and params that restrict results to allowed root dirs.
 * Returns [sqlFragment, params] or [null, []] if no restriction needed.
 */
function buildDirPermissionFilter(string $paramPrefix = 'perm_dir'): array
{
    $allowedDirs = getAllowedDirs();
    if ($allowedDirs === null) {
        return [null, []];
    }
    if (empty($allowedDirs)) {
        // No directories allowed → return impossible condition
        return ['1=0', []];
    }

    $conditions = [];
    $params = [];
    foreach ($allowedDirs as $i => $dir) {
        $key = ':' . $paramPrefix . $i;
        $conditions[] = "SUBSTRING_INDEX(path, '/', 1) = {$key}";
        $params[$key] = $dir;
    }
    return ['(' . implode(' OR ', $conditions) . ')', $params];
}

/**
 * Check if the current user has access to a given file path.
 */
function canAccessPath(string $relativePath): bool
{
    $allowedDirs = getAllowedDirs();
    if ($allowedDirs === null) return true;

    $rootDir = explode('/', $relativePath)[0] ?? '';
    return in_array($rootDir, $allowedDirs, true);
}

/**
 * Attempt to log in a user. Returns true on success.
 */
function attemptLogin(PDO $conn, string $username, string $password): bool
{
    $stmt = $conn->prepare("SELECT id, username, password, is_admin, can_download FROM users WHERE username = :username");
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }

    // Load allowed directories
    $permStmt = $conn->prepare("SELECT root_dir FROM user_permissions WHERE user_id = :uid");
    $permStmt->execute([':uid' => $user['id']]);
    $dirs = $permStmt->fetchAll(PDO::FETCH_COLUMN);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['is_admin'] = (bool)$user['is_admin'];
    $_SESSION['can_download'] = (bool)$user['can_download'];
    $_SESSION['allowed_dirs'] = $dirs;

    session_regenerate_id(true);
    return true;
}

/**
 * Log out the current user.
 */
function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

/**
 * Ensure the user has access. Redirects to login if not authorized.
 * Called from init.php.
 */
function requireAuth(): void
{
    if (!isAuthEnabled()) return;

    if (!isLoggedIn()) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * Ensure the request is from an authenticated user (for API endpoints).
 * Returns 401 JSON if not authorized under 'full' mode.
 */
function requireAuthApi(): void
{
    if (!isAuthEnabled()) return;

    if (!isLoggedIn()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Authentication required.']);
        exit;
    }
}

/**
 * Get all users (admin function).
 */
function getAllUsers(PDO $conn): array
{
    $stmt = $conn->query("SELECT u.id, u.username, u.is_admin, u.can_download, u.created_at,
                           GROUP_CONCAT(p.root_dir ORDER BY p.root_dir SEPARATOR ', ') as allowed_dirs
                           FROM users u
                           LEFT JOIN user_permissions p ON u.id = p.user_id
                           GROUP BY u.id
                           ORDER BY u.username");
    return $stmt->fetchAll();
}

/**
 * Create a new user (admin function).
 */
function createUser(PDO $conn, string $username, string $password, bool $isAdmin, bool $canDownload, array $allowedDirs): int
{
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("INSERT INTO users (username, password, is_admin, can_download) VALUES (:username, :password, :is_admin, :can_download)");
    $stmt->execute([
        ':username' => $username,
        ':password' => $hash,
        ':is_admin' => $isAdmin ? 1 : 0,
        ':can_download' => $canDownload ? 1 : 0,
    ]);
    $userId = (int)$conn->lastInsertId();

    if (!$isAdmin && !empty($allowedDirs)) {
        $permStmt = $conn->prepare("INSERT INTO user_permissions (user_id, root_dir) VALUES (:uid, :dir)");
        foreach ($allowedDirs as $dir) {
            $dir = trim($dir);
            if ($dir !== '') {
                $permStmt->execute([':uid' => $userId, ':dir' => $dir]);
            }
        }
    }

    return $userId;
}

/**
 * Update an existing user (admin function).
 */
function updateUser(PDO $conn, int $userId, ?string $password, bool $isAdmin, bool $canDownload, array $allowedDirs): void
{
    if ($password !== null && $password !== '') {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE users SET password = :password, is_admin = :is_admin, can_download = :can_download WHERE id = :id");
        $stmt->execute([':password' => $hash, ':is_admin' => $isAdmin ? 1 : 0, ':can_download' => $canDownload ? 1 : 0, ':id' => $userId]);
    } else {
        $stmt = $conn->prepare("UPDATE users SET is_admin = :is_admin, can_download = :can_download WHERE id = :id");
        $stmt->execute([':is_admin' => $isAdmin ? 1 : 0, ':can_download' => $canDownload ? 1 : 0, ':id' => $userId]);
    }

    // Replace permissions
    $conn->prepare("DELETE FROM user_permissions WHERE user_id = :uid")->execute([':uid' => $userId]);
    if (!$isAdmin && !empty($allowedDirs)) {
        $permStmt = $conn->prepare("INSERT INTO user_permissions (user_id, root_dir) VALUES (:uid, :dir)");
        foreach ($allowedDirs as $dir) {
            $dir = trim($dir);
            if ($dir !== '') {
                $permStmt->execute([':uid' => $userId, ':dir' => $dir]);
            }
        }
    }
}

/**
 * Count how many admin users exist.
 */
function countAdmins(PDO $conn): int
{
    return (int) $conn->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetchColumn();
}

/**
 * Check if removing admin from this user would leave zero admins.
 */
function isLastAdmin(PDO $conn, int $userId): bool
{
    $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();
    return $user && (int)$user['is_admin'] === 1 && countAdmins($conn) <= 1;
}

/**
 * Delete a user (admin function).
 */
function deleteUser(PDO $conn, int $userId): void
{
    $conn->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $userId]);
}

/**
 * Get all distinct root-level directories from the files table.
 */
function getAllRootDirs(PDO $conn): array
{
    $stmt = $conn->query("SELECT DISTINCT SUBSTRING_INDEX(path, '/', 1) as root_dir FROM files WHERE path LIKE '%/%' AND deleted_at IS NULL ORDER BY root_dir");
    $dirs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    array_unshift($dirs, '/');
    return $dirs;
}

/**
 * Check if any user exists in the database. Used for first-run setup.
 */
function hasAnyUser(PDO $conn): bool
{
    $stmt = $conn->query("SELECT COUNT(*) FROM users");
    return (int)$stmt->fetchColumn() > 0;
}
