<?php
header('Content-Type: application/json');

ob_start();
// Renders the tree partial, so it also needs the column/table rendering stack.
$GLOBALS['AWI_API_NEEDS_TREE_RENDER'] = true;
require_once '../includes/api_bootstrap.php';
ob_end_clean();

// Hypothetical project tree for the add-to-project modal (step 3).
// Runs the REAL projectAddFiles() inside a transaction and rolls everything
// back, so the preview can never diverge from the add and never writes.
// Same request shape as project_add.php (ids, project_id, overrides,
// new_project). Returns rendered tree HTML with the new links marked green
// (hypoMode: no checkboxes, hypo paths expanded).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    awiJson(['error' => 'Method Not Allowed'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);

try {
    $req = parseProjectAddRequest(is_array($data) ? $data : []);
} catch (InvalidArgumentException $e) {
    awiJson(['error' => $e->getMessage()], 400);
}
$projectId = $req['project_id'];
$ids = $req['ids'];
$overrides = $req['overrides'];
$customSetups = $req['customSetups'];
$newProject = $req['new_project'];

if (empty($ids)) {
    // 'error' e non solo 'success':false: main.js solleva solo su data.error, quindi
    // un success:false portava a un albero vuoto senza spiegare il motivo.
    awiJson(['success' => false, 'error' => 'No valid IDs provided.'], 400);
}

/**
 * Existing link keys touching $ids within one project scope:
 * "file_id:level:node_id". Diffed before/after the add to find the new
 * (hypothetical) links.
 */
function treePreviewSnapshot(PDO $conn, int $projectId, array $ids): array
{
    if (empty($ids)) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $scope = "((pf.level = 'project' AND pf.node_id = ?) "
        . "OR (pf.level = 'setup' AND pf.node_id IN (SELECT id FROM project_setups WHERE project_id = ?)) "
        . "OR (pf.level = 'panel' AND pf.node_id IN (SELECT pp.id FROM project_panels pp "
        . "JOIN project_setups ps ON ps.id = pp.setup_id WHERE ps.project_id = ?)) "
        . "OR (pf.level IN ('session','filter') AND pf.node_id IN (SELECT ss.id FROM project_sessions ss "
        . "JOIN project_panels pp ON pp.id = ss.panel_id "
        . "JOIN project_setups ps ON ps.id = pp.setup_id WHERE ps.project_id = ?)))";
    $stmt = $conn->prepare(
        "SELECT file_id, level, node_id FROM project_files pf WHERE file_id IN ($in) AND $scope"
    );
    $stmt->execute(array_merge($ids, [$projectId, $projectId, $projectId, $projectId]));
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int)$r['file_id'] . ':' . $r['level'] . ':' . (int)$r['node_id']] = true;
    }
    return $out;
}

try {
    $conn = connectDB();
    if ($projectId > 0) {
        // Trovato e accessibile sono due domande diverse: il gate precedente faceva
        // short-circuit su getProject() === null, quindi un id inesistente entrava
        // nella transazione e mostrava un albero vuoto come se fosse un progetto
        // nuovo, e la preview prometteva un add che l'add avrebbe rifiutato.
        if (getProject($conn, $projectId) === null) {
            awiJson(['error' => __('projects_not_found')], 404);
        }
        if (!canAccessProject($conn, $projectId)) {
            awiJson(['error' => __('projects_no_access')], 403);
        }
    }
    $conn->beginTransaction();
    try {
        $prep = projectAddPrepare($conn, $projectId, $ids, $overrides, $customSetups, $newProject);
        $projectId = $prep['project_id'];
        $ids = $prep['ids'];
        $customSkipped = $prep['customSkipped'];
        $before = treePreviewSnapshot($conn, $projectId, $ids);
        $result = projectAddFiles($conn, $projectId, $ids, $req['groupFpOverrides'] ?? []);
        $after = treePreviewSnapshot($conn, $projectId, $ids);
        $hypoLinks = array_fill_keys(array_keys(array_diff_key($after, $before)), true);

        $project = getProject($conn, $projectId);
        $frozen = $project !== null && getProjectAssignMode($project) === 'frozen';
        $tree = getProjectTree($conn, $projectId, false);
        $defs = getToleranceDefs();
        $projectTols = [];
        foreach ($defs as $tkey => $tdef) {
            $projectTols[$tkey] = resolve_tol($conn, $projectId, $tkey);
        }
        $tolExpRaw = trim((string)($projectTols['tol_exp'] ?? '1%'));
        if ($tolExpRaw === '') {
            $tolExpRaw = '1%';
        }
        $filterAliases = getProjectFilterAliases($conn, $projectId);
        $projectDiag = diagnoseProjectTree($tree, $projectTols, $filterAliases);
        $grouping = getProjectGrouping($conn, $projectId);
        $intGroups = getIntegrationGroups(
            $tree,
            $tolExpRaw,
            getProjectThresholds($conn, $projectId),
            (string)($projectTols['tol_temp'] ?? '2C'),
            $grouping,
            $projectTols,
            $filterAliases
        );
        if (!empty($intGroups)) {
            $tree = markTreeAutoOff($tree, indexAutoOffLights($intGroups));
            $projectDiag = diagnoseProjectTree($tree, $projectTols, $filterAliases);
        }
        $dupLinks = indexDuplicateLinks($tree);
        $darkRoles = indexDarkRoles($tree, $projectTols);
        $flatCov = diagnoseFlatCoverage($tree, $projectTols, $darkRoles);
        $calCtxBase = [
            'dup' => $dupLinks,
            'filterAliases' => $filterAliases,
            'darkRoles' => $darkRoles,
            'flatCov' => $flatCov,
            'hypoLinks' => $hypoLinks,
            'hypoMode' => true,
            'tols' => [
                'exp' => (string)($projectTols['tol_exp'] ?? '1%'),
                'temp' => (string)($projectTols['tol_temp'] ?? '2C'),
            ],
        ];
        // Ancestors of the new links stay expanded, everything else collapses.
        $hypoOpen = ['setups' => [], 'panels' => [], 'sessions' => []];
        $sess2panel = [];
        $panel2setup = [];
        foreach ($tree['setups'] ?? [] as $setup) {
            foreach ($setup['panels'] ?? [] as $panel) {
                $panel2setup[(int)$panel['id']] = (int)$setup['id'];
                foreach ($panel['sessions'] ?? [] as $session) {
                    $sess2panel[(int)$session['id']] = (int)$panel['id'];
                }
            }
        }
        foreach ($hypoLinks as $key => $_) {
            [$hfid, $hlevel, $hnode] = explode(':', (string)$key) + [null, null, null];
            $hnode = (int)$hnode;
            if ($hlevel === 'session' || $hlevel === 'filter') {
                $hypoOpen['sessions'][$hnode] = true;
                if (isset($sess2panel[$hnode])) {
                    $hypoOpen['panels'][$sess2panel[$hnode]] = true;
                    $psid = $sess2panel[$hnode];
                    if (isset($panel2setup[$psid])) {
                        $hypoOpen['setups'][$panel2setup[$psid]] = true;
                    }
                }
            } elseif ($hlevel === 'panel') {
                $hypoOpen['panels'][$hnode] = true;
                if (isset($panel2setup[$hnode])) {
                    $hypoOpen['setups'][$panel2setup[$hnode]] = true;
                }
            } elseif ($hlevel === 'setup' || $hlevel === 'project') {
                $hypoOpen['setups'][$hnode] = true;
            }
        }
        $projectTree = $tree;
        $hypoMode = true;
        $hypoLinksMap = $hypoLinks;
        // The partial prints the tree directly, so its output has to be captured. The
        // buffer is closed in finally and the rollback lives there too: a plain catch
        // left the buffer open, so on failure the partial HTML went out ahead of the
        // JSON error and the client could not parse either. catch (Throwable), not
        // Exception, because in PHP 8 TypeError and ParseError are Errors and were
        // not caught at all — that left an unrolled-back transaction and no JSON.
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            include __DIR__ . '/../includes/projects_tree.php';
            $html = (string)ob_get_clean();
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
        }
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
    $skipped = [];
    foreach ($result['skipped'] as $s) {
        $skipped[] = ['name' => $s['name'], 'message' => __(projectAddReasonKey($s['reason']))];
    }
    foreach ($customSkipped as $s) {
        $skipped[] = [
            'name' => $s['name'],
            'message' => __('projects_add_reason_custom_exists', ['no' => $s['no'] ?? '?']),
        ];
    }
    awiJson([
        'success' => true,
        'frozen' => $frozen,
        'project_id' => $projectId,
        'added' => $result['added'],
        'skipped' => $skipped,
        'html' => $html ?? '',
    ]);
} catch (Throwable $e) {
    error_log($e->getMessage());
    awiJson(['error' => 'Database query failed.'], 500);
}
