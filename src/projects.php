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
            // Project-scoped actions require an accessible project first.
            $needsProject = ['update', 'save_mode', 'delete', 'accept_suggestions', 'dismiss_suggestions', 'save_tolerances', 'remove_links', 'disable_links', 'enable_links', 'save_thresholds'];
            if (in_array($action, $needsProject, true)) {
                $gid = (int)($_POST['project_id'] ?? 0);
                $gproj = $gid > 0 ? getProject($conn, $gid) : null;
                if ($gproj === null) {
                    throw new InvalidArgumentException(__('projects_error_name'));
                }
                if (!canAccessProject($conn, $gid)) {
                    http_response_code(403);
                    throw new InvalidArgumentException(__('projects_no_access'));
                }
            }
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
                $target = $id > 0 ? getProject($conn, $id) : null;
                if ($target === null) {
                    throw new InvalidArgumentException(__('projects_error_name'));
                }
                if (getProjectAssignMode($target) === 'frozen') {
                    throw new InvalidArgumentException(__('projects_add_frozen'));
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
            } elseif ($action === 'remove_links' || $action === 'disable_links' || $action === 'enable_links') {
                $id = (int)($_POST['project_id'] ?? 0);
                $keys = array_values((array)($_POST['link_keys'] ?? []));
                if ($action === 'remove_links') {
                    $done = removeProjectLinks($conn, $id, $keys);
                    $message = __('projects_links_removed', ['count' => $done]);
                } else {
                    $done = setProjectLinksEnabled($conn, $id, $keys, $action === 'enable_links');
                    $message = __('projects_links_set', ['count' => $done]);
                }
                $messageType = 'success';
            } elseif ($action === 'save_thresholds') {
                $id = (int)($_POST['project_id'] ?? 0);
                $filter = trim((string)($_POST['filter'] ?? ''));
                saveGroupThresholds(
                    $conn,
                    $id,
                    (int)($_POST['setup_id'] ?? 0),
                    (int)($_POST['panel_id'] ?? 0),
                    $filter !== '' ? $filter : null,
                    trim((string)($_POST['exptime'] ?? '')) !== '' ? $_POST['exptime'] : null,
                    (array)($_POST['thresholds'] ?? [])
                );
                $message = __('projects_thresholds_saved');
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
$assignModes = getAssignModes();
$pendingTree = $detail !== null ? getProjectTree($conn, (int)$detail['id'], true) : null;
$projectTree = $pendingTree !== null ? stripPendingTree($pendingTree) : null;
$projectTols = [];
foreach ($defs as $tkey => $tdef) {
    $projectTols[$tkey] = $detail !== null ? resolve_tol($conn, (int)$detail['id'], $tkey) : $tdef['default'];
}
$tolExpFrac = parseTolFraction((string)($projectTols['tol_exp'] ?? '1%'), 0.01);
$projectDiag = $projectTree !== null ? diagnoseProjectTree($projectTree, $projectTols) : [];
$intGroups = $projectTree !== null
    ? getIntegrationGroups($projectTree, $tolExpFrac, $detail !== null ? getProjectThresholds($conn, (int)$detail['id']) : [])
    : [];
if ($projectTree !== null && !empty($intGroups)) {
    $projectTree = markTreeAutoOff($projectTree, indexAutoOffLights($intGroups));
}
$projectBlocked = $detail !== null && !canAccessProject($conn, (int)$detail['id']);
if ($projectBlocked) {
    http_response_code(403);
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <title><?= __('projects') ?> - <?= __('site_title') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="/assets/css/output.css?v=<?= @filemtime(__DIR__ . '/assets/css/output.css') ?: 0 ?>" rel="stylesheet">
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

        <?php if ($detail === null && !$projectBlocked): ?>
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
        <?php elseif ($projectBlocked): ?>
        <section class="bg-gray-800 rounded-lg p-6">
            <div class="p-3 rounded text-sm bg-red-900/50 border border-red-700 text-red-300">
                <?= htmlspecialchars(__('projects_no_access')) ?>
            </div>
            <div class="mt-4">
                <a href="/projects.php" class="text-sm text-gray-300 hover:text-white">&larr; <?= __('back') ?></a>
            </div>
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
            <details>
                <summary class="text-lg font-semibold cursor-pointer"><?= __('projects_assign_mode') ?></summary>
                <p class="text-sm text-gray-400 my-4"><?= __('projects_assign_intro') ?></p>
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
                                <button type="button" id="sugModalOpen"
                                        class="ml-2 inline-block px-2 py-0.5 text-xs rounded transition-colors <?= $detailPending > 0 ? 'bg-yellow-900/50 border border-yellow-700 text-yellow-300 hover:bg-yellow-800/50' : 'bg-gray-700 text-gray-400 hover:bg-gray-600' ?>">
                                    <?= htmlspecialchars(__('projects_pending', ['count' => $detailPending])) ?>
                                </button>
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
            </details>
        </section>

        <section class="mb-6 bg-gray-800 rounded-lg p-6">
            <details>
                <summary class="text-lg font-semibold cursor-pointer"><?= __('projects_tolerances') ?></summary>
                <p class="text-sm text-gray-400 my-4"><?= __('projects_tolerances_intro') ?></p>
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
                               placeholder="<?= htmlspecialchars(__('projects_inherit_default', ['value' => $global])) ?>"
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
            </details>
        </section>

        <div id="sugModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/60">
            <div class="bg-gray-800 rounded-lg p-6 w-full max-w-4xl max-h-[85vh] overflow-y-auto">
                <div class="flex items-center justify-between mb-2">
                    <h2 class="text-lg font-semibold"><?= __('projects_review', ['count' => $detailPending]) ?></h2>
                    <button type="button" id="sugModalClose" class="text-gray-400 hover:text-white text-xl leading-none">&times;</button>
                </div>
                <p class="text-sm text-gray-400 mb-4"><?= __('projects_review_intro') ?></p>
            <?php if ($detailPending === 0): ?>
                <p class="text-gray-500 text-sm"><?= __('projects_no_pending') ?></p>
            <?php else: ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="project_id" value="<?= (int)$detail['id'] ?>">
                <div class="mb-3 text-sm">
                    <label class="text-gray-300 cursor-pointer"><input type="checkbox" onclick="document.querySelectorAll('#sugModal .sug-check').forEach(c => c.checked = this.checked)" class="rounded bg-gray-600 border-gray-500"> <?= __('projects_select_all') ?></label>
                </div>
                <?php include __DIR__ . '/includes/projects_tree_pending.php'; ?>
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
            </div>
        </div>
        <script>
        (function () {
            const modal = document.getElementById('sugModal');
            const openBtn = document.getElementById('sugModalOpen');
            const closeBtn = document.getElementById('sugModalClose');
            function open() {
                if (!modal) return;
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }
            function close() {
                if (!modal) return;
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }
            if (openBtn) openBtn.addEventListener('click', open);
            if (closeBtn) closeBtn.addEventListener('click', close);
            if (modal) modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
        })();
        </script>

        <section class="bg-gray-800 rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-2"><?= __('projects_tree') ?></h2>
            <form method="POST" id="treeBulkForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="project_id" value="<?= (int)$detail['id'] ?>">
                <div class="flex flex-wrap justify-end gap-2 mb-4">
                    <button type="submit" name="action" value="enable_links"
                            class="px-3 py-1 text-sm bg-green-700 hover:bg-green-600 text-white rounded transition-colors">
                        <?= __('projects_enable_selected') ?>
                    </button>
                    <button type="submit" name="action" value="disable_links"
                            class="px-3 py-1 text-sm bg-yellow-700 hover:bg-yellow-600 text-white rounded transition-colors">
                        <?= __('projects_disable_selected') ?>
                    </button>
                    <button type="submit" name="action" value="remove_links"
                            onclick="return confirm(<?= htmlspecialchars(json_encode(__('projects_confirm_remove'))) ?>);"
                            class="px-3 py-1 text-sm bg-red-700 hover:bg-red-600 text-white rounded transition-colors">
                        <?= __('projects_remove_selected') ?>
                    </button>
                </div>
                <?php include __DIR__ . '/includes/projects_tree.php'; ?>
            </form>
            <div class="text-xs text-gray-400 mt-4 flex flex-wrap items-center gap-x-4 gap-y-1">
                <span class="font-semibold"><?= __('projects_legend') ?>:</span>
                <span><?= diagBox('green', 'B', __('projects_cal_bias')) ?><?= diagBox('green', 'D', __('projects_cal_dark')) ?><?= diagBox('green', 'F', __('projects_cal_flat')) ?> <?= htmlspecialchars(__('projects_legend_calib')) ?></span>
                <span>⏳ <?= htmlspecialchars(__('projects_pending_hypo')) ?></span>
                <span>(<?= __('projects_link_off') ?>) = <?= htmlspecialchars(__('projects_disable_selected')) ?></span>
            </div>
        </section>

        <section class="bg-gray-800 rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-2"><?= __('projects_igroups') ?></h2>
            <?php if (!empty($intGroups)): ?>
            <script src="assets/js/vendor/chart.umd.min.js"></script>
            <?php endif; ?>
            <?php if (empty($intGroups)): ?>
                <p class="text-sm text-gray-500"><?= __('projects_igroups_empty') ?></p>
            <?php else: ?>
                <?php
                $totCount = 0;
                $totExp = 0.0;
                $totAuto = 0;
                $totNights = [];
                foreach ($intGroups as $tg) {
                    $totCount += (int)$tg['count'];
                    $totExp += (float)$tg['exposure'];
                    foreach ($tg['lights'] as $tli) {
                        if (!empty($tli['auto_off'])) {
                            $totAuto++;
                        }
                    }
                    foreach ($tg['nights'] as $tn) {
                        $totNights[$tn] = true;
                    }
                }
                ?>
                <div class="text-sm text-gray-200 font-medium mb-3">
                    <?= htmlspecialchars(__('projects_igroup_total')) ?>:
                    <?= htmlspecialchars(__('projects_lights_count', ['count' => $totCount])) ?> · <span title="<?= htmlspecialchars(fmtExp($totExp)) ?>"><?= htmlspecialchars(number_format($totExp / 3600, 1)) ?> h</span> · <?= htmlspecialchars(__('projects_igroup_nights', ['count' => count($totNights)])) ?> · <?= htmlspecialchars(__('projects_igroup_excluded', ['total' => $totAuto])) ?>
                </div>
                <?php foreach ($intGroups as $gi => $grp): ?>
                    <?php $grpAuto = count(array_filter($grp['lights'], fn($li) => !empty($li['auto_off']))); ?>
                    <div class="mb-2 border border-gray-700 rounded-lg">
                        <div class="px-4 pt-2 text-sm font-medium">
                            <?= htmlspecialchars($grp['setup_label']) ?> · <?= htmlspecialchars($grp['panel_label']) ?> · <?= __('projects_filter') ?> <?= htmlspecialchars($grp['filter'] !== '' ? $grp['filter'] : '—') ?> · <?= htmlspecialchars(fmtExpShort($grp['exptime'])) ?>
                        </div>
                        <div class="px-4 pb-2 text-xs text-gray-400">
                            <?= htmlspecialchars(__('projects_lights_count', ['count' => $grp['count']])) ?> · <span title="<?= htmlspecialchars(fmtExp((float)$grp['exposure'])) ?>"><?= htmlspecialchars(number_format((float)$grp['exposure'] / 3600, 1)) ?> h</span> · <?= htmlspecialchars(__('projects_igroup_nights', ['count' => count($grp['nights'])])) ?>: <?= htmlspecialchars(implode(', ', $grp['nights'])) ?> · <?= htmlspecialchars(__('projects_igroup_excluded', ['total' => $grpAuto])) ?>
                        </div>
                        <details>
                            <summary class="cursor-pointer px-4 py-1.5 hover:bg-gray-700/40 rounded text-sm text-gray-300"><?= __('projects_igroup_show_files', ['count' => count($grp['lights'])]) ?></summary>
                        <div class="px-4 py-2 overflow-x-auto">
                            <table class="w-full text-xs text-left igroup-table" data-group="<?= (int)$gi ?>">
                                <thead class="text-gray-400 border-b border-gray-700">
                                    <tr>
                                        <th class="py-1 px-2 cursor-pointer hover:text-white" data-type="text"><?= __('projects_igroup_file') ?> ↕</th>
                                        <th class="py-1 px-2 cursor-pointer hover:text-white" data-type="text"><?= __('date') ?> ↕</th>
                                        <th class="py-1 px-2 cursor-pointer hover:text-white text-right" data-type="num"><?= __('hfr') ?> ↕</th>
                                        <th class="py-1 px-2 cursor-pointer hover:text-white text-right" data-type="num"><?= __('hfr_sd') ?> ↕</th>
                                        <th class="py-1 px-2 cursor-pointer hover:text-white text-right" data-type="num"><?= __('fwhm') ?> ↕</th>
                                        <th class="py-1 px-2 cursor-pointer hover:text-white text-right" data-type="num"><?= __('eccentricity') ?> ↕</th>
                                        <th class="py-1 px-2 cursor-pointer hover:text-white text-right" data-type="num"><?= __('star_count') ?> ↕</th>
                                        <th class="py-1 px-2 cursor-pointer hover:text-white text-right" data-type="num"><?= __('snr_weight') ?> ↕</th>
                                        <th class="py-1 px-2 cursor-pointer hover:text-white text-right" data-type="num"><?= __('psf_signal') ?> ↕</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($grp['lights'] as $li): ?>
                                        <?php $liOff = empty($li['enabled']); ?>
                                        <?php $liAuto = !$liOff && !empty($li['auto_off']); ?>
                                        <tr class="border-b border-gray-700/40<?= ($liOff || $liAuto) ? ' opacity-60' : '' ?>" data-enabled="<?= $liOff ? '0' : '1' ?>" data-auto="<?= $liAuto ? '1' : '0' ?>" data-hfr-sd="<?= htmlspecialchars((string)($li['hfr_sd'] ?? '')) ?>" data-psf="<?= htmlspecialchars((string)($li['psf_signal'] ?? '')) ?>" data-linkkey="<?= htmlspecialchars((string)($li['link_key'] ?? '')) ?>">
                                            <td class="py-1 px-2" data-val="<?= htmlspecialchars((string)$li['name']) ?>"><?= htmlspecialchars($li['name']) ?><?php if ($liOff): ?> <span class="text-gray-500">(<?= __('projects_link_off') ?>)</span><?php elseif ($liAuto): ?> <span class="text-gray-500">(<?= __('projects_auto_off') ?>)</span><?php endif; ?></td>
                                            <td class="py-1 px-2" data-val="<?= htmlspecialchars((string)($li['date_obs'] ?? '')) ?>"><?= htmlspecialchars((string)($li['date_obs'] ?? '')) ?></td>
                                            <td class="py-1 px-2 text-right" data-val="<?= htmlspecialchars((string)($li['hfr'] ?? '')) ?>"><?= htmlspecialchars($li['hfr'] !== null && $li['hfr'] !== '' ? number_format((float)$li['hfr'], 2) : '—') ?></td>
                                            <td class="py-1 px-2 text-right" data-val="<?= htmlspecialchars((string)($li['hfr_sd'] ?? '')) ?>"><?= htmlspecialchars($li['hfr_sd'] !== null && $li['hfr_sd'] !== '' ? number_format((float)$li['hfr_sd'], 2) : '—') ?></td>
                                            <td class="py-1 px-2 text-right" data-val="<?= htmlspecialchars((string)($li['fwhm'] ?? '')) ?>"><?= htmlspecialchars($li['fwhm'] !== null && $li['fwhm'] !== '' ? number_format((float)$li['fwhm'], 2) : '—') ?></td>
                                            <td class="py-1 px-2 text-right" data-val="<?= htmlspecialchars((string)($li['eccentricity'] ?? '')) ?>"><?= htmlspecialchars($li['eccentricity'] !== null && $li['eccentricity'] !== '' ? number_format((float)$li['eccentricity'], 3) : '—') ?></td>
                                            <td class="py-1 px-2 text-right" data-val="<?= htmlspecialchars((string)($li['star_count'] ?? '')) ?>"><?= htmlspecialchars($li['star_count'] !== null && $li['star_count'] !== '' ? (string)$li['star_count'] : '—') ?></td>
                                            <td class="py-1 px-2 text-right" data-val="<?= htmlspecialchars((string)($li['snr_weight'] ?? '')) ?>"><?= htmlspecialchars($li['snr_weight'] !== null && $li['snr_weight'] !== '' ? number_format((float)$li['snr_weight'], 2) : '—') ?></td>
                                            <td class="py-1 px-2 text-right" data-val="<?= htmlspecialchars((string)($li['psf_signal'] ?? '')) ?>"><?= htmlspecialchars($li['psf_signal'] !== null && $li['psf_signal'] !== '' ? number_format((float)$li['psf_signal'], 6) : '—') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="border-t border-gray-600 font-medium text-gray-200">
                                        <td class="py-1 px-2"><?= __('projects_igroup_median') ?></td>
                                        <td class="py-1 px-2"></td>
                                        <td class="py-1 px-2 text-right"><?= htmlspecialchars($grp['medians']['hfr'] !== null ? number_format((float)$grp['medians']['hfr'], 2) : '—') ?></td>
                                        <td class="py-1 px-2 text-right"><?= htmlspecialchars($grp['medians']['hfr_sd'] !== null ? number_format((float)$grp['medians']['hfr_sd'], 2) : '—') ?></td>
                                        <td class="py-1 px-2 text-right"><?= htmlspecialchars($grp['medians']['fwhm'] !== null ? number_format((float)$grp['medians']['fwhm'], 2) : '—') ?></td>
                                        <td class="py-1 px-2 text-right"><?= htmlspecialchars($grp['medians']['eccentricity'] !== null ? number_format((float)$grp['medians']['eccentricity'], 3) : '—') ?></td>
                                        <td class="py-1 px-2 text-right"><?= htmlspecialchars($grp['medians']['star_count'] !== null ? number_format((float)$grp['medians']['star_count'], 0) : '—') ?></td>
                                        <td class="py-1 px-2 text-right"><?= htmlspecialchars($grp['medians']['snr_weight'] !== null ? number_format((float)$grp['medians']['snr_weight'], 2) : '—') ?></td>
                                        <td class="py-1 px-2 text-right"><?= htmlspecialchars($grp['medians']['psf_signal'] !== null ? number_format((float)$grp['medians']['psf_signal'], 6) : '—') ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                            <div class="mt-3 border border-gray-700 rounded p-3 igroup-reject" data-setup="<?= (int)$grp['setup_id'] ?>" data-panel="<?= (int)$grp['panel_id'] ?>" data-filter="<?= htmlspecialchars($grp['filter']) ?>" data-exp="<?= htmlspecialchars((string)($grp['exptime'] ?? '')) ?>">
                                <div class="text-xs font-semibold text-gray-300 mb-2"><?= __('projects_reject_title') ?></div>
                                <div class="flex flex-wrap gap-x-4 gap-y-2">
                                    <?php
                                    $rejLabels = ['hfr' => __('hfr'), 'fwhm' => __('fwhm'), 'hfr_sd' => __('hfr_sd'), 'eccentricity' => __('eccentricity'), 'star_count' => __('star_count'), 'snr_weight' => __('snr_weight'), 'psf_signal' => __('psf_signal')];
                                    ?>
                                    <?php foreach (groupThresholdDirs() as $rk => $rdir): ?>
                                        <?php
                                        $rlabel = $rejLabels[$rk] ?? $rk;
                                        $rmed = $grp['medians'][$rk] ?? null;
                                        $rdec = $rk === 'eccentricity' ? 3 : ($rk === 'psf_signal' ? 6 : 2);
                                        $rval = $grp['thresholds'][$rk] ?? null;
                                        ?>
                                        <label class="text-xs text-gray-400"><?= htmlspecialchars($rlabel) ?>
                                            <span class="text-gray-500"><?= $rdir === 'above' ? htmlspecialchars(__('projects_reject_above')) : htmlspecialchars(__('projects_reject_below')) ?></span>
                                            <input type="number" step="any" data-metric="<?= $rk ?>" data-dir="<?= $rdir ?>"
                                                   value="<?= $rval !== null ? htmlspecialchars((string)$rval) : '' ?>"
                                                   placeholder="<?= htmlspecialchars($rmed !== null ? number_format((float)$rmed, $rdec) : '') ?>"
                                                   class="ml-1 w-24 px-2 py-1 bg-gray-700 border border-gray-600 rounded text-gray-100">
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="text-xs text-gray-500 mt-1"><?= __('projects_reject_hint') ?></div>
                                <div class="text-xs text-amber-400/90 mt-1">⚠️ <?= htmlspecialchars(__('projects_reject_calib_note')) ?></div>
                                <div class="flex items-center gap-3 mt-2">
                                    <button type="button" class="reject-save px-3 py-1 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded transition-colors">
                                        <?= __('projects_reject_save') ?>
                                    </button>
                                    <button type="button" class="reject-auto px-3 py-1 text-sm bg-purple-700 hover:bg-purple-600 text-white rounded transition-colors">
                                        <?= __('projects_reject_auto') ?>
                                    </button>
                                    <button type="button" class="reject-reset px-3 py-1 text-sm bg-gray-600 hover:bg-gray-500 text-white rounded transition-colors">
                                        <?= __('projects_reject_reset') ?>
                                    </button>
                                    <span class="reject-count text-xs text-gray-400"></span>
                                </div>
                            </div>
                            <?php
                            $grpHasMetrics = false;
                            foreach ($grp['lights'] as $mli) {
                                foreach (['hfr', 'fwhm', 'hfr_sd', 'eccentricity', 'star_count', 'snr_weight', 'psf_signal'] as $mk) {
                                    if (isset($mli[$mk]) && $mli[$mk] !== '' && $mli[$mk] !== null) {
                                        $grpHasMetrics = true;
                                        break 2;
                                    }
                                }
                            }
                            ?>
                            <?php if ($grpHasMetrics): ?>
                            <details class="mt-2 igroup-charts-wrap" data-group="<?= (int)$gi ?>">
                                <summary class="cursor-pointer text-xs text-gray-400 hover:text-white">📊 <?= __('projects_igroup_charts') ?></summary>
                                <div class="flex flex-col gap-4 mt-2 igroup-charts">
                                    <?php foreach (['hfr', 'fwhm', 'hfr_sd', 'eccentricity', 'star_count', 'snr_weight', 'psf_signal'] as $mi => $mk): ?>
                                    <div style="height: 190px"><canvas id="ig-chart-<?= (int)$gi ?>-<?= (int)$mi ?>"></canvas></div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                            <?php endif; ?>
                        </div>
                    </details>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
        <script>
        (function () {
            // Clickable headers sort each integration group table (numeric-aware).
            document.querySelectorAll('.igroup-table thead th').forEach(th => {
                th.addEventListener('click', () => {
                    const table = th.closest('table');
                    const idx = Array.from(th.parentNode.children).indexOf(th);
                    const numeric = th.dataset.type === 'num';
                    const asc = th.dataset.asc !== '1';
                    table.querySelectorAll('thead th').forEach(h => delete h.dataset.asc);
                    th.dataset.asc = asc ? '1' : '0';
                    const rows = Array.from(table.querySelectorAll('tbody tr'));
                    rows.sort((a, b) => {
                        const va = a.children[idx]?.dataset.val ?? '';
                        const vb = b.children[idx]?.dataset.val ?? '';
                        if (numeric) {
                            const na = parseFloat(va);
                            const nb = parseFloat(vb);
                            if (isNaN(na) && isNaN(nb)) return 0;
                            if (isNaN(na)) return 1;
                            if (isNaN(nb)) return -1;
                            return asc ? na - nb : nb - na;
                        }
                        return asc ? va.localeCompare(vb) : vb.localeCompare(va);
                    });
                    const tb = table.querySelector('tbody');
                    rows.forEach(r => tb.appendChild(r));
                    if (typeof refreshIgroupCharts === 'function') refreshIgroupCharts(table);
                });
            });

            // --- Integration group bar charts (one bar per photo) ---
            // X axis follows the table's current row order; bars are greyed
            // when the file is disabled at project level.
            const IGROUP_SERIES = [
                { key: 'hfr', label: <?= json_encode(__('hfr') . ' (px)') ?>, color: '#60a5fa', cell: 2 },
                { key: 'hfr_sd', label: <?= json_encode(__('hfr_sd') . ' (px)') ?>, color: '#a78bfa', cell: 3 },
                { key: 'fwhm', label: <?= json_encode(__('fwhm') . ' (arcsec)') ?>, color: '#34d399', cell: 4 },
                { key: 'eccentricity', label: <?= json_encode(__('eccentricity')) ?>, color: '#fbbf24', cell: 5 },
                { key: 'star_count', label: <?= json_encode(__('star_count')) ?>, color: '#f472b6', cell: 6 },
                { key: 'snr_weight', label: <?= json_encode(__('snr_weight')) ?>, color: '#22d3ee', cell: 7 },
                { key: 'psf_signal', label: <?= json_encode(__('psf_signal')) ?>, color: '#fb7185', cell: 8 },
            ];
            const IGROUP_OFF_COLOR = '#4b5563';
            const IGROUP_MEDIAN_LABEL = <?= json_encode(__('metrics_median')) ?>;
            const igCharts = {};
            function igMedian(values) {
                const nums = values.filter(v => typeof v === 'number' && isFinite(v)).sort((a, b) => a - b);
                if (!nums.length) return null;
                const mid = Math.floor(nums.length / 2);
                return nums.length % 2 ? nums[mid] : (nums[mid - 1] + nums[mid]) / 2;
            }
            function igRowsInOrder(table) {
                return Array.from(table.querySelectorAll('tbody tr')).map(tr => {
                    const cells = tr.children;
                    const num = (raw) => {
                        const v = parseFloat(raw ?? '');
                        return isNaN(v) ? null : v;
                    };
                    const vals = {};
                    IGROUP_SERIES.forEach(s => {
                        vals[s.key] = s.cell !== undefined ? num(cells[s.cell]?.dataset.val) : num(tr.dataset[s.attr]);
                    });
                    return { name: cells[0]?.dataset.val || '', enabled: tr.dataset.enabled === '1', linkkey: tr.dataset.linkkey || '', vals };
                });
            }
            // Shared rejection evaluation for one group table: rows failing any
            // active threshold (direction fixed per metric). Returns rejected
            // row indices; files missing the metric are never rejected by it.
            function igRejectedIndices(table) {
                const panel = table.parentElement?.querySelector('.igroup-reject');
                if (!panel) return new Set();
                const rules = Array.from(panel.querySelectorAll('input[data-metric]'))
                    .map(inp => ({ key: inp.dataset.metric, dir: inp.dataset.dir, t: inp.value.trim() === '' ? null : parseFloat(inp.value) }))
                    .filter(r => r.t !== null && !isNaN(r.t));
                if (!rules.length) return new Set();
                const rows = igRowsInOrder(table);
                const out = new Set();
                rows.forEach((r, i) => {
                    // Inclusive comparisons (>=, <=): the clicked bar is always included.
                    const bad = rules.some(rule => {
                        const v = r.vals[rule.key];
                        if (v === null || v === undefined) return false;
                        return rule.dir === 'above' ? v >= rule.t : v <= rule.t;
                    });
                    if (bad) out.add(i);
                });
                return out;
            }
            function igBuildGroup(gi) {
                const table = document.querySelector('.igroup-table[data-group="' + gi + '"]');
                if (!table || typeof Chart === 'undefined') return;
                const rows = igRowsInOrder(table);
                if (!rows.length) return;
                const gridColor = 'rgba(255,255,255,0.08)';
                const tickColor = '#9ca3af';
                IGROUP_SERIES.forEach((s, mi) => {
                    const canvas = document.getElementById('ig-chart-' + gi + '-' + mi);
                    if (!canvas) return;
                    const cid = 'ig-chart-' + gi + '-' + mi;
                    if (igCharts[cid]) { igCharts[cid].destroy(); delete igCharts[cid]; }
                    const data = rows.map(r => r.vals[s.key]);
                    if (!data.some(v => v !== null)) return;
                    const rejected = igRejectedIndices(table);
                    const med = igMedian(rows.filter((r, i) => r.enabled && !rejected.has(i)).map(r => r.vals[s.key]));
                    // Active threshold for this metric (same inputs as evaluation).
                    let thr = null;
                    const thrInp = table.parentElement?.querySelector('.igroup-reject input[data-metric="' + s.key + '"]');
                    if (thrInp && thrInp.value.trim() !== '') {
                        const tv = parseFloat(thrInp.value);
                        if (!isNaN(tv)) thr = tv;
                    }
                    const datasets = [{
                        label: s.label,
                        data,
                        backgroundColor: rows.map((r, i) => rejected.has(i) ? IGROUP_OFF_COLOR : s.color),
                        borderWidth: 0,
                        order: 1,
                    }];
                    if (thr !== null) {
                        datasets.push({
                            label: (thrInp?.dataset.dir === 'below' ? '≤ ' : '≥ ') + thr,
                            type: 'line',
                            data: new Array(data.length).fill(thr),
                            borderColor: '#ef4444',
                            borderWidth: 1.5,
                            borderDash: [6, 4],
                            pointRadius: 0,
                            order: 2,
                        });
                    }
                    if (med !== null) {
                        datasets.push({
                            label: IGROUP_MEDIAN_LABEL,
                            type: 'line',
                            data: new Array(data.length).fill(med),
                            borderColor: '#9ca3af',
                            borderWidth: 1.5,
                            borderDash: [6, 4],
                            pointRadius: 0,
                            order: 2,
                        });
                    }
                    igCharts[cid] = new Chart(canvas, {
                        type: 'bar',
                        data: {
                            labels: rows.map((_, i) => i + 1),
                            datasets,
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            onClick: (evt, elements) => {
                                // Clicking a bar sets its exact value as the threshold
                                // for that metric (median line clicks ignored).
                                // Full precision: with inclusive >= / <= the clicked
                                // bar is always included, no rounding games.
                                if (!elements || !elements.length || elements[0].datasetIndex !== 0) return;
                                const table = document.querySelector('.igroup-table[data-group="' + gi + '"]');
                                if (!table) return;
                                const val = igRowsInOrder(table)[elements[0].index]?.vals[s.key];
                                if (val === null || val === undefined) return;
                                const panel = table.parentElement?.querySelector('.igroup-reject');
                                const inp = panel?.querySelector('input[data-metric="' + s.key + '"]');
                                if (!inp) return;
                                inp.value = String(val);
                                igRefreshPanel(panel);
                            },
                            plugins: {
                                title: { display: true, text: s.label, color: '#e5e7eb', font: { size: 13, weight: 'bold' } },
                                legend: { labels: { color: tickColor, boxWidth: 20 } },
                                tooltip: {
                                    callbacks: {
                                        title: (items) => {
                                            if (!items.length) return '';
                                            const idx = items[0].dataIndex;
                                            return '#' + (idx + 1) + ' ' + (rows[idx]?.name || '') + (rows[idx] && !rows[idx].enabled ? ' (off)' : '');
                                        },
                                    },
                                },
                            },
                            scales: {
                                x: { grid: { color: gridColor }, ticks: { color: tickColor, maxTicksLimit: 12 } },
                                y: { grid: { color: gridColor }, ticks: { color: tickColor, maxTicksLimit: 6 }, grace: '5%' },
                            },
                        },
                    });
                });
            }
            function refreshIgroupCharts(table) {
                const groupDetails = table.closest('details');
                const wrap = groupDetails ? groupDetails.querySelector('.igroup-charts-wrap') : null;
                if (wrap && wrap.open) igBuildGroup(table.dataset.group);
            }
            document.querySelectorAll('.igroup-charts-wrap').forEach(wrap => {
                wrap.addEventListener('toggle', () => {
                    if (wrap.open && !wrap.dataset.built) {
                        wrap.dataset.built = '1';
                        igBuildGroup(wrap.dataset.group);
                    }
                });
            });

            // --- Rejection thresholds: combined OR evaluation with live preview ---
            // Direction is fixed per metric type (lower-is-better excludes above,
            // higher-is-better excludes below). Files missing a metric are never
            // rejected by it. Save persists thresholds; they alone decide inclusion.
            const IGROUP_REJECT_COUNT = <?= json_encode(__('projects_reject_count')) ?>;
            function igEvalPanel(panel) {
                const table = panel.parentElement?.querySelector('.igroup-table');
                if (!table) return [];
                const rejected = igRejectedIndices(table);
                const rows = igRowsInOrder(table);
                const hits = [];
                const trs = table.querySelectorAll('tbody tr');
                rows.forEach((r, i) => {
                    if (trs[i]) trs[i].classList.toggle('bg-red-900/50', rejected.has(i));
                    if (rejected.has(i) && r.linkkey) hits.push(r.linkkey);
                });
                return hits;
            }
            function igRefreshPanel(panel) {
                const count = panel.querySelector('.reject-count');
                const table = panel.parentElement?.querySelector('.igroup-table');
                const total = table ? table.querySelectorAll('tbody tr').length : 0;
                // Store hits on the panel for the count display.
                panel._hits = igEvalPanel(panel);
                if (count) {
                    count.textContent = IGROUP_REJECT_COUNT
                        .replace('{n}', panel._hits.length)
                        .replace('{total}', total);
                }
                // Keep bar colors in sync when charts are already built.
                const rtable = panel.parentElement?.querySelector('.igroup-table');
                if (rtable) {
                    const rwrap = document.querySelector('.igroup-charts-wrap[data-group="' + rtable.dataset.group + '"]');
                    if (rwrap && rwrap.open && rwrap.dataset.built) igBuildGroup(rtable.dataset.group);
                }
            }
            document.querySelectorAll('.igroup-reject').forEach(panel => {
                panel.addEventListener('input', () => igRefreshPanel(panel));
                const save = panel.querySelector('.reject-save');
                const auto = panel.querySelector('.reject-auto');
                const reset = panel.querySelector('.reject-reset');
                const postThresholds = () => {
                    const csrf = document.querySelector('#treeBulkForm input[name="csrf_token"]')?.value || '';
                    const pid = document.querySelector('#treeBulkForm input[name="project_id"]')?.value || '';
                    const fd = new FormData();
                    fd.append('csrf_token', csrf);
                    fd.append('project_id', pid);
                    fd.append('action', 'save_thresholds');
                    fd.append('setup_id', panel.dataset.setup || '');
                    fd.append('panel_id', panel.dataset.panel || '');
                    fd.append('filter', panel.dataset.filter || '');
                    fd.append('exptime', panel.dataset.exp || '');
                    panel.querySelectorAll('input[data-metric]').forEach(inp => {
                        fd.append('thresholds[' + inp.dataset.metric + ']', inp.value.trim());
                    });
                    return { fd, save };
                };
                if (save) save.addEventListener('click', () => {
                    const { fd, save: btn } = postThresholds();
                    if (btn) btn.disabled = true;
                    fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd })
                        .finally(() => window.location.reload());
                });
                if (auto) auto.addEventListener('click', () => {
                    // Very permissive robust proposal: median ± 5·MAD·1.4826.
                    // Only truly bad frames go out; borderline ones stay in.
                    // Direction fixed per metric type as usual. Metrics
                    // with <4 values or zero spread are left blank. Fills the
                    // inputs without saving: review the preview, then Save.
                    const table = panel.parentElement?.querySelector('.igroup-table');
                    if (!table) return;
                    const rows = igRowsInOrder(table);
                    const robust = {};
                    ['hfr', 'fwhm', 'hfr_sd', 'eccentricity', 'star_count', 'snr_weight', 'psf_signal'].forEach(k => {
                        const nums = rows.map(r => r.vals[k]).filter(v => v !== null && v !== undefined);
                        if (nums.length < 4) return;
                        const med = igMedian(nums);
                        const mad = igMedian(nums.map(v => Math.abs(v - med)));
                        const sigma = 1.4826 * mad;
                        if (!(sigma > 0)) return;
                        robust[k] = { med, sigma };
                    });
                    panel.querySelectorAll('input[data-metric]').forEach(inp => {
                        const st = robust[inp.dataset.metric];
                        if (!st) return;
                        let t = inp.dataset.dir === 'above' ? st.med + 5 * st.sigma : st.med - 5 * st.sigma;
                        if (inp.dataset.dir === 'below') t = Math.max(0, t);
                        inp.value = String(Number(t.toPrecision(6)));
                    });
                    igRefreshPanel(panel);
                });
                if (reset) reset.addEventListener('click', () => {
                    // Clear fields and persist immediately (deletes the row).
                    panel.querySelectorAll('input[data-metric]').forEach(inp => { inp.value = ''; });
                    if (save) save.click();
                });
                igRefreshPanel(panel);
            });
        })();
        </script>
        <script>
        (function () {
            const form = document.getElementById('treeBulkForm');
            if (!form) return;
            // Group checkbox toggles every file checkbox in its own .tnode subtree.
            // (Group boxes live outside <summary> on purpose: clicks inside
            // <summary> are swallowed by the details toggle in browsers.)
            form.addEventListener('change', (e) => {
                if (!e.target.classList.contains('pgroup-check')) return;
                const scope = e.target.closest('.tnode');
                if (!scope) return;
                scope.querySelectorAll('.pfl-check').forEach(cb => { cb.checked = e.target.checked; });
                scope.querySelectorAll('.pgroup-check').forEach(cb => { if (cb !== e.target) cb.checked = e.target.checked; });
            });
        })();
        </script>
        <?php endif; ?>
    </main>
</body>
</html>
