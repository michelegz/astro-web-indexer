<?php
require_once __DIR__ . '/includes/init.php';

$conn = connectDB();
$message = '';
$messageType = '';

// Handle form actions (same CSRF pattern as admin.php / filter_mapping.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $message = __('admin_error_csrf');
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'create') {
                $name = trim((string)($_POST['name'] ?? ''));
                $notes = trim((string)($_POST['notes'] ?? ''));
                if ($name === '') {
                    throw new InvalidArgumentException(__('projects_error_name'));
                }
                createProject($conn, substr($name, 0, 255), $notes);
                $message = __('projects_created');
                $messageType = 'success';
            } elseif ($action === 'update') {
                $id = (int)($_POST['project_id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                $notes = trim((string)($_POST['notes'] ?? ''));
                if ($id <= 0 || $name === '') {
                    throw new InvalidArgumentException(__('projects_error_name'));
                }
                if (getProject($conn, $id) === null) {
                    throw new InvalidArgumentException(__('projects_error_name'));
                }
                updateProject($conn, $id, substr($name, 0, 255), $notes);
                $message = __('projects_updated');
                $messageType = 'success';
            } elseif ($action === 'save_mode') {
                $id = (int)($_POST['project_id'] ?? 0);
                $mode = (string)($_POST['assign_mode'] ?? '');
                if ($id <= 0 || getProject($conn, $id) === null) {
                    throw new InvalidArgumentException(__('projects_error_name'));
                }
                updateProjectAssignMode($conn, $id, $mode);
                $message = __('projects_updated');
                $messageType = 'success';
            } elseif ($action === 'delete') {
                $id = (int)($_POST['project_id'] ?? 0);
                if ($id > 0) {
                    deleteProject($conn, $id);
                    $message = __('projects_deleted');
                    $messageType = 'success';
                    if ((int)($_GET['id'] ?? 0) === $id) {
                        unset($_GET['id']);
                    }
                }
            } elseif ($action === 'accept_suggestions' || $action === 'dismiss_suggestions') {
                $id = (int)($_POST['project_id'] ?? 0);
                if ($id <= 0 || getProject($conn, $id) === null) {
                    throw new InvalidArgumentException(__('projects_error_name'));
                }
                $ids = array_values(array_filter(array_map('intval', (array)($_POST['suggestion_ids'] ?? []))));
                $done = 0;
                foreach ($ids as $sid) {
                    if ($action === 'accept_suggestions') {
                        $done += acceptSuggestion($conn, $id, $sid) ? 1 : 0;
                    } else {
                        $done += dismissSuggestion($conn, $id, $sid) ? 1 : 0;
                    }
                }
                $message = $action === 'accept_suggestions'
                    ? __('projects_accepted', ['count' => $done])
                    : __('projects_discarded', ['count' => $done]);
                $messageType = 'success';
            } elseif ($action === 'save_tolerances') {
                $id = (int)($_POST['project_id'] ?? 0);
                if ($id <= 0 || getProject($conn, $id) === null) {
                    throw new InvalidArgumentException(__('projects_error_name'));
                }
                if (isset($_POST['reset_globals'])) {
                    saveProjectTolerances($conn, $id, []);
                } else {
                    saveProjectTolerances($conn, $id, $_POST['tolerances'] ?? []);
                }
                $message = __('projects_updated');
                $messageType = 'success';
            }
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Generate CSRF token only if not already present
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$projects = getProjects($conn);
$detailId = (int)($_GET['id'] ?? 0);
$detail = $detailId > 0 ? getProject($conn, $detailId) : null;
if ($detailId > 0 && $detail === null) {
    $detailId = 0;
}
$globals = getGlobalTolerances($conn);
$defs = getToleranceDefs();
$detailOverrides = $detail !== null ? getProjectTolerances($detail['tolerances']) : [];
$detailMode = $detail !== null ? getProjectAssignMode($detail) : 'suggest';
$detailPending = $detail !== null ? getPendingCount($conn, (int)$detail['id']) : 0;
$pendingSuggestions = $detail !== null ? getPendingSuggestions($conn, (int)$detail['id'], 200) : [];
$assignModes = getAssignModes();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <title><?= __('projects') ?> - <?= __('site_title') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="/assets/css/output.css" rel="stylesheet">
</head>
<body class="flex flex-col min-h-screen bg-gray-900 text-gray-100 font-sans">
    <header class="bg-gray-700 shadow-md">
        <div class="px-4 py-4 flex items-center justify-between">
            <h1 class="text-2xl font-bold tracking-wide"><?= __('projects') ?></h1>
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

        <section class="mb-6 bg-gray-800 rounded-lg p-6">
            <p class="text-sm text-gray-300"><?= __('projects_intro') ?></p>
        </section>

        <?php if ($detail === null): ?>
        <section class="mb-6 bg-gray-800 rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-4"><?= __('projects_new') ?></h2>
            <form method="POST" class="flex flex-col gap-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="create">
                <input type="text" name="name" maxlength="255" required
                       placeholder="<?= __('projects_name') ?>"
                       class="px-3 py-2 bg-gray-700 border border-gray-600 rounded text-gray-100">
                <textarea name="notes" rows="2"
                          placeholder="<?= __('projects_notes') ?>"
                          class="px-3 py-2 bg-gray-700 border border-gray-600 rounded text-gray-100"></textarea>
                <div class="flex justify-end">
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                        <?= __('projects_create') ?>
                    </button>
                </div>
            </form>
        </section>

        <section class="bg-gray-800 rounded-lg p-6">
            <?php if (empty($projects)): ?>
                <p class="text-gray-500"><?= __('projects_no_projects') ?></p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-gray-400 border-b border-gray-700">
                            <tr>
                                <th class="py-2 px-3"><?= __('projects_name') ?></th>
                                <th class="py-2 px-3"><?= __('projects_notes') ?></th>
                                <th class="py-2 px-3 text-right"><?= __('projects_actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projects as $p): ?>
                                <tr class="border-b border-gray-700/50">
                                    <td class="py-2 px-3 font-medium"><?= htmlspecialchars($p['name']) ?></td>
                                    <td class="py-2 px-3 text-gray-400"><?= htmlspecialchars((string)($p['notes'] ?? '')) ?></td>
                                    <td class="py-2 px-3 text-right whitespace-nowrap">
                                        <a href="/projects.php?id=<?= (int)$p['id'] ?>"
                                           class="inline-block px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded transition-colors"><?= __('projects_open') ?></a>
                                        <form method="POST" class="inline-block ml-2"
                                              onsubmit="return confirm(<?= htmlspecialchars(json_encode(__('projects_confirm_delete'))) ?>);">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="project_id" value="<?= (int)$p['id'] ?>">
                                            <button type="submit" class="px-3 py-1 bg-red-600 hover:bg-red-700 text-white rounded transition-colors">
                                                <?= __('projects_delete') ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php else: ?>
        <section class="mb-6 bg-gray-800 rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-4"><?= htmlspecialchars($detail['name']) ?></h2>
            <form method="POST" class="flex flex-col gap-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="project_id" value="<?= (int)$detail['id'] ?>">
                <label class="text-sm text-gray-400"><?= __('projects_name') ?>
                    <input type="text" name="name" maxlength="255" required
                           value="<?= htmlspecialchars($detail['name']) ?>"
                           class="mt-1 w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded text-gray-100">
                </label>
                <label class="text-sm text-gray-400"><?= __('projects_notes') ?>
                    <textarea name="notes" rows="2"
                              class="mt-1 w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded text-gray-100"><?= htmlspecialchars((string)($detail['notes'] ?? '')) ?></textarea>
                </label>
                <div class="flex justify-end gap-2">
                    <a href="/projects.php" class="px-4 py-2 bg-gray-600 hover:bg-gray-500 text-white rounded-lg transition-colors"><?= __('back') ?></a>
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                        <?= __('projects_save') ?>
                    </button>
                </div>
            </form>
        </section>

        <section class="mb-6 bg-gray-800 rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-2"><?= __('projects_assign_mode') ?></h2>
            <p class="text-sm text-gray-400 mb-4"><?= __('projects_assign_intro') ?></p>
            <form method="POST" class="flex flex-col gap-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save_mode">
                <input type="hidden" name="project_id" value="<?= (int)$detail['id'] ?>">
                <?php foreach ($assignModes as $mode): ?>
                    <label class="flex items-start gap-3 text-sm cursor-pointer">
                        <input type="radio" name="assign_mode" value="<?= htmlspecialchars($mode) ?>"
                               <?= $detailMode === $mode ? 'checked' : '' ?>
                               class="mt-1 rounded bg-gray-600 border-gray-500">
                        <span>
                            <span class="text-gray-200 font-medium"><?= htmlspecialchars(__('projects_mode_' . $mode)) ?></span>
                            <?php if ($mode === 'suggest'): ?>
                                <span class="ml-2 inline-block px-2 py-0.5 text-xs rounded <?= $detailPending > 0 ? 'bg-yellow-900/50 border border-yellow-700 text-yellow-300' : 'bg-gray-700 text-gray-400' ?>">
                                    <?= htmlspecialchars(__('projects_pending', ['count' => $detailPending])) ?>
                                </span>
                            <?php endif; ?>
                            <br>
                            <span class="text-gray-400"><?= htmlspecialchars(__('projects_mode_' . $mode . '_desc')) ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
                <div class="flex justify-end mt-2">
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                        <?= __('projects_save') ?>
                    </button>
                </div>
            </form>
        </section>

        <section class="mb-6 bg-gray-800 rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-2"><?= __('projects_tolerances') ?></h2>
            <p class="text-sm text-gray-400 mb-4"><?= __('projects_tolerances_intro') ?></p>
            <form method="POST" class="flex flex-col gap-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save_tolerances">
                <input type="hidden" name="project_id" value="<?= (int)$detail['id'] ?>">
                <?php foreach ($defs as $key => $def): ?>
                    <?php $global = (string)($globals[$key] ?? $def['default']); ?>
                    <label class="flex items-center justify-between gap-4 text-sm">
                        <span class="text-gray-300"><?= htmlspecialchars(__('projects_tol_' . $key)) ?>
                            <span class="text-gray-500">(<?= htmlspecialchars($def['hint']) ?>)</span>
                        </span>
                        <input type="text" name="tolerances[<?= htmlspecialchars($key) ?>]" maxlength="64"
                               value="<?= htmlspecialchars((string)($detailOverrides[$key] ?? '')) ?>"
                               placeholder="<?= htmlspecialchars(__('projects_inherit_global', ['value' => $global])) ?>"
                               class="w-40 px-3 py-2 bg-gray-700 border border-gray-600 rounded text-gray-100">
                    </label>
                <?php endforeach; ?>
                <div class="flex justify-end gap-2 mt-2">
                    <button type="submit" name="reset_globals" value="1"
                            class="px-4 py-2 bg-gray-600 hover:bg-gray-500 text-white rounded-lg transition-colors">
                        <?= __('projects_reset') ?>
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                        <?= __('projects_save') ?>
                    </button>
                </div>
            </form>
        </section>

        <section class="mb-6 bg-gray-800 rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-2"><?= __('projects_review', ['count' => $detailPending]) ?></h2>
            <p class="text-sm text-gray-400 mb-4"><?= __('projects_review_intro') ?></p>
            <?php if (empty($pendingSuggestions)): ?>
                <p class="text-gray-500 text-sm"><?= __('projects_no_pending') ?></p>
            <?php else: ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="project_id" value="<?= (int)$detail['id'] ?>">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-gray-400 border-b border-gray-700">
                            <tr>
                                <th class="py-2 px-3"><input type="checkbox" onclick="document.querySelectorAll('.sug-check').forEach(c => c.checked = this.checked)" class="rounded bg-gray-600 border-gray-500"></th>
                                <th class="py-2 px-3"><?= __('projects_file') ?></th>
                                <th class="py-2 px-3"><?= __('projects_target') ?></th>
                                <th class="py-2 px-3"><?= __('projects_reason') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingSuggestions as $s): ?>
                                <tr class="border-b border-gray-700/50">
                                    <td class="py-2 px-3">
                                        <input type="checkbox" name="suggestion_ids[]" value="<?= (int)$s['id'] ?>" class="sug-check rounded bg-gray-600 border-gray-500">
                                    </td>
                                    <td class="py-2 px-3">
                                        <span class="font-medium"><?= htmlspecialchars($s['file_name']) ?></span>
                                        <span class="ml-2 text-xs px-1.5 py-0.5 rounded bg-gray-700 text-gray-300"><?= htmlspecialchars($s['imgtype']) ?></span>
                                        <?php if ($s['file_filter'] !== null && $s['file_filter'] !== ''): ?>
                                            <span class="ml-1 text-xs text-gray-400"><?= htmlspecialchars($s['file_filter']) ?></span>
                                        <?php endif; ?>
                                        <br><span class="text-xs text-gray-500"><?= htmlspecialchars($s['file_path']) ?></span>
                                    </td>
                                    <td class="py-2 px-3 text-gray-300"><?= htmlspecialchars(getSuggestionNodeLabel($conn, $s['level'], (int)$s['node_id'], $s['filter_name'])) ?></td>
                                    <td class="py-2 px-3 text-xs text-gray-400"><?= htmlspecialchars((string)($s['reason'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button type="submit" name="action" value="dismiss_suggestions"
                            class="px-4 py-2 bg-gray-600 hover:bg-gray-500 text-white rounded-lg transition-colors">
                        <?= __('projects_discard_selected') ?>
                    </button>
                    <button type="submit" name="action" value="accept_suggestions"
                            class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors">
                        <?= __('projects_accept_selected') ?>
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </section>

        <section class="bg-gray-800 rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-2"><?= __('projects_tree') ?></h2>
            <p class="text-sm text-gray-500"><?= __('projects_tree_coming') ?></p>
        </section>
        <?php endif; ?>
    </main>
</body>
</html>
