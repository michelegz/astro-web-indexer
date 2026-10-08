<?php
require_once __DIR__ . '/includes/init.php';

$conn = connectDB();
$message = '';
$messageType = '';

// Bulk selections used to arrive as N input vars (suggestion_ids[],
// link_keys[]), one checkbox per file. Past ~1000 files PHP discards the
// tail of $_POST (max_input_vars) before this code even runs, so the forms
// now pack the selection into a single CSV hidden field via JS. Accept both
// shapes here so the no-JS fallback keeps working.
function awiCollectPostList(string $arrKey, string $csvKey): array
{
    $out = array_values((array)($_POST[$arrKey] ?? []));
    $csv = trim((string)($_POST[$csvKey] ?? ''));
    if ($csv !== '') {
        foreach (explode(',', $csv) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
    }
    return $out;
}

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
            $needsProject = ['update', 'save_mode', 'delete', 'accept_suggestions', 'dismiss_suggestions', 'resuggest_dismissed', 'request_suggest', 'save_tolerances', 'save_grouping', 'save_filter_aliases', 'remove_links', 'disable_links', 'enable_links', 'promote_links', 'demote_links', 'set_scope', 'save_thresholds', 'rename_setup'];
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
                $ids = array_values(array_filter(array_map('intval', awiCollectPostList('suggestion_ids', 'suggestion_ids_csv'))));
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
            } elseif ($action === 'resuggest_dismissed') {
                $id = (int)($_POST['project_id'] ?? 0);
                $target = $id > 0 ? getProject($conn, $id) : null;
                if ($target === null) {
                    throw new InvalidArgumentException(__('projects_error_name'));
                }
                // Only offered outside frozen mode, where nothing is ever
                // proposed in the first place.
                if (getProjectAssignMode($target) === 'frozen') {
                    throw new InvalidArgumentException(__('projects_add_frozen'));
                }
                $done = resuggestDismissed($conn, $id);
                $message = $done > 0
                    ? __('projects_resuggested', ['count' => $done])
                    : __('projects_resuggested_none');
                $messageType = 'success';
            } elseif ($action === 'request_suggest') {
                $id = (int)($_POST['project_id'] ?? 0);
                $target = $id > 0 ? getProject($conn, $id) : null;
                if ($target === null) {
                    throw new InvalidArgumentException(__('projects_error_name'));
                }
                if (getProjectAssignMode($target) === 'frozen') {
                    throw new InvalidArgumentException(__('projects_add_frozen'));
                }
                $queued = enqueueSuggestRequest($conn, $id, 'manual');
                $message = $queued ? __('projects_suggest_queued') : __('projects_suggest_queue_failed');
                $messageType = $queued ? 'success' : 'error';
            } elseif ($action === 'remove_links' || $action === 'disable_links' || $action === 'enable_links') {
                $id = (int)($_POST['project_id'] ?? 0);
                $keys = array_values(array_filter(array_map('strval', awiCollectPostList('link_keys', 'link_keys_csv')), fn($k) => trim($k) !== ''));
                if ($action === 'remove_links') {
                    $done = removeProjectLinks($conn, $id, $keys);
                    $message = __('projects_links_removed', ['count' => $done]);
                } else {
                    $done = setProjectLinksEnabled($conn, $id, $keys, $action === 'enable_links');
                    $message = __('projects_links_set', ['count' => $done]);
                }
                $messageType = 'success';
            } elseif ($action === 'promote_links' || $action === 'demote_links') {
                $id = (int)($_POST['project_id'] ?? 0);
                $keys = array_values(array_filter(array_map('strval', awiCollectPostList('link_keys', 'link_keys_csv')), fn($k) => trim($k) !== ''));
                $res = $action === 'promote_links'
                    ? promoteCalibLinks($conn, $id, $keys)
                    : demoteCalibLinks($conn, $id, $keys);
                if ($res['done'] === 0 && ($res['noop'] ?? 0) > 0 && $res['skipped'] === 0) {
                    $message = __('projects_move_noop');
                } else {
                    $message = __('projects_moved_bulk', ['done' => $res['done'], 'failed' => $res['skipped'] + ($res['noop'] ?? 0)]);
                    // Any move drops the session scope: say it out loud
                    // instead of leaving the user with a silently wider link.
                    if (($res['scope_cleared'] ?? 0) > 0) {
                        $message .= ' ' . __('projects_scope_cleared', ['count' => $res['scope_cleared']]);
                    }
                }
                $messageType = 'success';
            } elseif ($action === 'set_scope') {
                $id = (int)($_POST['project_id'] ?? 0);
                $keys = array_values(array_filter(array_map('strval', awiCollectPostList('link_keys', 'link_keys_csv')), fn($k) => trim($k) !== ''));
                $sids = array_values(array_filter(array_map('intval', (array)($_POST['scope_sessions'] ?? []))));
                $res = setProjectCalibScope($conn, $id, $keys, $sids);
                $message = __('projects_scope_set', ['updated' => $res['updated'], 'skipped' => $res['skipped']]);
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
            } elseif ($action === 'rename_setup') {
                $id = (int)($_POST['project_id'] ?? 0);
                $sid = (int)($_POST['setup_id'] ?? 0);
                if (!renameProjectSetup($conn, $id, $sid, (string)($_POST['name'] ?? ''))) {
                    throw new InvalidArgumentException(__('projects_error_name'));
                }
                $message = __('projects_updated');
                $messageType = 'success';
            } elseif ($action === 'save_tolerances') {
                $id = (int)($_POST['project_id'] ?? 0);
                if ($id <= 0 || getProject($conn, $id) === null) {
                    throw new InvalidArgumentException(__('projects_error_name'));
                }
                $hashBefore = getProjectSuggestConfigHash($conn, $id);
                if (isset($_POST['reset_globals'])) {
                    saveProjectTolerances($conn, $id, []);
                } else {
                    saveProjectTolerances($conn, $id, $_POST['tolerances'] ?? []);
                }
                $message = __('projects_updated');
                $messageType = 'success';
                // Only tolerances feeding the suggester re-open the matching
                // context (tol_exp/tol_temp drive diagnostics only): queue a
                // backfill pass when they really moved.
                if (getProjectSuggestConfigHash($conn, $id) !== $hashBefore) {
                    if (enqueueSuggestRequest($conn, $id, 'tolerances_changed')) {
                        $message .= ' ' . __('projects_suggest_queued');
                    }
                }
            } elseif ($action === 'save_grouping') {
                $id = (int)($_POST['project_id'] ?? 0);
                if ($id <= 0 || getProject($conn, $id) === null) {
                    throw new InvalidArgumentException(__('projects_error_name'));
                }
                if (isset($_POST['reset_grouping'])) {
                    $conn->prepare("DELETE FROM project_grouping WHERE project_id = :pid")
                        ->execute([':pid' => $id]);
                } else {
                    // A disabled merge_tiles checkbox is not submitted: when tiles
                    // cannot apply (setup split ON or panel split OFF) keep the
                    // stored value instead of silently clearing it.
                    $groupingPrev = getProjectGrouping($conn, $id);
                    $splitSetup = !empty($_POST['split_setup']);
                    $splitPanel = !empty($_POST['split_panel']);
                    saveProjectGrouping($conn, $id, [
                        'split_setup' => $splitSetup,
                        'split_panel' => $splitPanel,
                        'split_filter' => !empty($_POST['split_filter']),
                        'split_exposure' => !empty($_POST['split_exposure']),
                        'split_temp' => !empty($_POST['split_temp']),
                        'merge_tiles' => ($splitSetup || !$splitPanel) ? !empty($groupingPrev['merge_tiles']) : !empty($_POST['merge_tiles']),
                        'exp_tol' => trim((string)($_POST['exp_tol'] ?? '')),
                        'temp_tol' => trim((string)($_POST['temp_tol'] ?? '')),
                    ]);
                }
                $message = __('projects_updated');
                $messageType = 'success';
            } elseif ($action === 'save_filter_aliases') {
                $id = (int)($_POST['project_id'] ?? 0);
                if ($id <= 0 || getProject($conn, $id) === null) {
                    throw new InvalidArgumentException(__('projects_error_name'));
                }
                if (isset($_POST['reset_aliases'])) {
                    $conn->prepare("DELETE FROM project_filter_aliases WHERE project_id = :pid")
                        ->execute([':pid' => $id]);
                } else {
                    $pairs = [];
                    foreach ((array)($_POST['aliases'] ?? []) as $alias => $canon) {
                        $pairs[(string)$alias] = (string)$canon;
                    }
                    saveProjectFilterAliases($conn, $id, $pairs);
                }
                $message = __('projects_updated');
                $messageType = 'success';
            }
} catch (Throwable $e) {
    // Throwable, not Exception: in PHP 8 a TypeError is not an Exception, and it used
    // to escape as a fatal with no message at all (see the tree preview).
    //
    // The message is shown, but only when it was written for a person. A PDOException
    // carries the query and the driver text, so those are logged and the user gets a
    // generic line instead of the SQL that failed.
    $messageType = 'error';
    if ($e instanceof InvalidArgumentException) {
        $message = $e->getMessage();
    } else {
        error_log('projects action failed: ' . get_class($e) . ': ' . $e->getMessage());
        $message = __('projects_error_generic');
    }
}
        // Canvas Relaunch: successful actions triggered from the home
        // projects panel carry a `return` path -> go back home (PRG).
        //
        // A browser normalises backslashes to slashes inside a Location value, so
        // '/\evil.example' leaves as '//evil.example' while passing a plain
        // str_starts_with('//') test. Judge the normalised form, as login.php already does.
        $returnTo = str_replace('\\', '/', (string)($_POST['return'] ?? ''));
        if ($messageType === 'success' && $returnTo !== ''
            && str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//')) {
            if (strpos($returnTo, 'panel=projects') === false) {
                $returnTo .= (strpos($returnTo, '?') === false ? '?' : '&') . 'panel=projects';
            }
            header('Location: ' . $returnTo);
            exit;
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
// Detail-only page: the project list lives in the home canvas now.
if ($detail === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /?panel=projects');
    exit;
}
$globals = getGlobalTolerances($conn);
$defs = getToleranceDefs();
$detailOverrides = $detail !== null ? getProjectTolerances($detail['tolerances']) : [];
$detailMode = $detail !== null ? getProjectAssignMode($detail) : 'suggest';
$detailPending = $detail !== null ? getPendingCount($conn, (int)$detail['id']) : 0;
$detailDismissed = $detail !== null ? getDismissedCount($conn, (int)$detail['id']) : 0;
$suggestStatus = $detail !== null ? getSuggestRequestStatus($conn, (int)$detail['id']) : null;
$assignModes = getAssignModes();
$pendingTree = $detail !== null ? getProjectTree($conn, (int)$detail['id'], true) : null;
$projectTree = $pendingTree !== null ? stripPendingTree($pendingTree) : null;
$projectTols = [];
foreach ($defs as $tkey => $tdef) {
    $projectTols[$tkey] = $detail !== null ? resolve_tol($conn, (int)$detail['id'], $tkey) : $tdef['default'];
}
$tolExpRaw = trim((string)($projectTols['tol_exp'] ?? '1%'));
if ($tolExpRaw === '') {
    $tolExpRaw = '1%';
}
$filterAliases = $detail !== null ? getProjectFilterAliases($conn, (int)$detail['id']) : [];
$projectDiag = $projectTree !== null ? diagnoseProjectTree($projectTree, $projectTols, $filterAliases) : [];
$grouping = $detail !== null ? getProjectGrouping($conn, (int)$detail['id']) : defaultProjectGrouping();
$detailThresholds = $detail !== null ? getProjectThresholds($conn, (int)$detail['id']) : [];
$intGroups = $projectTree !== null
    ? getIntegrationGroups($projectTree, $tolExpRaw, $detailThresholds, (string)($projectTols['tol_temp'] ?? '2C'), $grouping, $projectTols, $filterAliases)
    : [];
// Tile diagnostics: when cross-setup merging is effective, explain the
// cross-setup panel pairs that did NOT merge (blocking criterion each).
$tilesEffective = groupingTilesEffective($grouping);
$tileHints = [];
if ($tilesEffective && $projectTree !== null) {
    $flatPanels = flatTreePanels($projectTree);
    $tileClust = clusterCrossSetupPanels($flatPanels, $projectTols);
    $tileHints = tileMergeHints($flatPanels, $projectTols, $tileClust['panelTile']);
}
if ($projectTree !== null && !empty($intGroups)) {
    $projectTree = markTreeAutoOff($projectTree, indexAutoOffLights($intGroups));
}
// Duplicate-link warnings (same file linked at several levels): ⧉×n markers.
$dupLinks = $projectTree !== null ? indexDuplicateLinks($projectTree) : [];
// Darkflat roles + flat coverage for the main tree (pending modal builds its
// own from $pendingTree inside the partial).
$darkRolesMain = $projectTree !== null ? indexDarkRoles($projectTree, $projectTols) : [];
// Shared render context for calibration rows: duplicates + grouping tolerances.
$calCtxBase = [
    'dup' => $dupLinks,
    'filterAliases' => $filterAliases,
    'darkRoles' => $darkRolesMain,
    'flatCov' => $projectTree !== null ? diagnoseFlatCoverage($projectTree, $projectTols, $darkRolesMain) : [],
    'tols' => [
        'exp' => (string)($projectTols['tol_exp'] ?? '1%'),
        'temp' => (string)($projectTols['tol_temp'] ?? '2C'),
    ],
];
// Suggestion-review modal context: the same pipeline run over the "as if
// accepted" merge, so badges, counts and exposures match the project the user
// gets by accepting. Pure in-memory (mergePendingTree), no writes, no
// transaction: suggestions already carry their final placement.
$sugMergedTree = ($pendingTree !== null && $detailPending > 0) ? mergePendingTree($pendingTree) : null;
$sugIntGroups = $sugMergedTree !== null
    ? getIntegrationGroups($sugMergedTree, $tolExpRaw, $detailThresholds, (string)($projectTols['tol_temp'] ?? '2C'), $grouping, $projectTols, $filterAliases)
    : [];
if ($sugMergedTree !== null && !empty($sugIntGroups)) {
    $sugMergedTree = markTreeAutoOff($sugMergedTree, indexAutoOffLights($sugIntGroups));
}
$sugMergedDiag = $sugMergedTree !== null ? diagnoseProjectTree($sugMergedTree, $projectTols, $filterAliases) : [];
$sugMergedDup = $sugMergedTree !== null ? indexDuplicateLinks($sugMergedTree) : [];
$sugMergedDark = $sugMergedTree !== null ? indexDarkRoles($sugMergedTree, $projectTols) : [];
$sugCalCtx = [
    'dup' => $sugMergedDup,
    'filterAliases' => $filterAliases,
    'darkRoles' => $sugMergedDark,
    'flatCov' => $sugMergedTree !== null ? diagnoseFlatCoverage($sugMergedTree, $projectTols, $sugMergedDark) : [],
    'reviewMode' => true,
    'tols' => [
        'exp' => (string)($projectTols['tol_exp'] ?? '1%'),
        'temp' => (string)($projectTols['tol_temp'] ?? '2C'),
    ],
];
$sugOpenMap = ($pendingTree !== null && $detailPending > 0)
    ? suggestReviewOpenMap($pendingTree)
    : ['setups' => [], 'panels' => [], 'sessions' => [], 'shown' => 0];
$sugPendingShown = (int)($sugOpenMap['shown'] ?? 0);
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

    <main class="flex-grow px-4 md:px-6 py-8 w-full max-w-[1600px] mx-auto">

        <?php if ($message): ?>
            <div class="mb-6 p-3 rounded text-sm <?= $messageType === 'success' ? 'bg-green-900/50 border border-green-700 text-green-300' : 'bg-red-900/50 border border-red-700 text-red-300' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

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
            <details>
                <summary class="text-lg font-semibold cursor-pointer"><?= htmlspecialchars($detail['name']) ?></summary>
            <form method="POST" id="projectUpdateForm" class="flex flex-col gap-3 mt-4">
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
            </form>
            <div class="flex justify-between gap-2 mt-3">
                <form method="POST" onsubmit="return confirm(<?= htmlspecialchars(json_encode(__('projects_confirm_delete'))) ?>);">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="project_id" value="<?= (int)$detail['id'] ?>">
                    <input type="hidden" name="return" value="/">
                    <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                        <?= __('projects_delete') ?>
                    </button>
                </form>
                <div class="flex gap-2">
                    <a href="/projects.php" class="px-4 py-2 bg-gray-600 hover:bg-gray-500 text-white rounded-lg transition-colors"><?= __('back') ?></a>
                    <button type="submit" form="projectUpdateForm" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                        <?= __('projects_save') ?>
                    </button>
                </div>
            </div>
            </details>
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

        <section class="mb-6 bg-gray-800 rounded-lg p-6">
            <details>
                <summary class="text-lg font-semibold cursor-pointer"><?= __('projects_filter_aliases') ?></summary>
                <p class="text-sm text-gray-400 my-4"><?= htmlspecialchars(__('projects_filter_aliases_intro')) ?></p>
            <?php
            $aliasTree = $pendingTree ?? $projectTree;
            $aliasFilters = [];
            if ($aliasTree !== null) {
                $aliasCollect = function (array $rows, bool $isLight) use (&$aliasFilters): void {
                    foreach ($rows as $r) {
                        $raw = trim((string)($isLight ? ($r['filter_name'] ?? $r['filter'] ?? '') : ($r['filter'] ?? '')));
                        if ($raw === '') {
                            continue;
                        }
                        $aliasFilters[$raw] = ($aliasFilters[$raw] ?? 0) + 1;
                    }
                };
                foreach ($aliasTree['setups'] ?? [] as $asSetup) {
                    $aliasCollect($asSetup['calibrations'] ?? [], false);
                    foreach ($asSetup['panels'] ?? [] as $asPanel) {
                        $aliasCollect($asPanel['calibrations'] ?? [], false);
                        foreach ($asPanel['sessions'] ?? [] as $asSession) {
                            $aliasCollect($asSession['calibrations'] ?? [], false);
                            foreach ($asSession['filters'] ?? [] as $asFilter) {
                                $aliasCollect($asFilter['lights'] ?? [], true);
                                $aliasCollect($asFilter['calibrations'] ?? [], false);
                            }
                        }
                    }
                }
                uksort($aliasFilters, 'strcasecmp');
            }
            ?>
            <?php if (empty($aliasFilters)): ?>
                <p class="text-sm text-gray-500"><?= __('projects_filter_aliases_empty') ?></p>
            <?php else: ?>
            <form method="POST" class="flex flex-col gap-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save_filter_aliases">
                <input type="hidden" name="project_id" value="<?= (int)$detail['id'] ?>">
                <datalist id="aliasNames">
                    <?php foreach (array_keys($aliasFilters) as $asName): ?>
                    <option value="<?= htmlspecialchars($asName) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <?php foreach ($aliasFilters as $asRaw => $asCount): ?>
                    <?php $asTarget = $filterAliases[mb_strtolower($asRaw)] ?? ''; ?>
                    <label class="flex items-center justify-between gap-4 text-sm">
                        <span class="text-gray-300"><?= htmlspecialchars($asRaw) ?>
                            <span class="text-gray-500">(<?= (int)$asCount ?>)</span>
                            <?php if ($asTarget !== ''): ?>
                            <span class="text-teal-400">→ <?= htmlspecialchars($asTarget) ?></span>
                            <?php endif; ?>
                        </span>
                        <input type="text" name="aliases[<?= htmlspecialchars($asRaw) ?>]" maxlength="50" list="aliasNames"
                               value="<?= htmlspecialchars($asTarget) ?>"
                               placeholder="<?= htmlspecialchars(__('projects_filter_aliases_canonical')) ?>"
                               class="w-40 px-3 py-2 bg-gray-700 border border-gray-600 rounded text-gray-100">
                    </label>
                <?php endforeach; ?>
                <div class="flex justify-end gap-2 mt-2">
                    <button type="submit" name="reset_aliases" value="1"
                            class="px-4 py-2 bg-gray-600 hover:bg-gray-500 text-white rounded-lg transition-colors">
                        <?= __('projects_reset') ?>
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                        <?= __('projects_save') ?>
                    </button>
                </div>
            </form>
            <?php endif; ?>
            </details>
        </section>

        <div id="sugModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/60">
            <div class="bg-gray-800 rounded-lg p-6 w-full max-w-6xl max-h-[85vh] overflow-y-auto">
                <div class="flex items-center justify-between mb-2">
                    <h2 class="text-lg font-semibold"><?= __('projects_review', ['count' => $detailPending]) ?></h2>
                    <button type="button" id="sugModalClose" class="text-gray-400 hover:text-white text-xl leading-none">&times;</button>
                </div>
                <p class="text-sm text-gray-400 mb-4"><?= __('projects_review_intro') ?></p>
                <?php if ($detailMode !== 'frozen'): ?>
                <form method="POST" class="mb-4 flex items-center flex-wrap gap-2">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="project_id" value="<?= (int)$detail['id'] ?>">
                    <button type="submit" name="action" value="request_suggest"
                            class="px-3 py-1.5 bg-blue-600 hover:bg-blue-500 text-white rounded text-sm transition-colors">
                        <?= __('projects_suggest_refresh') ?>
                    </button>
                    <span class="text-xs text-gray-500"><?= __('projects_suggest_refresh_hint') ?></span>
                    <?php if ($suggestStatus !== null): ?>
                    <span class="text-xs text-gray-500">· <?= htmlspecialchars(suggestStatusLine($suggestStatus)) ?></span>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
                <?php if ($detailDismissed > 0): ?>
                <form method="POST" class="mb-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="project_id" value="<?= (int)$detail['id'] ?>">
                    <button type="submit" name="action" value="resuggest_dismissed"
                            class="px-3 py-1.5 bg-gray-600 hover:bg-gray-500 text-white rounded text-sm transition-colors">
                        <?= __('projects_resuggest', ['count' => $detailDismissed]) ?>
                    </button>
                    <span class="text-xs text-gray-500 ml-2"><?= __('projects_resuggest_hint') ?></span>
                </form>
                <?php endif; ?>
            <?php if ($detailPending === 0 || $sugPendingShown === 0): ?>
                <p class="text-gray-500 text-sm"><?= __('projects_no_pending') ?></p>
            <?php else: ?>
            <?php if ($sugPendingShown < $detailPending): ?>
                <p class="mb-3 p-2 rounded text-xs bg-yellow-900/50 border border-yellow-700 text-yellow-300"><?= htmlspecialchars(__('projects_review_truncated', ['shown' => $sugPendingShown, 'count' => $detailPending])) ?></p>
            <?php endif; ?>
            <form method="POST" id="sugBulkForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="project_id" value="<?= (int)$detail['id'] ?>">
                <input type="hidden" name="suggestion_ids_csv" id="sugIdsCsv" value="">
                <div class="mb-3 text-sm">
                    <label class="text-gray-300 cursor-pointer"><input type="checkbox" onclick="document.querySelectorAll('#sugModal .sug-check').forEach(c => { c.checked = this.checked; c.dispatchEvent(new Event('change', { bubbles: true })); })" class="rounded bg-gray-600 border-gray-500"> <?= __('projects_select_all') ?></label>
                </div>
                <?php
                // Full tree as if accepted (step-3 look): swap in the merged
                // diagnostics for the render, then restore the main-tree ones
                // used by the section below.
                $__saveDiag = $projectDiag;
                $__saveDup = $dupLinks;
                $__saveCtx = $calCtxBase;
                $__saveTree = $projectTree;
                $projectDiag = $sugMergedDiag;
                $dupLinks = $sugMergedDup;
                $calCtxBase = $sugCalCtx;
                $projectTree = $pendingTree;
                $suggestReviewMode = true;
                $suggestOpen = $sugOpenMap;
                include __DIR__ . '/includes/projects_tree.php';
                $projectDiag = $__saveDiag;
                $dupLinks = $__saveDup;
                $calCtxBase = $__saveCtx;
                $projectTree = $__saveTree;
                unset($suggestReviewMode, $suggestOpen, $__saveDiag, $__saveDup, $__saveCtx, $__saveTree);
                ?>
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
        // Pack the checked suggestions into a single hidden field. One input
        // var per file trips PHP's max_input_vars past ~1000 selections; a
        // single CSV field has no such ceiling. The individual boxes are
        // disabled at submit so they are not sent alongside the CSV.
        (function () {
            const form = document.getElementById('sugBulkForm');
            if (!form) return;
            form.addEventListener('submit', () => {
                const checked = Array.from(form.querySelectorAll('.sug-check:checked'))
                    .map(cb => cb.value).filter(Boolean);
                const csv = form.querySelector('#sugIdsCsv');
                if (csv) csv.value = checked.join(',');
                form.querySelectorAll('.sug-check').forEach(cb => { cb.disabled = true; });
            });
        })();
        // Master group checkboxes for the review tree (same pattern as the
        // main treeBulkForm, but driving .sug-check only: real rows carry no
        // checkbox here, so a group box selects just the new files below it).
        (function () {
            const form = document.getElementById('sugBulkForm');
            if (!form) return;
            function syncGroupBox(scope) {
                const box = scope.querySelector(':scope > div > .pgroup-check');
                if (!box) return;
                const files = scope.querySelectorAll('.sug-check');
                if (!files.length) {
                    box.checked = false;
                    box.indeterminate = false;
                    return;
                }
                const checked = scope.querySelectorAll('.sug-check:checked').length;
                box.checked = checked === files.length;
                box.indeterminate = checked > 0 && checked < files.length;
            }
            function syncCalGroupBox(group) {
                const box = group.parentElement?.querySelector(':scope > .cgroup-check');
                if (!box) return;
                const files = group.querySelectorAll('.sug-check');
                if (!files.length) {
                    box.checked = false;
                    box.indeterminate = false;
                    return;
                }
                const checked = group.querySelectorAll('.sug-check:checked').length;
                box.checked = checked === files.length;
                box.indeterminate = checked > 0 && checked < files.length;
            }
            function syncAbove(scope) {
                let above = scope.parentElement?.closest('.tnode') ?? null;
                while (above) {
                    syncGroupBox(above);
                    above = above.parentElement?.closest('.tnode') ?? null;
                }
            }
            form.addEventListener('change', (e) => {
                if (e.target.classList.contains('pgroup-check')) {
                    const scope = e.target.closest('.tnode');
                    if (!scope) return;
                    if (!scope.querySelectorAll('.sug-check').length) {
                        e.target.checked = false;
                        e.target.indeterminate = false;
                        return;
                    }
                    scope.querySelectorAll('.sug-check').forEach(cb => { cb.checked = e.target.checked; });
                    scope.querySelectorAll('.pgroup-check').forEach(cb => {
                        if (cb !== e.target) {
                            cb.checked = e.target.checked;
                            cb.indeterminate = false;
                        }
                    });
                    e.target.indeterminate = false;
                    scope.querySelectorAll('details.cal-group').forEach(syncCalGroupBox);
                    syncAbove(scope);
                    return;
                }
                if (e.target.classList.contains('cgroup-check')) {
                    const wrap = e.target.closest('.cal-group-wrap');
                    if (!wrap) return;
                    if (!wrap.querySelectorAll('.sug-check').length) {
                        e.target.checked = false;
                        e.target.indeterminate = false;
                        return;
                    }
                    const details = wrap.querySelector('details.cal-group');
                    if (e.target.checked && details) details.open = true;
                    wrap.querySelectorAll('.sug-check').forEach(cb => { cb.checked = e.target.checked; });
                    e.target.indeterminate = false;
                    syncAbove(wrap);
                    return;
                }
                if (e.target.classList.contains('sug-check')) {
                    const group = e.target.closest('details.cal-group');
                    if (group) syncCalGroupBox(group);
                    let scope = e.target.closest('.tnode');
                    while (scope) {
                        syncGroupBox(scope);
                        scope = scope.parentElement?.closest('.tnode') ?? null;
                    }
                }
            });
            // Shift-click range select on suggestion checkboxes.
            let lastSugCheck = null;
            form.addEventListener('click', (e) => {
                if (!e.target.classList || !e.target.classList.contains('sug-check')) return;
                const all = Array.from(form.querySelectorAll('.sug-check'));
                if (e.shiftKey && lastSugCheck && all.includes(lastSugCheck)) {
                    const a = all.indexOf(lastSugCheck);
                    const b = all.indexOf(e.target);
                    const lo = Math.min(a, b);
                    const hi = Math.max(a, b);
                    for (let i = lo; i <= hi; i++) {
                        all[i].checked = e.target.checked;
                        all[i].dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
                lastSugCheck = e.target;
            });
        })();
        </script>

        <section class="bg-gray-800 rounded-lg p-6 mb-6">
            <details open>
            <summary class="text-lg font-semibold cursor-pointer mb-2"><?= __('projects_tree') ?> <span class="ml-2 inline-block px-2 py-0.5 text-xs font-medium rounded bg-blue-900/50 border border-blue-700 text-blue-300 align-middle"><?= htmlspecialchars(__('projects_pre_badge')) ?></span></summary>
            <form method="POST" id="treeBulkForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="project_id" value="<?= (int)$detail['id'] ?>">
                <input type="hidden" name="link_keys_csv" id="treeKeysCsv" value="">
                <div class="flex flex-col items-end gap-2 mb-4">
                    <div class="flex flex-wrap justify-end gap-2">
                    <?php $projectExportIds = $projectTree !== null ? getProjectTreeFileIds($projectTree) : []; ?>
                    <?php if (!empty($projectExportIds)): ?>
                    <button type="button" id="projectAstroBinBtn" data-ids="<?= htmlspecialchars(implode(',', $projectExportIds)) ?>"
                            class="px-3 py-1 text-sm bg-sky-600 hover:bg-sky-700 text-white rounded transition-colors">
                        <?= __('export_astrobin_csv') ?>
                    </button>
                    <?php if (canDownload()): ?>
                    <button type="button" id="projectZipBtn" data-project-id="<?= (int)$detail['id'] ?>"
                            class="px-3 py-1 text-sm bg-teal-600 hover:bg-teal-700 text-white rounded transition-colors">
                        <?= __('projects_export_zip') ?>
                    </button>
                    <?php endif; ?>
                    <?php endif; ?>
                    <button type="submit" name="action" value="enable_links"
                            class="px-3 py-1 text-sm bg-green-700 hover:bg-green-600 text-white rounded transition-colors disabled:opacity-50">
                        <?= __('projects_enable_selected') ?>
                    </button>
                    <button type="submit" name="action" value="disable_links"
                            class="px-3 py-1 text-sm bg-yellow-700 hover:bg-yellow-600 text-white rounded transition-colors disabled:opacity-50">
                        <?= __('projects_disable_selected') ?>
                    </button>
                    <button type="submit" name="action" value="remove_links"
                            onclick="return confirm(<?= htmlspecialchars(json_encode(__('projects_confirm_remove'))) ?>);"
                            class="px-3 py-1 text-sm bg-red-700 hover:bg-red-600 text-white rounded transition-colors disabled:opacity-50">
                        <?= __('projects_remove_selected') ?>
                    </button>
                    </div>
                    <div class="flex flex-wrap justify-end gap-2">
                    <button type="submit" name="action" value="promote_links"
                            class="px-3 py-1 text-sm bg-blue-700 hover:bg-blue-600 text-white rounded transition-colors disabled:opacity-50">
                        ↑ <?= __('projects_promote') ?>
                    </button>
                    <button type="submit" name="action" value="demote_links"
                            class="px-3 py-1 text-sm bg-orange-700 hover:bg-orange-600 text-white rounded transition-colors disabled:opacity-50">
                        ↓ <?= __('projects_demote') ?>
                    </button>
                    <button type="button" id="scopeModalOpen"
                            class="px-3 py-1 text-sm bg-purple-700 hover:bg-purple-600 text-white rounded transition-colors disabled:opacity-50">
                        <?= __('projects_scope_button') ?>
                    </button>
                    </div>
                </div>
                <div id="scopeModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/60">
                    <div class="bg-gray-800 rounded-lg p-6 w-full max-w-md max-h-[85vh] overflow-y-auto">
                        <div class="flex items-center justify-between mb-2">
                            <h2 class="text-lg font-semibold"><?= __('projects_scope_modal') ?></h2>
                            <button type="button" id="scopeModalClose" class="text-gray-400 hover:text-white text-xl leading-none">&times;</button>
                        </div>
                        <p class="text-sm text-gray-400 mb-3"><?= __('projects_scope_hint') ?></p>
                        <div class="mb-3 text-sm flex gap-3">
                            <button type="button" id="scopeCheckAll" class="text-blue-400 hover:text-blue-300"><?= __('projects_select_all') ?></button>
                            <button type="button" id="scopeCheckNone" class="text-blue-400 hover:text-blue-300"><?= __('projects_scope_all') ?></button>
                        </div>
                        <div class="flex flex-col gap-3 mb-4" id="scopeSessionList">
                            <?php foreach (($projectTree['setups'] ?? []) as $scSetup): ?>
                                <?php
                                $scHasSessions = false;
                                foreach (($scSetup['panels'] ?? []) as $__sp) {
                                    if (!empty($__sp['sessions'])) {
                                        $scHasSessions = true;
                                        break;
                                    }
                                }
                                unset($__sp);
                                ?>
                                <?php if (!$scHasSessions) continue; ?>
                                <div class="scope-setup-group" data-setup="<?= (int)$scSetup['id'] ?>">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1">
                                        S<?= (int)($scSetup['setup_no'] ?? $scSetup['id']) ?>
                                    </div>
                                    <?php foreach (($scSetup['panels'] ?? []) as $scPanel): ?>
                                        <?php if (empty($scPanel['sessions'])) continue; ?>
                                        <div class="text-xs text-gray-500 mt-1">P<?= (int)($scPanel['panel_no'] ?? $scPanel['id']) ?></div>
                                        <?php foreach ($scPanel['sessions'] as $scSession): ?>
                                            <label class="flex items-center gap-2 py-1 text-sm text-gray-200 cursor-pointer">
                                                <input type="checkbox" name="scope_sessions[]" value="<?= (int)$scSession['id'] ?>" data-setup="<?= (int)$scSetup['id'] ?>"
                                                       class="scope-session-check rounded bg-gray-600 border-gray-500">
                                                <?= htmlspecialchars(sessionShortLabel($scSession)) ?>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="flex justify-end gap-2">
                            <button type="button" id="scopeModalCancel" class="px-4 py-2 bg-gray-600 hover:bg-gray-500 text-white rounded-lg transition-colors"><?= __('projects_cancel') ?></button>
                            <button type="submit" name="action" value="set_scope" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition-colors disabled:opacity-50">
                                <?= __('projects_save') ?>
                            </button>
                        </div>
                    </div>
                </div>
                <?php include __DIR__ . '/includes/projects_tree.php'; ?>
            </form>
            <script>
            (function () {
                const bulk = document.getElementById('treeBulkForm');
                if (!bulk) return;
                // Group header checkbox selects every file in its calibration group.
                // It lives outside the <details> (same pattern as pgroup-check),
                // so the native behavior just works. No synthetic events here:
                // ancestor boxes are synced explicitly, so a failing listener
                // can never abort the loop mid-way (only the first row selected).
                document.querySelectorAll('#treeBulkForm .cgroup-check').forEach(box => {
                    box.addEventListener('change', () => {
                        const wrap = box.closest('.cal-group-wrap');
                        if (!wrap) return;
                        const details = wrap.querySelector('details.cal-group');
                        if (box.checked && details) details.open = true;
                        const boxes = Array.from(wrap.querySelectorAll('.pfl-check'));
                        boxes.forEach(cb => { cb.checked = box.checked; });
                        box.indeterminate = false;
                        let scope = wrap.closest('.tnode');
                        while (scope) {
                            const gbox = scope.querySelector(':scope > div > .pgroup-check');
                            if (gbox) {
                                const files = scope.querySelectorAll('.pfl-check');
                                const n = scope.querySelectorAll('.pfl-check:checked').length;
                                gbox.checked = files.length > 0 && n === files.length;
                                gbox.indeterminate = n > 0 && n < files.length;
                            }
                            scope = scope.parentElement?.closest('.tnode') ?? null;
                        }
                    });
                });
            })();
            // Shift-click range select on tree file checkboxes (same pattern as
            // the main table). Programmatic sets skip 'change', so every group
            // box is re-synced explicitly afterwards.
            (function () {
                const form = document.getElementById('treeBulkForm');
                if (!form) return;
                let lastTreeCheck = null;
                form.addEventListener('click', (e) => {
                    if (!e.target.classList || !e.target.classList.contains('pfl-check')) return;
                    const all = Array.from(form.querySelectorAll('.pfl-check'));
                    if (e.shiftKey && lastTreeCheck && all.includes(lastTreeCheck)) {
                        const a = all.indexOf(lastTreeCheck);
                        const b = all.indexOf(e.target);
                        const lo = Math.min(a, b);
                        const hi = Math.max(a, b);
                        for (let i = lo; i <= hi; i++) {
                            all[i].checked = e.target.checked;
                            all[i].dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }
                    lastTreeCheck = e.target;
                });
                // Same max_input_vars story as the suggestions modal: pack the
                // checked links into one CSV field and drop the individual
                // boxes from the submission. scope_sessions[] stays as-is
                // (a handful of sessions at most).
                form.addEventListener('submit', () => {
                    const checked = Array.from(form.querySelectorAll('.pfl-check:checked'))
                        .map(cb => cb.value).filter(Boolean);
                    const csv = form.querySelector('#treeKeysCsv');
                    if (csv) csv.value = checked.join(',');
                    form.querySelectorAll('.pfl-check').forEach(cb => { cb.disabled = true; });
                });
            })();
            // Bulk calibration scope modal: session checkboxes are submitted
            // with the bulk form (link_keys[] come from the tree selection).
            // On open, the common scope of the selection is pre-checked.
            (function () {
                const modal = document.getElementById('scopeModal');
                const openBtn = document.getElementById('scopeModalOpen');
                const closeBtn = document.getElementById('scopeModalClose');
                const cancelBtn = document.getElementById('scopeModalCancel');
                if (!modal || !openBtn) return;
                function open() {
                    const checked = Array.from(
                        document.querySelectorAll('#treeBulkForm .pfl-check:checked')
                    );
                    // Cross-setup associations are forbidden: scope applies
                    // within one setup only (server re-validates per link).
                    const setups = new Set();
                    checked.forEach(cb => { if (cb.dataset.setup) setups.add(cb.dataset.setup); });
                    if (setups.size === 0) {
                        alert(window.i18n?.projects_scope_no_cal || 'Select at least one setup/panel calibration.');
                        return;
                    }
                    if (setups.size > 1) {
                        alert(window.i18n?.projects_scope_single_setup || 'Select calibrations from a single setup.');
                        return;
                    }
                    const onlySetup = Array.from(setups)[0];
                    modal.querySelectorAll('.scope-setup-group').forEach(gr => {
                        gr.style.display = gr.dataset.setup === onlySetup ? '' : 'none';
                    });
                    modal.querySelectorAll('.scope-session-check').forEach(box => {
                        const visible = box.closest('.scope-setup-group')?.style.display !== 'none';
                        if (!visible) box.checked = false;
                    });
                    const scoped = checked.filter(cb => cb.dataset.scope);
                    const boxes = Array.from(modal.querySelectorAll('.scope-session-check'));
                    if (scoped.length > 0) {
                        let common = scoped[0].dataset.scope.split(',').filter(Boolean);
                        scoped.slice(1).forEach(cb => {
                            const s = new Set(cb.dataset.scope.split(',').filter(Boolean));
                            common = common.filter(id => s.has(id));
                        });
                        boxes.forEach(box => { box.checked = common.includes(box.value); });
                    } else {
                        boxes.forEach(box => { box.checked = false; });
                    }
                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                }
                function close() {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                }
                openBtn.addEventListener('click', open);
                if (closeBtn) closeBtn.addEventListener('click', close);
                if (cancelBtn) cancelBtn.addEventListener('click', close);
                modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
                document.getElementById('scopeCheckAll')?.addEventListener('click', () => {
                    modal.querySelectorAll('.scope-session-check').forEach(box => { box.checked = true; });
                });
                document.getElementById('scopeCheckNone')?.addEventListener('click', () => {
                    modal.querySelectorAll('.scope-session-check').forEach(box => { box.checked = false; });
                });
            })();
            </script>
            <div class="text-xs text-gray-400 mt-4 flex flex-wrap items-center gap-x-4 gap-y-1">
                <span class="font-semibold"><?= __('projects_legend') ?>:</span>
                <span class="inline-flex gap-1 items-center"><?= diagBox('green', 'B', __('projects_cal_bias')) ?><?= diagBox('green', 'D', __('projects_cal_dark')) ?><?= diagBox('green', 'F', __('projects_cal_flat')) ?> <span><?= htmlspecialchars(__('projects_legend_calib')) ?></span></span>
                <span class="inline-flex gap-1 items-center"><?= rotBadge('ok', __('projects_cal_rot_ok'), __('projects_cal_rot_warn')) ?><?= rotBadge('warn', __('projects_cal_rot_ok'), __('projects_cal_rot_warn')) ?> <span><?= htmlspecialchars(__('projects_legend_rot')) ?></span></span>
                <span class="inline-flex gap-1 items-center"><span class="font-mono">[DARKFLAT]</span> <span><?= htmlspecialchars(__('projects_legend_darkflat')) ?></span></span>
                <span class="inline-flex gap-1 items-center"><?= diagBox('green', 'B', __('projects_cal_bias')) ?><?= diagBox('grey', 'D', __('projects_cal_not_needed')) ?> <span><?= htmlspecialchars(__('projects_legend_flatcov')) ?></span></span>
            </div>
            </details>
            <div id="renameModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/60">
                <div class="bg-gray-800 rounded-lg p-6 w-full max-w-md">
                    <h3 class="text-lg font-semibold mb-4"><?= __('projects_setup_rename') ?></h3>
                    <input type="text" id="renameInput" maxlength="255"
                           placeholder="<?= __('projects_setup_rename_prompt') ?>"
                           class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded text-gray-100">
                    <div class="flex justify-end gap-2 mt-4">
                        <button type="button" id="renameCancel" class="px-4 py-2 bg-gray-600 hover:bg-gray-500 text-white rounded-lg transition-colors"><?= __('projects_cancel') ?></button>
                        <button type="button" id="renameSave" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors"><?= __('projects_save') ?></button>
                    </div>
                </div>
            </div>
        </section>

        <div id="zipModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/60">
            <div class="bg-gray-800 rounded-lg p-6 w-full max-w-6xl max-h-[85vh] overflow-y-auto">
                <div class="flex items-center justify-between mb-2">
                    <h2 class="text-lg font-semibold"><?= __('projects_export_zip') ?></h2>
                    <button type="button" id="zipModalClose" class="text-gray-400 hover:text-white text-xl leading-none">&times;</button>
                </div>
                <div id="zipPreviewBody" class="text-sm text-gray-300"></div>
                <form id="zipDownloadForm" method="POST" action="api/export_project_zip.php" class="hidden">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="project_id" value="<?= (int)$detail['id'] ?>">
                </form>
                <div class="flex justify-end gap-2 mt-4">
                    <button type="button" id="zipModalCancel" class="px-4 py-2 bg-gray-600 hover:bg-gray-500 text-white rounded-lg transition-colors"><?= __('projects_cancel') ?></button>
                    <button type="button" id="zipDownloadBtn" class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg transition-colors disabled:opacity-50" disabled>
                        <?= __('projects_export_zip') ?>
                    </button>
                </div>
            </div>
        </div>
        <script>
        // Project ZIP export preview: same shared builder as the download, so
        // the preview can never diverge from the archive.
        (function () {
            const modal = document.getElementById('zipModal');
            const openBtn = document.getElementById('projectZipBtn');
            const closeBtn = document.getElementById('zipModalClose');
            const cancelBtn = document.getElementById('zipModalCancel');
            const body = document.getElementById('zipPreviewBody');
            const dlBtn = document.getElementById('zipDownloadBtn');
            const dlForm = document.getElementById('zipDownloadForm');
            // openBtn is absent when the user lacks can_download (see the server-side
            // render guard), so this also disables the whole ZIP preview.
            if (!modal || !openBtn || !body) return;
            const esc = (s) => String(s ?? '').replace(/[&<>'"]/g,
                c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[c]));
            function renderTree(entries) {
                const root = {};
                entries.forEach(e => {
                    const parts = String(e.zip_path || '').split('/');
                    let node = root;
                    parts.forEach((p, i) => {
                        const last = i === parts.length - 1;
                        node.children = node.children || {};
                        if (last) {
                            node.files = node.files || [];
                            node.files.push(e);
                        } else {
                            node.children[p] = node.children[p] || {};
                            node = node.children[p];
                        }
                    });
                });
                const countFiles = (n) => (n.files || []).length
                    + Object.values(n.children || {}).reduce((a, c) => a + countFiles(c), 0);
                const render = (n) => {
                    let html = '';
                    Object.keys(n.children || {}).sort().forEach(dir => {
                        const c = n.children[dir];
                        const count = countFiles(c);
                        html += `<details open class="mb-1 ml-4"><summary class="cursor-pointer px-2 py-1 hover:bg-gray-700/40 rounded text-sm text-gray-200">📁 ${esc(dir)} <span class="text-xs text-gray-500">${count}</span></summary><div class="ml-2">${render(c)}</div></details>`;
                    });
                    (n.files || []).forEach(f => {
                        const badges = [];
                        if (f.master) badges.push('M');
                        if (f.scope && f.scope.length) badges.push('◈×' + f.scope.length);
                        html += `<div class="text-xs text-gray-400 py-0.5 ml-4 truncate" title="${esc(f.zip_path)}">${esc(f.zip_path.split('/').pop())}${badges.length ? ' <span class="text-gray-500">(' + badges.join(' ') + ')</span>' : ''}</div>`;
                    });
                    return html;
                };
                return { html: render(root), total: countFiles(root) };
            }
            function open() {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                if (dlBtn) dlBtn.disabled = true;
                body.innerHTML = `<p class="text-gray-500">${esc(window.i18n?.loading || 'Loading...')}</p>`;
                fetch('api/project_export_preview.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ project_id: parseInt(openBtn.dataset.projectId || '0', 10) }),
                })
                    .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                    .then(({ ok, data }) => {
                        if (!ok || !data || data.success !== true) {
                            throw new Error((data && data.error) || 'Request failed');
                        }
                        const t = renderTree(data.entries || []);
                        const fmtSize = (bytes) => {
                            let n = Math.max(0, Number(bytes) || 0);
                            const units = ['B', 'KB', 'MB', 'GB', 'TB'];
                            let u = 0;
                            while (n >= 1024 && u < units.length - 1) {
                                n /= 1024;
                                u++;
                            }
                            return (u === 0 ? n : n.toFixed(n >= 100 ? 0 : 1)) + ' ' + units[u];
                        };
                        let html = `<p class="text-gray-200 mb-2">${t.total} file · ${fmtSize(data.total_size)} ${esc(window.i18n?.projects_export_uncompressed || 'uncompressed')}</p>${t.html}`;
                        if (data.sets && Object.keys(data.sets).length) {
                            html += `<div class="mt-3 text-xs text-gray-400">`
                                + Object.entries(data.sets).map(([name, s]) =>
                                    `<div>🔑 <span class="font-mono">${esc(name)}</span> → ${esc((s.nights || []).join(', '))}</div>`).join('')
                                + `</div>`;
                        }
                        if (data.tiles && Object.keys(data.tiles).length) {
                            html += `<div class="mt-3 text-xs text-gray-400">`
                                + Object.entries(data.tiles).map(([name, t]) =>
                                    `<div>🧩 <span class="font-mono">${esc(name)}</span> → ${esc((t.panels || []).join(', '))}</div>`).join('')
                                + `</div>`;
                        }
                        // The endpoint caps the list and sends skipped_total: show the bounded sample
                        // plus the real count, so a project with thousands of skipped
                        // subframes does not ship a huge HTML blob into the modal.
                        const skipped = data.skipped || [];
                        const skippedTotal = data.skipped_total ?? skipped.length;
                        if (skippedTotal > 0) {
                            html += `<details class="mt-3"><summary class="cursor-pointer text-xs text-gray-400">`
                                + `${esc(window.i18n?.projects_export_skipped || 'Skipped')} (${skippedTotal})</summary><ul class="text-xs text-gray-500">`
                                + skipped.map(s => `<li>${esc(s.name)} — ${esc(s.reason)}</li>`).join('')
                                + (skippedTotal > skipped.length
                                    ? `<li class="italic">… +${skippedTotal - skipped.length}</li>` : '')
                                + `</ul></details>`;
                        }
                        // Files requested by more than one folder are copied
                        // into each of them, so the ZIP carries N copies.
                        const duplicated = data.duplicated_files || [];
                        if (duplicated.length) {
                            html += `<details class="mt-3" open><summary class="cursor-pointer text-xs text-gray-400">`
                                + `${esc(window.i18n?.projects_export_duplicated || 'Duplicated')} (${duplicated.length})</summary><ul class="text-xs text-gray-500">`
                                + duplicated.map(d => `<li>${esc(d.name)} — ${d.paths.map(esc).join(', ')}</li>`).join('')
                                + `</ul></details>`;
                        }
                        body.innerHTML = html;
                        if (dlBtn) dlBtn.disabled = t.total === 0;
                    })
                    .catch(err => {
                        body.innerHTML = `<p class="text-red-400">Error: ${esc(err.message)}</p>`;
                    });
            }
            function close() {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }
            openBtn.addEventListener('click', open);
            if (closeBtn) closeBtn.addEventListener('click', close);
            if (cancelBtn) cancelBtn.addEventListener('click', close);
            modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
            if (dlBtn && dlForm) dlBtn.addEventListener('click', () => dlForm.submit());
        })();
        </script>

        <section class="bg-gray-800 rounded-lg p-6">
            <details open>
            <summary class="text-lg font-semibold cursor-pointer mb-2"><?= __('projects_igroups') ?> <span class="ml-2 inline-block px-2 py-0.5 text-xs font-medium rounded bg-teal-900/50 border border-teal-700 text-teal-300 align-middle"><?= htmlspecialchars(__('projects_post_badge')) ?></span></summary>
            <details class="mb-4 border border-gray-700 rounded-lg">
                <summary class="cursor-pointer px-4 py-2 hover:bg-gray-700/40 rounded text-sm font-medium"><?= htmlspecialchars(__('projects_grouping')) ?></summary>
                <form method="POST" class="px-4 py-3 flex flex-col gap-2">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="save_grouping">
                    <input type="hidden" name="project_id" value="<?= (int)$detail['id'] ?>">
                    <p class="text-xs text-gray-400"><?= htmlspecialchars(__('projects_grouping_intro')) ?></p>
                    <div id="groupingRows" class="flex flex-col gap-1.5 text-sm mt-1">
                        <label class="flex items-center gap-2 cursor-pointer w-fit">
                            <input type="checkbox" name="split_setup" value="1" <?= !empty($grouping['split_setup']) ? 'checked' : '' ?> class="rounded bg-gray-600 border-gray-500">
                            <?= htmlspecialchars(__('projects_grouping_setup')) ?>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer w-fit">
                            <input type="checkbox" name="split_panel" value="1" <?= !empty($grouping['split_panel']) ? 'checked' : '' ?> class="rounded bg-gray-600 border-gray-500">
                            <?= htmlspecialchars(__('projects_grouping_panel')) ?>
                        </label>
                        <div class="ml-6 flex flex-col gap-0.5">
                            <label class="flex items-center gap-2 cursor-pointer w-fit" id="mergeTilesLabel">
                                <input type="checkbox" name="merge_tiles" value="1" <?= !empty($grouping['merge_tiles']) ? 'checked' : '' ?> class="rounded bg-gray-600 border-gray-500">
                                <?= htmlspecialchars(__('projects_grouping_tiles')) ?>
                            </label>
                            <p class="text-xs text-gray-500"><?= htmlspecialchars(__('projects_grouping_tiles_hint')) ?></p>
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer w-fit">
                            <input type="checkbox" name="split_filter" value="1" <?= !empty($grouping['split_filter']) ? 'checked' : '' ?> class="rounded bg-gray-600 border-gray-500">
                            <?= htmlspecialchars(__('projects_grouping_filter')) ?>
                        </label>
                        <div class="flex items-center gap-2 flex-wrap">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="split_exposure" value="1" <?= !empty($grouping['split_exposure']) ? 'checked' : '' ?> class="rounded bg-gray-600 border-gray-500">
                                <?= htmlspecialchars(__('projects_grouping_exposure')) ?>
                            </label>
                            <label class="flex items-center gap-2 text-gray-400 text-sm"><?= htmlspecialchars(__('projects_grouping_exp_tol')) ?>
                                <input type="text" name="exp_tol" maxlength="64" value="<?= htmlspecialchars((string)($grouping['exp_tol'] ?? '')) ?>"
                                       placeholder="<?= htmlspecialchars(__('projects_inherit_default', ['value' => $tolExpRaw])) ?>"
                                       class="w-28 px-2 py-1 bg-gray-700 border border-gray-600 rounded text-gray-100">
                            </label>
                        </div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="split_temp" value="1" <?= !empty($grouping['split_temp']) ? 'checked' : '' ?> class="rounded bg-gray-600 border-gray-500">
                                <?= htmlspecialchars(__('projects_grouping_temp')) ?>
                            </label>
                            <label class="flex items-center gap-2 text-gray-400 text-sm"><?= htmlspecialchars(__('projects_grouping_temp_tol')) ?>
                                <input type="text" name="temp_tol" maxlength="64" value="<?= htmlspecialchars((string)($grouping['temp_tol'] ?? '')) ?>"
                                       placeholder="<?= htmlspecialchars(__('projects_inherit_default', ['value' => (string)($projectTols['tol_temp'] ?? '2C')])) ?>"
                                       class="w-28 px-2 py-1 bg-gray-700 border border-gray-600 rounded text-gray-100">
                            </label>
                        </div>
                    </div>
                    <script>
                    (function () {
                        // Tiles only make sense with setup split OFF and panel
                        // split ON: keep the checkbox in sync so the dependency
                        // is visible.
                        const rows = document.getElementById('groupingRows');
                        if (!rows) return;
                        const setupBox = rows.querySelector('input[name="split_setup"]');
                        const panelBox = rows.querySelector('input[name="split_panel"]');
                        const tileBox = rows.querySelector('input[name="merge_tiles"]');
                        const tileLabel = document.getElementById('mergeTilesLabel');
                        function sync() {
                            const off = !tileBox
                                || (setupBox && setupBox.checked)
                                || (panelBox && !panelBox.checked);
                            if (tileBox) tileBox.disabled = !!off;
                            if (tileLabel) tileLabel.classList.toggle('opacity-50', !!off);
                        }
                        if (setupBox) setupBox.addEventListener('change', sync);
                        if (panelBox) panelBox.addEventListener('change', sync);
                        sync();
                    })();
                    </script>
                    <?php if ($tilesEffective): ?>
                        <?php if (empty($tileHints)): ?>
                        <p class="text-xs text-green-400/90 mt-1">✓ <?= htmlspecialchars(__('projects_tiles_ok')) ?></p>
                        <?php else: ?>
                        <div class="text-xs text-gray-400 mt-1">
                            <p class="font-medium text-gray-300"><?= htmlspecialchars(__('projects_tiles_unmerged')) ?></p>
                            <ul class="list-disc ml-5 mt-1 flex flex-col gap-0.5">
                                <?php foreach (array_slice($tileHints, 0, 10) as $th): ?>
                                <li>S<?= (int)$th['a']['setup_no'] ?>/P<?= (int)$th['a']['panel_no'] ?> ↔ S<?= (int)$th['b']['setup_no'] ?>/P<?= (int)$th['b']['panel_no'] ?>: <?= htmlspecialchars(tileHintReasonText($th['reason'])) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if (count($tileHints) > 10): ?><p class="text-gray-500 mt-1"><?= htmlspecialchars(__('projects_tiles_more', ['count' => count($tileHints) - 10])) ?></p><?php endif; ?>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="flex justify-end gap-2 mt-1">
                        <button type="submit" name="reset_grouping" value="1"
                                class="px-3 py-1 text-sm bg-gray-600 hover:bg-gray-500 text-white rounded transition-colors">
                            <?= __('projects_grouping_reset') ?>
                        </button>
                        <button type="submit" class="px-3 py-1 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded transition-colors">
                            <?= __('projects_save') ?>
                        </button>
                    </div>
                </form>
            </details>
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
                    <?= htmlspecialchars(__('projects_igroup_groups', ['count' => count($intGroups)])) ?> · <?= htmlspecialchars(__('projects_lights_count', ['count' => $totCount])) ?> · <span title="<?= htmlspecialchars(fmtExp($totExp)) ?>"><?= htmlspecialchars(number_format($totExp / 3600, 1)) ?> h</span> · <?= htmlspecialchars(__('projects_igroup_nights', ['count' => count($totNights)])) ?> · <?= htmlspecialchars(__('projects_igroup_excluded', ['total' => $totAuto])) ?>
                </div>
                <?php include __DIR__ . '/includes/columns_modal_projects.php'; ?>
                <?php foreach ($intGroups as $gi => $grp): ?>
                    <?php $grpAuto = count(array_filter($grp['lights'], fn($li) => !empty($li['auto_off']))); ?>
                    <div class="mb-2 border border-gray-700 rounded-lg igroup" data-group="<?= (int)$gi ?>">
                        <div class="px-4 pt-2 text-sm font-medium">
                            <?php if (!empty($grp['tile'])): ?>T<?= (int)$grp['tile']['no'] ?> · S<?= htmlspecialchars(implode('+S', array_map('strval', $grp['tile']['setups']))) ?> · <?= htmlspecialchars($grp['tile']['label']) ?><?php elseif (!empty($grp['merged_setup'])): ?><span class="text-gray-400">S*</span><?php else: ?>S<?= (int)($grp['setup_no'] ?? 0) ?><?php endif; ?><?php if (empty($grp['tile'])): ?> · <?php if (!empty($grp['merged_panel'])): ?><span class="text-gray-400">P*</span><?php else: ?><?= htmlspecialchars($grp['panel_label']) ?><?php endif; ?><?php endif; ?><?php if (empty($grp['merged_filter'])): ?> · <?= __('projects_filter') ?> <?= htmlspecialchars($grp['filter'] !== '' ? $grp['filter'] : '—') ?><?php endif; ?> · <?= htmlspecialchars(fmtExpShort($grp['rep_exp'] ?? $grp['exptime'])) ?><?php if ($grp['exptime'] === null): ?> <span class="text-gray-400" title="<?= htmlspecialchars(__('projects_grouping_merged')) ?>">*</span><?php endif; ?><?php if (!empty($grouping['split_temp'])): ?><?php if ($grp['rep_temp'] !== null): ?> · <?= htmlspecialchars(repTempDisplay($grp['rep_temp'])) ?><?php endif; ?><?php else: ?><?php $grpTempRange = repTempRange($grp['temp_min'] ?? null, $grp['temp_max'] ?? null); ?><?php if ($grpTempRange !== '—'): ?> · <?= htmlspecialchars($grpTempRange) ?><?php endif; ?><?php endif; ?>
                        </div>
                        <div class="px-4 pb-2 text-xs text-gray-400">
                            <?= htmlspecialchars(__('projects_lights_count', ['count' => $grp['count']])) ?> · <span title="<?= htmlspecialchars(fmtExp((float)$grp['exposure'])) ?>"><?= htmlspecialchars(number_format((float)$grp['exposure'] / 3600, 1)) ?> h</span> · <?= htmlspecialchars(__('projects_igroup_nights', ['count' => count($grp['nights'])])) ?>: <?= htmlspecialchars(implode(', ', $grp['nights'])) ?> · <?= htmlspecialchars(__('projects_igroup_excluded', ['total' => $grpAuto])) ?>
                        </div>
                        <details>
                            <summary class="cursor-pointer px-4 py-1.5 hover:bg-gray-700/40 rounded text-sm text-gray-300">📁 <?= __('projects_igroup_show_files', ['count' => count($grp['lights'])]) ?></summary>
                        <div class="px-4 py-2">
                            <?php include __DIR__ . '/includes/igroup_files_table.php'; ?>
                        </div>
                    </details>
                    <details>
                        <summary class="cursor-pointer px-4 py-1.5 hover:bg-gray-700/40 rounded text-sm text-gray-300">📏 <?= __('projects_reject_title') ?></summary>
                        <div class="px-4 py-2">
                            <div class="igroup-reject" data-setup="<?= (int)$grp['setup_id'] ?>" data-panel="<?= (int)$grp['panel_id'] ?>" data-filter="<?= htmlspecialchars(!empty($grp['merged_filter']) ? '' : $grp['filter']) ?>" data-exp="<?= htmlspecialchars((string)($grp['exptime'] ?? '')) ?>">
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
                                    <?php foreach ([5, 4, 3.5] as $sig): ?>
                                    <button type="button" class="reject-auto px-3 py-1 text-sm bg-purple-700 hover:bg-purple-600 text-white rounded transition-colors" data-sigma="<?= $sig ?>">
                                        Auto <?= $sig ?>σ
                                    </button>
                                    <?php endforeach; ?>
                                    <button type="button" class="reject-reset px-3 py-1 text-sm bg-gray-600 hover:bg-gray-500 text-white rounded transition-colors">
                                        <?= __('projects_reject_reset') ?>
                                    </button>
                                    <span class="reject-count text-xs text-gray-400"></span>
                                </div>
                            </div>
                        </div>
                    </details>
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
                            <details class="igroup-charts-wrap" data-group="<?= (int)$gi ?>">
                                <summary class="cursor-pointer px-4 py-1.5 hover:bg-gray-700/40 rounded text-sm text-gray-300">📊 <?= __('projects_igroup_charts') ?></summary>
                                <div class="flex flex-col gap-4 mt-2 igroup-charts">
                                    <?php
                                    $igSeriesLabels = [
                                        'hfr' => __('hfr') . ' (px)',
                                        'fwhm' => __('fwhm') . ' (arcsec)',
                                        'hfr_sd' => __('hfr_sd') . ' (px)',
                                        'eccentricity' => __('eccentricity'),
                                        'star_count' => __('star_count'),
                                        'snr_weight' => __('snr_weight'),
                                        'psf_signal' => __('psf_signal'),
                                    ];
                                    ?>
                                    <?php foreach (['hfr', 'fwhm', 'hfr_sd', 'eccentricity', 'star_count', 'snr_weight', 'psf_signal'] as $mk): ?>
                                    <?php $mkHasData = count(array_filter($grp['lights'], fn($mli) => isset($mli[$mk]) && $mli[$mk] !== '' && $mli[$mk] !== null)) > 0; ?>
                                    <?php if (!$mkHasData) continue; ?>
                                    <?php // The bar chart reads its values out of the table cells. A hidden column
                                    // means no cell for it, so every value came back null and the chart was
                                    // skipped with nothing on screen: an empty box that looked broken. Say
                                    // why instead, and still offer the column back. ?>
                                    <?php if (in_array($mk, $hiddenColsProjects, true)): ?>
                                    <div>
                                        <div class="flex items-center mb-1">
                                            <span class="text-xs font-bold" style="color: #e5e7eb;"><?= htmlspecialchars($igSeriesLabels[$mk] ?? $mk) ?></span>
                                        </div>
                                        <div class="text-xs text-gray-500 py-3"><?= __('projects_chart_column_hidden') ?></div>
                                    </div>
                                    <?php continue; ?>
                                    <?php endif; ?>
                                    <div>
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-xs font-bold" style="color: #e5e7eb;"><?= htmlspecialchars($igSeriesLabels[$mk] ?? $mk) ?></span>
                                            <span class="flex gap-1">
                                                <button type="button" class="ig-sort text-gray-400 hover:text-white text-xs px-1" data-group="<?= (int)$gi ?>" data-metric="<?= $mk ?>" data-dir="asc" title="<?= __('projects_sort_asc') ?>">▲</button>
                                                <button type="button" class="ig-sort text-gray-400 hover:text-white text-xs px-1" data-group="<?= (int)$gi ?>" data-metric="<?= $mk ?>" data-dir="desc" title="<?= __('projects_sort_desc') ?>">▼</button>
                                            </span>
                                        </div>
                                        <div style="height: 190px"><canvas id="ig-chart-<?= (int)$gi ?>-<?= htmlspecialchars($mk) ?>"></canvas></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                            <?php endif; ?>
                            <?php $grpBlinkN = count(array_filter($grp['lights'], fn($bli) => !empty($bli['enabled']) && empty($bli['auto_off']))); ?>
                            <?php if ($grpBlinkN > 0): ?>
                            <details class="igroup-blink-wrap" data-group="<?= (int)$gi ?>">
                                <summary class="cursor-pointer px-4 py-1.5 hover:bg-gray-700/40 rounded text-sm text-gray-300">👁 <?= __('projects_blink') ?> (<?= (int)$grpBlinkN ?>)</summary>
                                <div class="px-4 py-2">
                                    <div class="flex items-center gap-3 mb-2">
                                        <button type="button" class="blink-play px-3 py-1 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded transition-colors" title="<?= __('projects_blink_play') ?>">▶</button>
                                        <select class="blink-rate px-2 py-1 bg-gray-700 border border-gray-600 rounded text-gray-100 text-sm">
                                            <option value="0.25">0.25 s</option>
                                            <option value="0.5" selected>0.5 s</option>
                                            <option value="1">1 s</option>
                                            <option value="2">2 s</option>
                                        </select>
                                        <span class="blink-label text-xs text-gray-300 truncate"></span>
                                    </div>
                                    <div class="bg-black rounded flex items-center justify-center" style="height: 420px;">
                                        <img class="blink-img rounded" style="max-height: 420px; max-width: 100%; object-fit: contain;" alt="">
                                    </div>
                                </div>
                            </details>
                            <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </details>
        </section>
        <script>
        (function () {
            // Clickable headers sort each integration group table (numeric-aware).
            // Key-based: cells are looked up by data-col, so hidden/reordered
            // columns never break sorting, charts or threshold evaluation.
            // Columns with data-type="none" (preview, SFF buttons) are not sortable.
            function igSortTableByKey(table, key, dir) {
                const th = table.querySelector('thead th[data-col="' + key + '"]');
                if (!th || th.dataset.type === 'none') return;
                const numeric = th.dataset.type === 'num';
                const asc = dir === 'asc';
                table.querySelectorAll('thead th').forEach(h => { delete h.dataset.asc; const ind = h.querySelector('.ig-sort-ind'); if (ind) ind.textContent = ''; });
                th.dataset.asc = asc ? '1' : '0';
                const ind = th.querySelector('.ig-sort-ind');
                if (ind) ind.textContent = asc ? ' ▲' : ' ▼';
                const rows = Array.from(table.querySelectorAll('tbody tr'));
                rows.sort((a, b) => {
                    const va = a.querySelector('td[data-col="' + key + '"]')?.dataset.val ?? '';
                    const vb = b.querySelector('td[data-col="' + key + '"]')?.dataset.val ?? '';
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
            }
            document.querySelectorAll('.igroup-table thead th').forEach(th => {
                th.addEventListener('click', () => {
                    const table = th.closest('table');
                    const key = th.dataset.col || '';
                    if (!key || th.dataset.type === 'none') return;
                    const asc = th.dataset.asc !== '1';
                    igSortTableByKey(table, key, asc ? 'asc' : 'desc');
                });
            });

            // Chart ▲▼ buttons reuse the column sort verbatim.
            document.querySelectorAll('.ig-sort').forEach(btn => {
                btn.addEventListener('click', () => {
                    const table = document.querySelector('.igroup-table[data-group="' + btn.dataset.group + '"]');
                    if (!table) return;
                    igSortTableByKey(table, btn.dataset.metric, btn.dataset.dir);
                });
            });

            // --- Integration group bar charts (one bar per photo) ---
            // X axis follows the table's current row order; bars are greyed
            // when the file is disabled at project level.
            const IGROUP_SERIES = [
                { key: 'hfr', label: <?= json_encode(__('hfr') . ' (px)') ?>, color: '#60a5fa' },
                { key: 'hfr_sd', label: <?= json_encode(__('hfr_sd') . ' (px)') ?>, color: '#a78bfa' },
                { key: 'fwhm', label: <?= json_encode(__('fwhm') . ' (arcsec)') ?>, color: '#34d399' },
                { key: 'eccentricity', label: <?= json_encode(__('eccentricity')) ?>, color: '#fbbf24' },
                { key: 'star_count', label: <?= json_encode(__('star_count')) ?>, color: '#f472b6' },
                { key: 'snr_weight', label: <?= json_encode(__('snr_weight')) ?>, color: '#22d3ee' },
                { key: 'psf_signal', label: <?= json_encode(__('psf_signal')) ?>, color: '#fb7185' },
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
                    const num = (raw) => {
                        const v = parseFloat(raw ?? '');
                        return isNaN(v) ? null : v;
                    };
                    const vals = {};
                    IGROUP_SERIES.forEach(s => {
                        vals[s.key] = num(tr.querySelector('td[data-col="' + s.key + '"]')?.dataset.val);
                    });
                    return { name: tr.querySelector('td[data-col="name"]')?.dataset.val || '', enabled: tr.dataset.enabled === '1', linkkey: tr.dataset.linkkey || '', vals };
                });
            }
            // Shared rejection evaluation for one group table: rows failing any
            // active threshold (direction fixed per metric). Returns rejected
            // row indices; files missing the metric are never rejected by it.
            // Shared rejection evaluation for one group table: maps row index
            // to the set of metric keys whose threshold rejects it (empty =
            // included). Inclusive comparisons (>=, <=): a clicked bar that
            // set its own threshold is always included.
            function igRejectedBy(table) {
                const panel = table.closest('.igroup')?.querySelector('.igroup-reject');
                if (!panel) return new Map();
                const rules = Array.from(panel.querySelectorAll('input[data-metric]'))
                    .map(inp => ({ key: inp.dataset.metric, dir: inp.dataset.dir, t: inp.value.trim() === '' ? null : parseFloat(inp.value) }))
                    .filter(r => r.t !== null && !isNaN(r.t));
                if (!rules.length) return new Map();
                const rows = igRowsInOrder(table);
                const out = new Map();
                rows.forEach((r, i) => {
                    const why = new Set();
                    rules.forEach(rule => {
                        const v = r.vals[rule.key];
                        if (v === null || v === undefined) return;
                        if (rule.dir === 'above' ? v >= rule.t : v <= rule.t) why.add(rule.key);
                    });
                    if (why.size) out.set(i, why);
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
                IGROUP_SERIES.forEach(s => {
                    // Keyed by metric key, never by position: the PHP emitter iterates
                    // a different order, so an index-based id silently swapped fwhm and
                    // hfr_sd.
                    const cid = 'ig-chart-' + gi + '-' + s.key;
                    const canvas = document.getElementById(cid);
                    if (!canvas) return;
                    if (igCharts[cid]) { igCharts[cid].destroy(); delete igCharts[cid]; }
                    const data = rows.map(r => r.vals[s.key]);
                    if (!data.some(v => v !== null)) return;
                    const rejected = igRejectedBy(table);
                    const med = igMedian(rows.filter((r, i) => r.enabled && !rejected.has(i)).map(r => r.vals[s.key]));
                    // Active threshold for this metric (same inputs as evaluation).
                    let thr = null;
                    const thrInp = table.closest('.igroup')?.querySelector('.igroup-reject input[data-metric="' + s.key + '"]');
                    if (thrInp && thrInp.value.trim() !== '') {
                        const tv = parseFloat(thrInp.value);
                        if (!isNaN(tv)) thr = tv;
                    }
                    const datasets = [{
                        label: s.label,
                        data,
                        // Rejected by THIS metric: black bar with red border.
                        // Rejected by another threshold: grey. Included: series color.
                        backgroundColor: rows.map((r, i) => rejected.get(i)?.has(s.key) ? '#000000' : (rejected.has(i) ? IGROUP_OFF_COLOR : s.color)),
                        borderColor: rows.map((r, i) => rejected.get(i)?.has(s.key) ? '#ef4444' : 'rgba(0,0,0,0)'),
                        borderWidth: rows.map((r, i) => rejected.get(i)?.has(s.key) ? 1.5 : 0),
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
                                const panel = table.closest('.igroup')?.querySelector('.igroup-reject');
                                const inp = panel?.querySelector('input[data-metric="' + s.key + '"]');
                                if (!inp) return;
                                inp.value = String(val);
                                igRefreshPanel(panel);
                            },
                            plugins: {
                                title: { display: false },
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
                const root = table.closest('.igroup');
                const wrap = root ? root.querySelector('.igroup-charts-wrap') : null;
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

            // --- Blink player: fixed window cycling through a group's eligible
            // frames (manually disabled and threshold-rejected files excluded).
            // The visible frame's row is highlighted in the table above.
            const IGROUP_BLINK_PLAY = <?= json_encode(__('projects_blink_play')) ?>;
            const IGROUP_BLINK_PAUSE = <?= json_encode(__('projects_blink_pause')) ?>;
            function igBlinkFrames(wrap) {
                const table = wrap.closest('.igroup')?.querySelector('.igroup-table');
                if (!table) return [];
                return Array.from(table.querySelectorAll('tbody tr'))
                    .filter(tr => tr.dataset.enabled === '1' && tr.dataset.auto !== '1')
                    .map(tr => ({
                        fid: parseInt((tr.dataset.linkkey || '').split(':')[0], 10),
                        name: tr.querySelector('td[data-col="name"]')?.dataset.val || '',
                        tr,
                    }))
                    .filter(f => Number.isInteger(f.fid) && f.fid > 0);
            }
            function igBlinkShow(wrap, idx) {
                const frames = igBlinkFrames(wrap);
                if (!frames.length) return;
                const n = ((idx % frames.length) + frames.length) % frames.length;
                const table = wrap.closest('.igroup')?.querySelector('.igroup-table');
                if (table) table.querySelectorAll('tbody tr').forEach(tr => { tr.style.backgroundColor = ''; });
                const f = frames[n];
                wrap._blinkIdx = n;
                f.tr.style.backgroundColor = 'rgba(37,99,235,0.25)';
                const label = wrap.querySelector('.blink-label');
                if (label) label.textContent = (n + 1) + '/' + frames.length + ' ' + f.name;
                const img = wrap.querySelector('.blink-img');
                if (img) img.src = '/image.php?id=' + f.fid + '&type=thumb';
            }
            function igBlinkStop(wrap) {
                if (wrap._blinkTimer) {
                    clearInterval(wrap._blinkTimer);
                    wrap._blinkTimer = null;
                }
                const playBtn = wrap.querySelector('.blink-play');
                if (playBtn) {
                    playBtn.textContent = '▶';
                    playBtn.title = IGROUP_BLINK_PLAY;
                }
            }
            function igBlinkPreload(wrap) {
                igBlinkFrames(wrap).forEach(f => {
                    const pre = new Image();
                    pre.src = '/image.php?id=' + f.fid + '&type=thumb';
                });
            }
            function igBlinkStart(wrap) {
                const frames = igBlinkFrames(wrap);
                if (!frames.length) return;
                igBlinkPreload(wrap);
                const rateSel = wrap.querySelector('.blink-rate');
                const ms = Math.max(50, Math.round(parseFloat(rateSel?.value || '1') * 1000));
                const playBtn = wrap.querySelector('.blink-play');
                if (playBtn) {
                    playBtn.textContent = '⏸';
                    playBtn.title = IGROUP_BLINK_PAUSE;
                }
                igBlinkShow(wrap, (wrap._blinkIdx ?? -1) + 1);
                wrap._blinkTimer = setInterval(() => {
                    igBlinkShow(wrap, (wrap._blinkIdx ?? -1) + 1);
                }, ms);
            }
            document.querySelectorAll('.igroup-blink-wrap').forEach(wrap => {
                const playBtn = wrap.querySelector('.blink-play');
                const rateSel = wrap.querySelector('.blink-rate');
                if (playBtn) playBtn.addEventListener('click', () => {
                    if (wrap._blinkTimer) {
                        igBlinkStop(wrap);
                    } else {
                        igBlinkStart(wrap);
                    }
                });
                if (rateSel) rateSel.addEventListener('change', () => {
                    if (wrap._blinkTimer) {
                        igBlinkStop(wrap);
                        igBlinkStart(wrap);
                    }
                });
                wrap.addEventListener('toggle', () => {
                    if (!wrap.open) {
                        igBlinkStop(wrap);
                        const table = wrap.closest('.igroup')?.querySelector('.igroup-table');
                        if (table) table.querySelectorAll('tbody tr').forEach(tr => { tr.style.backgroundColor = ''; });
                    } else if (!wrap._blinkTimer) {
                        // Show a frame right away, paused (first one, or where we stopped).
                        igBlinkPreload(wrap);
                        igBlinkShow(wrap, wrap._blinkIdx ?? 0);
                    }
                });
            });

            // --- Rejection thresholds: combined OR evaluation with live preview ---
            // Direction is fixed per metric type (lower-is-better excludes above,
            // higher-is-better excludes below). Files missing a metric are never
            // rejected by it. Save persists thresholds; they alone decide inclusion.
            const IGROUP_REJECT_COUNT = <?= json_encode(__('projects_reject_count')) ?>;
            function igEvalPanel(panel) {
                const table = panel.closest('.igroup')?.querySelector('.igroup-table');
                if (!table) return [];
                const rejected = igRejectedBy(table);
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
                const table = panel.closest('.igroup')?.querySelector('.igroup-table');
                const total = table ? table.querySelectorAll('tbody tr').length : 0;
                // Store hits on the panel for the count display.
                panel._hits = igEvalPanel(panel);
                if (count) {
                    count.textContent = IGROUP_REJECT_COUNT
                        .replace('{n}', panel._hits.length)
                        .replace('{total}', total);
                }
                // Keep bar colors in sync when charts are already built.
                const rtable = panel.closest('.igroup')?.querySelector('.igroup-table');
                if (rtable) {
                    const rwrap = document.querySelector('.igroup-charts-wrap[data-group="' + rtable.dataset.group + '"]');
                    if (rwrap && rwrap.open && rwrap.dataset.built) igBuildGroup(rtable.dataset.group);
                }
            }
            document.querySelectorAll('.igroup-reject').forEach(panel => {
                panel.addEventListener('input', () => igRefreshPanel(panel));
                const save = panel.querySelector('.reject-save');
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
                panel.querySelectorAll('.reject-auto').forEach(autoBtn => autoBtn.addEventListener('click', () => {
                    // Permissive robust proposal: median ± k·MAD·1.4826, with k
                    // from the button (5/4/3.5σ). Only truly bad frames go out;
                    // borderline ones stay in. Direction fixed per metric type
                    // as usual. Metrics with <4 values or zero spread are left
                    // blank. Fills the inputs without saving: review, then Save.
                    const k = parseFloat(autoBtn.dataset.sigma) || 5;
                    const table = panel.closest('.igroup')?.querySelector('.igroup-table');
                    if (!table) return;
                    const rows = igRowsInOrder(table);
                    const robust = {};
                    ['hfr', 'fwhm', 'hfr_sd', 'eccentricity', 'star_count', 'snr_weight', 'psf_signal'].forEach(mk => {
                        const nums = rows.map(r => r.vals[mk]).filter(v => v !== null && v !== undefined);
                        if (nums.length < 4) return;
                        const med = igMedian(nums);
                        const mad = igMedian(nums.map(v => Math.abs(v - med)));
                        const sigma = 1.4826 * mad;
                        if (!(sigma > 0)) return;
                        robust[mk] = { med, sigma };
                    });
                    panel.querySelectorAll('input[data-metric]').forEach(inp => {
                        const st = robust[inp.dataset.metric];
                        if (!st) return;
                        let t = inp.dataset.dir === 'above' ? st.med + k * st.sigma : st.med - k * st.sigma;
                        if (inp.dataset.dir === 'below') t = Math.max(0, t);
                        inp.value = String(Number(t.toPrecision(6)));
                    });
                    igRefreshPanel(panel);
                }));
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
            // Ancestor group boxes reflect their subtree: checked, unchecked
            // or indeterminate when single files are (de)selected by hand.
            function syncGroupBox(scope) {
                const box = scope.querySelector(':scope > div > .pgroup-check');
                if (!box) return;
                const files = scope.querySelectorAll('.pfl-check');
                if (!files.length) {
                    box.checked = false;
                    box.indeterminate = false;
                    return;
                }
                const checked = scope.querySelectorAll('.pfl-check:checked').length;
                box.checked = checked === files.length;
                box.indeterminate = checked > 0 && checked < files.length;
            }
            // Calibration group header box reflects its own group only.
            function syncCalGroupBox(group) {
                const box = group.parentElement?.querySelector(':scope > .cgroup-check');
                if (!box) return;
                const files = group.querySelectorAll('.pfl-check');
                if (!files.length) {
                    box.checked = false;
                    box.indeterminate = false;
                    return;
                }
                const checked = group.querySelectorAll('.pfl-check:checked').length;
                box.checked = checked === files.length;
                box.indeterminate = checked > 0 && checked < files.length;
            }
            form.addEventListener('change', (e) => {
                if (e.target.classList.contains('pgroup-check')) {
                    const scope = e.target.closest('.tnode');
                    if (!scope) return;
                    scope.querySelectorAll('.pfl-check').forEach(cb => { cb.checked = e.target.checked; });
                    scope.querySelectorAll('.pgroup-check').forEach(cb => {
                        if (cb !== e.target) {
                            cb.checked = e.target.checked;
                            cb.indeterminate = false;
                        }
                    });
                    e.target.indeterminate = false;
                    scope.querySelectorAll('details.cal-group').forEach(syncCalGroupBox);
                    let above = scope.parentElement?.closest('.tnode') ?? null;
                    while (above) {
                        syncGroupBox(above);
                        above = above.parentElement?.closest('.tnode') ?? null;
                    }
                    updateTreeBulkButtons();
                    return;
                }
                if (e.target.classList.contains('pfl-check')) {
                    const group = e.target.closest('details.cal-group');
                    if (group) syncCalGroupBox(group);
                    let scope = e.target.closest('.tnode');
                    while (scope) {
                        syncGroupBox(scope);
                        scope = scope.parentElement?.closest('.tnode') ?? null;
                    }
                }
                updateTreeBulkButtons();
            });
            // Bulk actions need a selection: toggle them with the checkboxes.
            // AstroBin exports the whole project, so it stays always enabled.
            function updateTreeBulkButtons() {
                const hasSelection = form.querySelectorAll('.pfl-check:checked').length > 0;
                form.querySelectorAll('button[type="submit"][name="action"]').forEach(btn => {
                    btn.disabled = !hasSelection;
                });
                document.getElementById('scopeModalOpen')?.toggleAttribute('disabled', !hasSelection);
            }
            updateTreeBulkButtons();
            // Rename setup custom label via modal (empty clears it).
            // The pencil lives inside <summary>: stop the click from also
            // toggling the details element.
            const renameModal = document.getElementById('renameModal');
            const renameInput = document.getElementById('renameInput');
            let renameSetupId = 0;
            function openRenameModal(btn) {
                if (!renameModal || !renameInput) return;
                renameSetupId = parseInt(btn.dataset.setupId || '0', 10);
                renameInput.value = btn.dataset.setupName || '';
                renameModal.classList.remove('hidden');
                renameModal.classList.add('flex');
                renameInput.focus();
                renameInput.select();
            }
            function closeRenameModal() {
                if (!renameModal) return;
                renameModal.classList.add('hidden');
                renameModal.classList.remove('flex');
                renameSetupId = 0;
            }
            form.addEventListener('click', (e) => {
                const btn = e.target.closest('.setup-rename');
                if (!btn) return;
                e.preventDefault();
                e.stopPropagation();
                openRenameModal(btn);
            });
            document.getElementById('renameCancel')?.addEventListener('click', closeRenameModal);
            renameModal?.addEventListener('click', (e) => { if (e.target === renameModal) closeRenameModal(); });
            document.getElementById('renameSave')?.addEventListener('click', () => {
                if (!renameSetupId) return;
                const fd = new FormData();
                fd.append('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || '');
                fd.append('project_id', form.querySelector('input[name="project_id"]')?.value || '');
                fd.append('action', 'rename_setup');
                fd.append('setup_id', renameSetupId);
                fd.append('name', renameInput ? renameInput.value.trim() : '');
                fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd })
                    .finally(() => window.location.reload());
            });
            renameInput?.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    document.getElementById('renameSave')?.click();
                } else if (e.key === 'Escape') {
                    closeRenameModal();
                }
            });
        })();
        </script>
        <?php endif; ?>
    </main>
    <?php include __DIR__ . '/includes/sff_modal.php'; ?>
    <?php include __DIR__ . '/includes/astrobin_modal.php'; ?>
    <script type="text/javascript">
        // Minimal i18n bundle for sff.js / astrobin_export.js (the full bundle
        // lives in footer.php, which the standalone projects page does not include).
        window.i18n = Object.assign(window.i18n || {}, {
            loading: <?= json_encode(__('loading...')) ?>,
            copied: <?= json_encode(__('copied')) ?>,
            copy_to_clipboard_failed: <?= json_encode(__('copy_to_clipboard_failed')) ?>,
            error_fetching_csv_data: <?= json_encode(__('error_fetching_csv_data')) ?>,
            astrobin_modal_explanation: <?= json_encode(__('astrobin_modal_explanation')) ?>,
            sff_total_exposure: <?= json_encode(__('sff_total_exposure')) ?>,
            sff_modal_title: <?= json_encode(__('sff_modal_title')) ?>,
            sff_loading_filters: <?= json_encode(__('sff_loading_filters')) ?>,
            sff_error_loading_filters: <?= json_encode(__('sff_error_loading_filters')) ?>,
            sff_searching: <?= json_encode(__('sff_searching')) ?>,
            sff_frames_found_js: <?= json_encode(__('sff_frames_found_js')) ?>,
            sff_configure_and_run: <?= json_encode(__('sff_configure_and_run')) ?>,
            projects_scope_no_cal: <?= json_encode(__('projects_scope_no_cal')) ?>,
            projects_scope_single_setup: <?= json_encode(__('projects_scope_single_setup')) ?>,
            projects_export_skipped: <?= json_encode(__('projects_export_skipped')) ?>,
            projects_export_duplicated: <?= json_encode(__('projects_export_duplicated')) ?>,
            projects_export_uncompressed: <?= json_encode(__('projects_export_uncompressed')) ?>
        });
    </script>
    <script src="assets/js/sff.js?v=<?= @filemtime(__DIR__ . '/assets/js/sff.js') ?: 0 ?>"></script>
    <script src="assets/js/astrobin_export.js?v=<?= @filemtime(__DIR__ . '/assets/js/astrobin_export.js') ?: 0 ?>"></script>
    <script>
    // Project tree AstroBin export: all linked files, same shared logic as home.
    document.addEventListener('DOMContentLoaded', function() {
        const btn = document.getElementById('projectAstroBinBtn');
        if (!btn) return;
        btn.addEventListener('click', () => {
            if (typeof window.awiExportAstroBin !== 'function') return;
            window.awiExportAstroBin((btn.dataset.ids || '').split(','), btn);
        });
    });
    </script>
    <script>
    // Same UTC -> local conversion as the main page (main.js): the shared
    // file cells render .utc-date spans with a data-timestamp.
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.utc-date').forEach(function(el) {
            const timestamp = el.getAttribute('data-timestamp');
            if (timestamp && !isNaN(timestamp)) {
                const date = new Date(timestamp * 1000);
                const options = {
                    year: 'numeric', month: 'numeric', day: 'numeric',
                    hour: 'numeric', minute: 'numeric', second: 'numeric',
                    hour12: false
                };
                try {
                    el.textContent = date.toLocaleString(undefined, options);
                } catch (e) {
                    el.textContent = date.toLocaleString();
                }
            }
        });
    });
    </script>
</body>
</html>
