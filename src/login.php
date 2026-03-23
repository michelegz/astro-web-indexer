<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_functions.php';
session_start();
require_once __DIR__ . '/includes/language_functions.php';
require_once __DIR__ . '/includes/language.php';
require_once __DIR__ . '/includes/auth.php';

// If auth is disabled or user is already logged in, go home
if (!isAuthEnabled() || isLoggedIn()) {
    header('Location: /');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    // CSRF check
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $error = __('login_error');
    } elseif ($username === '' || $password === '') {
        $error = __('login_error');
    } else {
        $conn = connectDB();
        if (attemptLogin($conn, $username, $password)) {
            $redirect = $_GET['redirect'] ?? '/';
            // Prevent open redirect
            if (!str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
                $redirect = '/';
            }
            session_write_close();
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = __('login_error');
        }
    }
}

// Generate CSRF token only if not already present
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <title><?= __('login_title') ?> - <?= __('site_title') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Private astrophotography file manager - login">
    <link href="/assets/css/output.css" rel="stylesheet">
</head>
<body class="flex items-center justify-center min-h-screen bg-gray-900 text-gray-100 font-sans">
    <div class="w-full max-w-sm p-8 bg-gray-800 rounded-lg shadow-lg">
        <div class="flex flex-col items-center mb-6">
            <?php
            $customLogoPath = '/var/www/html/assets/logo/custom_logo.svg';
            $defaultLogoPath = 'assets/logo/default_logo.svg';
            $logoPath = file_exists($customLogoPath) ? 'assets/logo/custom_logo.svg' : $defaultLogoPath;
            ?>
            <img src="<?= $logoPath ?>" alt="Logo" class="h-16 w-16 mb-4">
            <div class="text-sm text-gray-300 mb-1"><?= __('site_title') ?></div>
            <h1 class="text-xl font-bold"><?= __('login_title') ?></h1>
        </div>

        <?php if ($error): ?>
            <div class="mb-4 p-3 bg-red-900/50 border border-red-700 rounded text-red-300 text-sm">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <div class="mb-4">
                <label for="username" class="block text-sm font-medium text-gray-300 mb-1"><?= __('login_username') ?></label>
                <input type="text" id="username" name="username" required autocomplete="username"
                       class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-lg text-gray-100 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <div class="mb-6">
                <label for="password" class="block text-sm font-medium text-gray-300 mb-1"><?= __('login_password') ?></label>
                <input type="password" id="password" name="password" required autocomplete="current-password"
                       class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-lg text-gray-100 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <button type="submit"
                    class="w-full py-2 px-4 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition-colors">
                <?= __('login_button') ?>
            </button>
        </form>

    </div>
</body>
</html>
