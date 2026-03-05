<?php
require_once __DIR__ . '/includes/init.php';

// Only admins can access this page
if (!isAdmin()) {
    http_response_code(403);
    header('Location: /');
    exit;
}

$conn = connectDB();
$message = '';
$messageType = '';

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $message = __('admin_error_csrf');
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $isAdmin = isset($_POST['is_admin']);
            $canDownload = isset($_POST['can_download']);
            $dirs = array_filter(array_map('trim', $_POST['dirs'] ?? []));

            if ($username === '' || $password === '') {
                $message = __('admin_error_required');
                $messageType = 'error';
            } else {
                try {
                    createUser($conn, $username, $password, $isAdmin, $canDownload, $dirs);
                    $message = __('admin_user_created');
                    $messageType = 'success';
                } catch (PDOException $e) {
                    $message = str_contains($e->getMessage(), 'Duplicate') ? __('admin_error_duplicate') : __('admin_error_generic');
                    $messageType = 'error';
                }
            }
        } elseif ($action === 'update') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $password = $_POST['password'] ?? '';
            $isAdmin = isset($_POST['is_admin']);
            $canDownload = isset($_POST['can_download']);
            $dirs = array_filter(array_map('trim', $_POST['dirs'] ?? []));

            if ($userId > 0) {
                if (!$isAdmin && isLastAdmin($conn, $userId)) {
                    $message = __('admin_error_last_admin');
                    $messageType = 'error';
                } else {
                    updateUser($conn, $userId, $password !== '' ? $password : null, $isAdmin, $canDownload, $dirs);
                    $message = __('admin_user_updated');
                    $messageType = 'success';
                }
            }
        } elseif ($action === 'delete') {
            $userId = (int)($_POST['user_id'] ?? 0);
            // Prevent self-deletion
            if ($userId > 0 && $userId !== $_SESSION['user_id']) {
                if (isLastAdmin($conn, $userId)) {
                    $message = __('admin_error_last_admin');
                    $messageType = 'error';
                } else {
                    deleteUser($conn, $userId);
                    $message = __('admin_user_deleted');
                    $messageType = 'success';
                }
            }
        }
    }
}

// Generate CSRF token only if not already present
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$users = getAllUsers($conn);
$rootDirs = getAllRootDirs($conn);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <title><?= __('admin_title') ?> - <?= __('site_title') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="/assets/css/output.css" rel="stylesheet">
</head>
<body class="flex flex-col min-h-screen bg-gray-900 text-gray-100 font-sans">
    <header class="bg-gray-700 shadow-md">
        <div class="px-4 py-4 flex items-center justify-between">
            <h1 class="text-2xl font-bold tracking-wide"><?= __('admin_title') ?></h1>
            <div class="flex items-center gap-4">
                <?php include __DIR__ . '/includes/language_selector.php'; ?>
                <a href="/" class="text-sm text-gray-300 hover:text-white">&larr; <?= __('back') ?></a>
            </div>
        </div>
    </header>

    <main class="flex-grow px-4 py-8 max-w-4xl mx-auto w-full">

        <?php if ($message): ?>
            <div class="mb-6 p-3 rounded text-sm <?= $messageType === 'success' ? 'bg-green-900/50 border border-green-700 text-green-300' : 'bg-red-900/50 border border-red-700 text-red-300' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Create User Form -->
        <section class="mb-10 bg-gray-800 rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-4"><?= __('admin_create_user') ?></h2>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="create">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-gray-300 mb-1"><?= __('login_username') ?></label>
                        <input type="text" name="username" required
                               class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-lg text-gray-100">
                    </div>
                    <div>
                        <label class="block text-sm text-gray-300 mb-1"><?= __('login_password') ?></label>
                        <input type="password" name="password" required autocomplete="new-password"
                               class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-lg text-gray-100">
                    </div>
                </div>
                <div class="flex gap-6">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="is_admin" class="rounded bg-gray-700 border-gray-600">
                        <?= __('admin_is_admin') ?>
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="can_download" checked class="rounded bg-gray-700 border-gray-600">
                        <?= __('admin_can_download') ?>
                    </label>
                </div>
                <div>
                    <label class="block text-sm text-gray-300 mb-1"><?= __('admin_allowed_dirs') ?></label>
                    <div class="flex flex-wrap gap-3">
                        <?php foreach ($rootDirs as $rd): ?>
                            <label class="flex items-center gap-1 text-sm bg-gray-700 px-2 py-1 rounded">
                                <input type="checkbox" name="dirs[]" value="<?= htmlspecialchars($rd) ?>" class="rounded bg-gray-600 border-gray-500">
                                <?= htmlspecialchars($rd) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-xs text-gray-500 mt-1"><?= __('admin_dirs_hint') ?></p>
                </div>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                    <?= __('admin_create_user') ?>
                </button>
            </form>
        </section>

        <!-- User List -->
        <section class="bg-gray-800 rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-4"><?= __('admin_user_list') ?></h2>
            <?php if (empty($users)): ?>
                <p class="text-gray-500"><?= __('admin_no_users') ?></p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-gray-400 border-b border-gray-700">
                            <tr>
                                <th class="py-2 px-3"><?= __('login_username') ?></th>
                                <th class="py-2 px-3"><?= __('admin_is_admin') ?></th>
                                <th class="py-2 px-3"><?= __('admin_can_download') ?></th>
                                <th class="py-2 px-3"><?= __('admin_allowed_dirs') ?></th>
                                <th class="py-2 px-3"><?= __('admin_actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                                <tr class="border-b border-gray-700/50">
                                    <td class="py-2 px-3 font-medium"><?= htmlspecialchars($u['username']) ?></td>
                                    <td class="py-2 px-3"><?= $u['is_admin'] ? '✓' : '' ?></td>
                                    <td class="py-2 px-3"><?= $u['can_download'] ? '✓' : '' ?></td>
                                    <td class="py-2 px-3 text-xs text-gray-400"><?= $u['is_admin'] ? __('admin_all_dirs') : htmlspecialchars($u['allowed_dirs'] ?? __('admin_all_dirs')) ?></td>
                                    <td class="py-2 px-3">
                                        <div class="flex gap-2">
                                            <button onclick="openEditModal(<?= htmlspecialchars(json_encode($u)) ?>, <?= htmlspecialchars(json_encode($rootDirs)) ?>)"
                                                    class="text-xs px-2 py-1 bg-yellow-600 hover:bg-yellow-700 rounded transition-colors"><?= __('admin_edit') ?></button>
                                            <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                                                <form method="POST" class="inline" onsubmit="return confirm('<?= __('admin_confirm_delete') ?>')">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                    <button type="submit" class="text-xs px-2 py-1 bg-red-600 hover:bg-red-700 rounded transition-colors"><?= __('admin_delete') ?></button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <!-- Edit Modal -->
    <div id="editModal" class="fixed inset-0 bg-black/60 hidden items-center justify-center z-50">
        <div class="bg-gray-800 rounded-lg p-6 w-full max-w-md mx-4">
            <h3 class="text-lg font-semibold mb-4"><?= __('admin_edit_user') ?></h3>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div>
                    <label class="block text-sm text-gray-300 mb-1"><?= __('login_password') ?></label>
                    <input type="password" name="password" autocomplete="new-password" placeholder="<?= __('admin_password_placeholder') ?>"
                           class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-lg text-gray-100">
                </div>
                <div class="flex gap-6">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="is_admin" id="edit_is_admin" class="rounded bg-gray-700 border-gray-600">
                        <?= __('admin_is_admin') ?>
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="can_download" id="edit_can_download" class="rounded bg-gray-700 border-gray-600">
                        <?= __('admin_can_download') ?>
                    </label>
                </div>
                <div id="edit_dirs_container">
                    <label class="block text-sm text-gray-300 mb-1"><?= __('admin_allowed_dirs') ?></label>
                    <div id="edit_dirs_list" class="flex flex-wrap gap-3"></div>
                </div>
                <div class="flex gap-3 justify-end">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 bg-gray-600 hover:bg-gray-500 rounded-lg transition-colors"><?= __('admin_cancel') ?></button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors"><?= __('admin_save') ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openEditModal(user, rootDirs) {
        document.getElementById('edit_user_id').value = user.id;
        document.getElementById('edit_is_admin').checked = !!parseInt(user.is_admin);
        document.getElementById('edit_can_download').checked = !!parseInt(user.can_download);

        const userDirs = user.allowed_dirs ? user.allowed_dirs.split(', ') : [];
        const container = document.getElementById('edit_dirs_list');
        container.innerHTML = '';
        rootDirs.forEach(dir => {
            const label = document.createElement('label');
            label.className = 'flex items-center gap-1 text-sm bg-gray-700 px-2 py-1 rounded';
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.name = 'dirs[]';
            cb.value = dir;
            cb.className = 'rounded bg-gray-600 border-gray-500';
            cb.checked = userDirs.includes(dir);
            label.appendChild(cb);
            label.appendChild(document.createTextNode(' ' + dir));
            container.appendChild(label);
        });

        const modal = document.getElementById('editModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeEditModal() {
        const modal = document.getElementById('editModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    document.getElementById('editModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditModal();
    });
    </script>
</body>
</html>
