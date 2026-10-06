<?php

declare(strict_types=1);

/**
 * Bootstrap for the projects API endpoints.
 *
 * init.php is the home page bootstrap: beyond auth and the shared includes it also
 * runs the whole file-listing pipeline for the main table — folder tree, three
 * aggregate queries, a star-metrics trend scan capped at 10 000 rows, a LIGHT count
 * and a full paged file query — and assigns $folders/$files/$totalRecords/... that
 * no JSON endpoint reads. Five endpoints paid for it on every request, including
 * export_project_zip.php which then opened a second connection via connectDB().
 *
 * This loads the same shared code without that block. Call it instead of
 * '../includes/init.php':
 *
 *   ob_start();
 *   require_once '../includes/api_bootstrap.php';
 *   ob_end_clean();
 *
 * Options (before the require, or via the globals below):
 *   $AWI_API_NEEDS_TREE_RENDER = true  loads the tree/table partials and the
 *                                     column-visibility globals. Only
 *                                     project_tree_preview.php renders HTML; the
 *                                     others must not pay for it.
 *
 * requireAuth() is kept rather than requireAuthApi() on purpose: switching to the
 * JSON 401 changes the response an expired session produces, and that is a
 * behaviour change of its own (tracked separately).
 */

require_once '/var/www/html/vendor/autoload.php';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_functions.php';
require_once __DIR__ . '/http_json.php';
require_once __DIR__ . '/projects_functions.php';
require_once __DIR__ . '/projects_diagnostics.php';

// Session before language, so a stored language preference is available.
session_start();

require_once __DIR__ . '/language_functions.php';
require_once __DIR__ . '/language.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/project_export.php';

$treeRender = !empty($GLOBALS['AWI_API_NEEDS_TREE_RENDER']);
if ($treeRender) {
    require_once __DIR__ . '/template_functions.php';
    require_once __DIR__ . '/columns.php';
    require_once __DIR__ . '/file_cells.php';
    require_once __DIR__ . '/igroup_files_table.php';
}

requireAuth();

if ($treeRender) {
    // Column visibility, same derivation as init.php:50-72. The tree partials and
    // renderFileTableCells() read these globals.
    $showStarMetrics = true;
    $starMetricsEnv = getenv('STAR_METRICS_ENABLED');
    if ($starMetricsEnv !== false
        && in_array(strtolower(trim((string)$starMetricsEnv)), ['0', 'false', 'no', 'off'], true)) {
        $showStarMetrics = false;
    }

    $toggleableKeys = getToggleableKeys();
    $columnGroups = getColumnGroups();
    $advKeys = [];
    foreach ($columnGroups as $groupKey => $group) {
        if ($groupKey !== 'star' && $groupKey !== 'frame') {
            $advKeys = array_merge($advKeys, array_keys($group['columns']));
        }
    }
    $starKeys = array_keys($columnGroups['star']['columns']);
    $frameKeys = array_keys($columnGroups['frame']['columns']);
    $hiddenCols = resolveHiddenColumns($toggleableKeys);
    $visibleAdvKeys = array_values(array_diff($advKeys, $hiddenCols));
    $visibleStarKeys = $showStarMetrics ? array_values(array_diff($starKeys, $hiddenCols)) : [];
    $visibleFrameKeys = array_values(array_diff($frameKeys, $hiddenCols));
    $visibleBaseKeys = array_values(array_diff(array_keys(getBaseColumns()), $hiddenCols));

    $hiddenColsProjects = resolveHiddenColumnsForCookie(
        $toggleableKeys, 'hiddenColsProjects', getProjectsDefaultVisible());
    $visibleProjectAdvKeys = array_values(array_diff($advKeys, $hiddenColsProjects));
    $visibleProjectStarKeys = $showStarMetrics
        ? array_values(array_diff($starKeys, $hiddenColsProjects)) : [];
    $visibleProjectFrameKeys = array_values(array_diff($frameKeys, $hiddenColsProjects));

    $tableColspan = 1 + count($visibleBaseKeys) + count($visibleAdvKeys)
        + count($visibleStarKeys) + count($visibleFrameKeys);
}