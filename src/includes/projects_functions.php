<?php
/**
 * Project CRUD + tolerance helpers (WBPP-like hierarchy, v1 free).
 *
 * Hierarchy: PROJECT -> SETUP -> PANEL -> SESSION -> FILTER -> lights,
 * with calibration files linkable at any level (sub or master).
 * v1 stores logical links only; no physical file moves.
 *
 * Access: projects are global (no owner column yet), like the main index:
 * anyone passing requireAuth() can read/write them.
 */

/**
 * Valid assignment modes. Nothing is linked silently except in 'auto',
 * and manual links/overrides always win over wizard and auto.
 */
function getAssignModes(): array
{
    return ['manual', 'suggest', 'frozen', 'auto'];
}

function getProjectAssignMode(?array $project): string
{
    $mode = (string)($project['assign_mode'] ?? 'suggest');
    return in_array($mode, getAssignModes(), true) ? $mode : 'suggest';
}

function updateProjectAssignMode(PDO $conn, int $id, string $mode): void
{
    if (!in_array($mode, getAssignModes(), true)) {
        throw new InvalidArgumentException('Invalid assign mode');
    }
    $stmt = $conn->prepare("UPDATE projects SET assign_mode = :mode WHERE id = :id");
    $stmt->execute([':mode' => $mode, ':id' => $id]);
}

/**
 * Files awaiting review in the wizard queue for a project.
 */
function getPendingCount(PDO $conn, int $projectId): int
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM project_suggestions WHERE project_id = :id AND status = 'pending'"
    );
    $stmt->execute([':id' => $projectId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Wizard queue: pending suggestions with file info for display.
 */
function getPendingSuggestions(PDO $conn, int $projectId, int $limit = 500): array
{
    $stmt = $conn->prepare(
        "SELECT s.id, s.level, s.node_id, s.filter_name, s.role, s.reason, s.created_at, "
        . "f.id AS file_id, f.name AS file_name, f.path AS file_path, f.imgtype, "
        . "f.filter AS file_filter, f.date_obs, f.exptime "
        . "FROM project_suggestions s JOIN files f ON f.id = s.file_id "
        . "WHERE s.project_id = :id AND s.status = 'pending' "
        . "ORDER BY s.created_at ASC LIMIT :limit"
    );
    $stmt->bindValue(':id', $projectId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Human-readable target label for a suggestion/link node.
 */
function getSuggestionNodeLabel(PDO $conn, string $level, int $nodeId, ?string $filterName): string
{
    if ($level === 'project') {
        $stmt = $conn->prepare("SELECT name FROM projects WHERE id = :id");
        $stmt->execute([':id' => $nodeId]);
        $row = $stmt->fetch();
        return $row ? (string)$row['name'] : "#$nodeId";
    }
    if ($level === 'setup') {
        $stmt = $conn->prepare("SELECT label, fingerprint FROM project_setups WHERE id = :id");
        $stmt->execute([':id' => $nodeId]);
        $row = $stmt->fetch();
        if (!$row) {
            return "#$nodeId";
        }
        return $row['label'] !== null && $row['label'] !== ''
            ? (string)$row['label']
            : substr((string)$row['fingerprint'], 0, 48);
    }
    if ($level === 'panel') {
        $stmt = $conn->prepare("SELECT panel_no, ra, `dec`, rot_mean, label_object FROM project_panels WHERE id = :id");
        $stmt->execute([':id' => $nodeId]);
        $row = $stmt->fetch();
        if (!$row) {
            return "#$nodeId";
        }
        $coords = ($row['ra'] !== null && $row['dec'] !== null)
            ? number_format((float)$row['ra'], 3) . ' / ' . number_format((float)$row['dec'], 3)
            : '?';
        $label = 'P' . (int)($row['panel_no'] ?? $nodeId) . " ($coords)";
        if ($row['label_object'] !== null && $row['label_object'] !== '') {
            $label .= ' ' . $row['label_object'];
        }
        return $label;
    }
    // session / filter levels share the session row (filter adds filter_name).
    $stmt = $conn->prepare("SELECT astro_night FROM project_sessions WHERE id = :id");
    $stmt->execute([':id' => $nodeId]);
    $row = $stmt->fetch();
    $label = $row ? (string)$row['astro_night'] : "#$nodeId";
    if ($level === 'filter' && $filterName !== null && $filterName !== '') {
        $label .= ' · ' . $filterName;
    }
    return $label;
}

/**
 * Accept a suggestion: create the project_files link, mark accepted.
 * Returns true if a pending row was actually accepted.
 */
function acceptSuggestion(PDO $conn, int $projectId, int $suggestionId): bool
{
    $stmt = $conn->prepare(
        "SELECT s.file_id, s.level, s.node_id, s.filter_name, s.role, f.imgtype, f.path "
        . "FROM project_suggestions s JOIN files f ON f.id = s.file_id "
        . "WHERE s.id = :sid AND s.project_id = :pid AND s.status = 'pending'"
    );
    $stmt->execute([':sid' => $suggestionId, ':pid' => $projectId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return false;
    }
    if (function_exists('getAllowedDirs') && getAllowedDirs() !== null
        && function_exists('canAccessPath') && !canAccessPath((string)$row['path'])) {
        return false;
    }
    $conn->beginTransaction();
    try {
        $link = $conn->prepare(
            "INSERT INTO project_files (file_id, level, node_id, filter_name, role, is_light) "
            . "VALUES (:fid, :level, :node, :filter, :role, :light) "
            . "ON DUPLICATE KEY UPDATE role = VALUES(role), filter_name = VALUES(filter_name)"
        );
        $link->execute([
            ':fid' => (int)$row['file_id'],
            ':level' => $row['level'],
            ':node' => (int)$row['node_id'],
            ':filter' => $row['filter_name'],
            ':role' => $row['role'],
            ':light' => strtoupper((string)$row['imgtype']) === 'LIGHT' ? 1 : 0,
        ]);
        $conn->prepare("UPDATE project_suggestions SET status = 'accepted' WHERE id = :sid")
            ->execute([':sid' => $suggestionId]);
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

/**
 * Discard a suggestion: never proposed again. Prunes the node if it became
 * an empty panel/session with no other references.
 * Returns true if a pending row was actually discarded.
 */
function dismissSuggestion(PDO $conn, int $projectId, int $suggestionId): bool
{
    $stmt = $conn->prepare(
        "SELECT s.level, s.node_id, f.path FROM project_suggestions s "
        . "JOIN files f ON f.id = s.file_id "
        . "WHERE s.id = :sid AND s.project_id = :pid AND s.status = 'pending'"
    );
    $stmt->execute([':sid' => $suggestionId, ':pid' => $projectId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return false;
    }
    if (function_exists('getAllowedDirs') && getAllowedDirs() !== null
        && function_exists('canAccessPath') && !canAccessPath((string)$row['path'])) {
        return false;
    }
    $conn->beginTransaction();
    try {
        $conn->prepare("UPDATE project_suggestions SET status = 'dismissed' WHERE id = :sid")
            ->execute([':sid' => $suggestionId]);
        // Bottom-up prune chain (shared with manual link removal).
        projectPruneUpwards($conn, (string)$row['level'], (int)$row['node_id']);
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

/**
 * Prune helpers: each deletes its node only when fully empty and returns the
 * parent id on success (null otherwise), so dismissSuggestion() can walk the
 * chain bottom-up (session -> panel -> setup). Projects are never pruned.
 */
function pruneSessionNode(PDO $conn, int $nodeId): ?int
{
    if ($nodeId <= 0) {
        return null;
    }
    // Filter-level links live on the session row.
    $link = $conn->prepare(
        "SELECT 1 FROM project_files WHERE level IN ('session','filter') AND node_id = :node LIMIT 1"
    );
    $link->execute([':node' => $nodeId]);
    if ($link->fetch() !== false) {
        return null;
    }
    $live = $conn->prepare(
        "SELECT 1 FROM project_suggestions WHERE level IN ('session','filter') AND node_id = :node "
        . "AND status IN ('pending','accepted') LIMIT 1"
    );
    $live->execute([':node' => $nodeId]);
    if ($live->fetch() !== false) {
        return null;
    }
    $parent = $conn->prepare("SELECT panel_id FROM project_sessions WHERE id = :node");
    $parent->execute([':node' => $nodeId]);
    $r = $parent->fetch();
    if ($r === false) {
        return null;
    }
    $conn->prepare("DELETE FROM project_sessions WHERE id = :node")->execute([':node' => $nodeId]);
    return (int)$r['panel_id'];
}

function prunePanelNode(PDO $conn, int $nodeId): ?int
{
    if ($nodeId <= 0) {
        return null;
    }
    $link = $conn->prepare(
        "SELECT 1 FROM project_files WHERE level = 'panel' AND node_id = :node LIMIT 1"
    );
    $link->execute([':node' => $nodeId]);
    if ($link->fetch() !== false) {
        return null;
    }
    $child = $conn->prepare(
        "SELECT ss.id FROM project_sessions ss "
        . "LEFT JOIN project_files pf ON pf.level IN ('session','filter') AND pf.node_id = ss.id "
        . "LEFT JOIN project_suggestions sg ON sg.level IN ('session','filter') AND sg.node_id = ss.id "
        . "AND sg.status IN ('pending','accepted') "
        . "WHERE ss.panel_id = :node AND (pf.file_id IS NOT NULL OR sg.id IS NOT NULL) LIMIT 1"
    );
    $child->execute([':node' => $nodeId]);
    if ($child->fetch() !== false) {
        return null;
    }
    $live = $conn->prepare(
        "SELECT 1 FROM project_suggestions WHERE level = 'panel' AND node_id = :node "
        . "AND status IN ('pending','accepted') LIMIT 1"
    );
    $live->execute([':node' => $nodeId]);
    if ($live->fetch() !== false) {
        return null;
    }
    $parent = $conn->prepare("SELECT setup_id FROM project_panels WHERE id = :node");
    $parent->execute([':node' => $nodeId]);
    $r = $parent->fetch();
    if ($r === false) {
        return null;
    }
    // Sessions cascade via FK.
    $conn->prepare("DELETE FROM project_panels WHERE id = :node")->execute([':node' => $nodeId]);
    return (int)$r['setup_id'];
}

/**
 * Delete a setup left fully empty: no panels, no setup-level links or live
 * suggestions, no manual overrides pointing to it. Projects are never pruned.
 */
function pruneEmptySetup(PDO $conn, int $setupId): void
{
    if ($setupId <= 0) {
        return;
    }
    $panels = $conn->prepare("SELECT 1 FROM project_panels WHERE setup_id = :sid LIMIT 1");
    $panels->execute([':sid' => $setupId]);
    if ($panels->fetch() !== false) {
        return;
    }
    $links = $conn->prepare(
        "SELECT 1 FROM project_files WHERE level = 'setup' AND node_id = :sid LIMIT 1"
    );
    $links->execute([':sid' => $setupId]);
    if ($links->fetch() !== false) {
        return;
    }
    $live = $conn->prepare(
        "SELECT 1 FROM project_suggestions WHERE level = 'setup' AND node_id = :sid "
        . "AND status IN ('pending','accepted') LIMIT 1"
    );
    $live->execute([':sid' => $setupId]);
    if ($live->fetch() !== false) {
        return;
    }
    $ov = $conn->prepare("SELECT 1 FROM setup_overrides WHERE setup_id = :sid LIMIT 1");
    $ov->execute([':sid' => $setupId]);
    if ($ov->fetch() !== false) {
        return;
    }
    $conn->prepare("DELETE FROM project_setups WHERE id = :sid")->execute([':sid' => $setupId]);
}
/**
 * Bottom-up prune chain shared by dismiss and manual link removal:
 * session -> panel -> setup (each step resolves its parent BEFORE deleting,
 * so the chain stays walkable).
 */
function projectPruneUpwards(PDO $conn, string $level, int $nodeId): void
{
    if ($level === 'session' || $level === 'filter') {
        $panelId = pruneSessionNode($conn, $nodeId);
        if ($panelId !== null) {
            $setupId = prunePanelNode($conn, $panelId);
            if ($setupId !== null) {
                pruneEmptySetup($conn, $setupId);
            }
        }
    } elseif ($level === 'panel') {
        $setupId = prunePanelNode($conn, $nodeId);
        if ($setupId !== null) {
            pruneEmptySetup($conn, $setupId);
        }
    } elseif ($level === 'setup') {
        pruneEmptySetup($conn, $nodeId);
    }
}

/**
 * Verify a (level, node) belongs to the project (prevents cross-project writes).
 */
function projectOwnsNode(PDO $conn, int $projectId, string $level, int $nodeId): bool
{
    if ($nodeId <= 0) {
        return false;
    }
    if ($level === 'project') {
        return $nodeId === $projectId;
    }
    if ($level === 'setup') {
        $stmt = $conn->prepare("SELECT 1 FROM project_setups WHERE id = :n AND project_id = :pid LIMIT 1");
        $stmt->execute([':n' => $nodeId, ':pid' => $projectId]);
        return $stmt->fetch() !== false;
    }
    if ($level === 'panel') {
        $stmt = $conn->prepare(
            "SELECT 1 FROM project_panels pp JOIN project_setups ps ON ps.id = pp.setup_id "
            . "WHERE pp.id = :n AND ps.project_id = :pid LIMIT 1"
        );
        $stmt->execute([':n' => $nodeId, ':pid' => $projectId]);
        return $stmt->fetch() !== false;
    }
    if ($level === 'session' || $level === 'filter') {
        $stmt = $conn->prepare(
            "SELECT 1 FROM project_sessions ss JOIN project_panels pp ON pp.id = ss.panel_id "
            . "JOIN project_setups ps ON ps.id = pp.setup_id "
            . "WHERE ss.id = :n AND ps.project_id = :pid LIMIT 1"
        );
        $stmt->execute([':n' => $nodeId, ':pid' => $projectId]);
        return $stmt->fetch() !== false;
    }
    return false;
}

/**
 * Parse a "fileId:level:nodeId" link key from the bulk forms.
 */
function parseProjectLinkKey(string $key): ?array
{
    if (!preg_match('/^(\d+):(project|setup|panel|session|filter):(\d+)$/', trim($key), $m)) {
        return null;
    }
    return [(int)$m[1], $m[2], (int)$m[3]];
}

/**
 * Bulk remove links from a project (files on disk untouched), pruning
 * emptied nodes bottom-up. Returns the removed count.
 */
function removeProjectLinks(PDO $conn, int $projectId, array $keys): int
{
    $removed = 0;
    foreach ($keys as $key) {
        $parsed = parseProjectLinkKey((string)$key);
        if ($parsed === null) {
            continue;
        }
        [$fid, $level, $node] = $parsed;
        if (!projectOwnsNode($conn, $projectId, $level, $node)) {
            continue;
        }
        $conn->beginTransaction();
        try {
            $del = $conn->prepare(
                "DELETE FROM project_files WHERE file_id = :fid AND level = :level AND node_id = :node"
            );
            $del->execute([':fid' => $fid, ':level' => $level, ':node' => $node]);
            if ($del->rowCount() > 0) {
                $removed++;
                // A removed file becomes a candidate again: drop its accepted
                // history row so the next scan can re-propose it. Dismissed
                // rows stay dismissed (explicit rejection wins).
                $conn->prepare(
                    "DELETE FROM project_suggestions WHERE project_id = :pid AND file_id = :fid AND status = 'accepted'"
                )->execute([':pid' => $projectId, ':fid' => $fid]);
            }
            projectPruneUpwards($conn, $level, $node);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
        }
    }
    return $removed;
}

/**
 * Bulk enable/disable links (subframe selection). Disabled links stay in the
 * project but are excluded from counts, exposure and diagnostics.
 */
function setProjectLinksEnabled(PDO $conn, int $projectId, array $keys, bool $enabled): int
{
    $updated = 0;
    $upd = $conn->prepare(
        "UPDATE project_files SET enabled = :en WHERE file_id = :fid AND level = :level AND node_id = :node"
    );
    foreach ($keys as $key) {
        $parsed = parseProjectLinkKey((string)$key);
        if ($parsed === null) {
            continue;
        }
        [$fid, $level, $node] = $parsed;
        if (!projectOwnsNode($conn, $projectId, $level, $node)) {
            continue;
        }
        $upd->execute([':en' => $enabled ? 1 : 0, ':fid' => $fid, ':level' => $level, ':node' => $node]);
        $updated += $upd->rowCount();
    }
    return $updated;
}

function getToleranceDefs(): array
{
    return [
        'tol_exp_dark' => ['default' => '10%', 'hint' => '% or s'],
        'tol_temp' => ['default' => '2C', 'hint' => '°C'],
        'tol_rot' => ['default' => '3deg', 'hint' => '°'],
        'tol_pos_arcmin' => ['default' => '5', 'hint' => 'arcmin'],
        'tol_pos_fovfrac' => ['default' => '0.2', 'hint' => '× FoV'],
        'tol_fov' => ['default' => '10%', 'hint' => '%'],
        'tol_exp' => ['default' => '1%', 'hint' => '%'],
    ];
}

function getProjects(PDO $conn): array
{
    $stmt = $conn->query("SELECT id, name, notes, tolerances, assign_mode, created_at FROM projects ORDER BY id ASC");
    $rows = $stmt->fetchAll();
    // A project containing any out-of-scope file is invisible as a whole:
    // no names, no counts, no leak. Admins and auth-off see everything.
    if (function_exists('getAllowedDirs') && getAllowedDirs() !== null) {
        $rows = array_values(array_filter(
            $rows,
            fn($r) => canAccessProject($conn, (int)$r['id'])
        ));
    }
    return $rows;
}

/**
 * Project-level access gate. A project is accessible iff every linked file
 * and every live suggestion (pending/accepted) lives inside the user's
 * allowed directories. Empty projects are always visible. Returns true for
 * admins, disabled auth, or missing auth layer.
 */
function canAccessProject(PDO $conn, int $projectId): bool
{
    if (!function_exists('getAllowedDirs') || !function_exists('canAccessPath')) {
        return true;
    }
    if (getAllowedDirs() === null) {
        return true;
    }
    $links = $conn->prepare(
        "SELECT DISTINCT f.path FROM project_files pf JOIN files f ON f.id = pf.file_id "
        . "WHERE f.deleted_at IS NULL AND ((pf.level = 'project' AND pf.node_id = :pid) "
        . "OR (pf.level = 'setup' AND pf.node_id IN (SELECT id FROM project_setups WHERE project_id = :pid2)) "
        . "OR (pf.level = 'panel' AND pf.node_id IN (SELECT pp.id FROM project_panels pp "
        . "JOIN project_setups ps ON ps.id = pp.setup_id WHERE ps.project_id = :pid3)) "
        . "OR (pf.level IN ('session','filter') AND pf.node_id IN (SELECT ss.id FROM project_sessions ss "
        . "JOIN project_panels pp ON pp.id = ss.panel_id "
        . "JOIN project_setups ps ON ps.id = pp.setup_id WHERE ps.project_id = :pid4)))"
    );
    $links->execute([':pid' => $projectId, ':pid2' => $projectId, ':pid3' => $projectId, ':pid4' => $projectId]);
    foreach ($links->fetchAll(PDO::FETCH_COLUMN) as $path) {
        if (!canAccessPath((string)$path)) {
            return false;
        }
    }
    $sugg = $conn->prepare(
        "SELECT DISTINCT f.path FROM project_suggestions s JOIN files f ON f.id = s.file_id "
        . "WHERE s.project_id = :pid AND s.status IN ('pending','accepted') AND f.deleted_at IS NULL"
    );
    $sugg->execute([':pid' => $projectId]);
    foreach ($sugg->fetchAll(PDO::FETCH_COLUMN) as $path) {
        if (!canAccessPath((string)$path)) {
            return false;
        }
    }
    return true;
}

function getProject(PDO $conn, int $id): ?array
{
    $stmt = $conn->prepare("SELECT id, name, notes, tolerances, assign_mode, created_at FROM projects WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function createProject(PDO $conn, string $name, string $notes): int
{
    $stmt = $conn->prepare("INSERT INTO projects (name, notes) VALUES (:name, :notes)");
    $stmt->execute([
        ':name' => $name,
        ':notes' => $notes !== '' ? $notes : null,
    ]);
    return (int)$conn->lastInsertId();
}

function updateProject(PDO $conn, int $id, string $name, string $notes): void
{
    $stmt = $conn->prepare("UPDATE projects SET name = :name, notes = :notes WHERE id = :id");
    $stmt->execute([
        ':name' => $name,
        ':notes' => $notes !== '' ? $notes : null,
        ':id' => $id,
    ]);
}

function deleteProject(PDO $conn, int $id): void
{
    // project_files has no FK to the project tables (node_id is level-scoped),
    // so remove this project's links explicitly. Setups/panels/sessions,
    // overrides, merges and suggestions cascade via FK.
    $conn->beginTransaction();
    try {
        $conn->prepare(
            "DELETE FROM project_files WHERE (level = 'project' AND node_id = :pid) "
            . "OR (level = 'setup' AND node_id IN (SELECT id FROM project_setups WHERE project_id = :pid2)) "
            . "OR (level = 'panel' AND node_id IN (SELECT pp.id FROM project_panels pp "
            . "JOIN project_setups ps ON ps.id = pp.setup_id WHERE ps.project_id = :pid3)) "
            . "OR (level IN ('session','filter') AND node_id IN (SELECT ss.id FROM project_sessions ss "
            . "JOIN project_panels pp ON pp.id = ss.panel_id "
            . "JOIN project_setups ps ON ps.id = pp.setup_id WHERE ps.project_id = :pid4))"
        )->execute([':pid' => $id, ':pid2' => $id, ':pid3' => $id, ':pid4' => $id]);
        $conn->prepare("DELETE FROM projects WHERE id = :id")->execute([':id' => $id]);
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

/**
 * Decode the per-project tolerance overrides (JSON) into key => value.
 */
function getProjectTolerances(?string $tolerancesJson): array
{
    if ($tolerancesJson === null || $tolerancesJson === '') {
        return [];
    }
    $decoded = json_decode($tolerancesJson, true);
    return is_array($decoded) ? $decoded : [];
}

function getGlobalTolerances(PDO $conn): array
{
    $out = [];
    foreach ($conn->query("SELECT setting_key, setting_value FROM global_settings")->fetchAll() as $row) {
        $out[$row['setting_key']] = $row['setting_value'];
    }
    // Fallback to code defaults if the table was never seeded.
    foreach (getToleranceDefs() as $key => $def) {
        if (!isset($out[$key])) {
            $out[$key] = $def['default'];
        }
    }
    return $out;
}

/**
 * Store per-project overrides: only non-empty values are kept,
 * empty means "inherit global" and is not stored.
 */
function saveProjectTolerances(PDO $conn, int $id, array $overrides): void
{
    $defs = getToleranceDefs();
    $clean = [];
    foreach ($defs as $key => $def) {
        $val = trim((string)($overrides[$key] ?? ''));
        if ($val !== '') {
            $clean[$key] = substr($val, 0, 64);
        }
    }
    $stmt = $conn->prepare("UPDATE projects SET tolerances = :tolerances WHERE id = :id");
    $stmt->execute([
        ':tolerances' => $clean === [] ? null : json_encode($clean),
        ':id' => $id,
    ]);
}

/**
 * Resolve the effective tolerance for a project (override ?? global).
 * $projectId null = global value.
 */
function resolve_tol(PDO $conn, ?int $projectId, string $key): string
{
    $defs = getToleranceDefs();
    $fallback = $defs[$key]['default'] ?? '';
    if ($projectId !== null) {
        $project = getProject($conn, $projectId);
        if ($project !== null) {
            $overrides = getProjectTolerances($project['tolerances']);
            if (isset($overrides[$key]) && $overrides[$key] !== '') {
                return (string)$overrides[$key];
            }
        }
    }
    $globals = getGlobalTolerances($conn);
    return (string)($globals[$key] ?? $fallback);
}

/**
 * Manual match-or-create ("add to project"): PHP port of the Python matcher
 * (docker/python/indexer_lib/projects.py). Manual adds match an existing
 * panel by FoV/coords/rotation within tolerances, or create the missing
 * chain (setup/panel, then session/filter). Superseded pending suggestions
 * for the same file+project are removed (explicit user decision wins).
 *
 * Returns ['added' => int, 'skipped' => [['name' =>, 'reason' =>]]].
 * Reasons are short codes; the UI maps them to localized strings.
 */
function projectNormPart($value): string
{
    if ($value === null) {
        return '?';
    }
    $text = strtoupper(trim((string)$value));
    $text = preg_replace('/\s+/', ' ', $text);
    return $text !== '' ? $text : '?';
}

function projectBuildFingerprint(array $row): string
{
    $xb = $row['xbinning'] ?? null;
    $yb = $row['ybinning'] ?? null;
    $bin = ($xb !== null && $xb !== '' ? $xb : '?') . 'X' . ($yb !== null && $yb !== '' ? $yb : '?');
    return implode('|', [
        projectNormPart($row['instrume'] ?? null),
        projectNormPart($row['telescop'] ?? null),
        projectNormPart($row['cameraid'] ?? null),
        $bin,
        projectNormPart($row['gain'] ?? null),
        projectNormPart($row['xpixsz'] ?? null),
    ]);
}

function projectParseRa($objctra): ?float
{
    if ($objctra === null || trim((string)$objctra) === '') {
        return null;
    }
    if (is_numeric(trim((string)$objctra))) {
        return fmod((float)$objctra, 360.0);
    }
    $text = str_replace(['h', 'm', 's'], [':', ':', ''], strtolower(trim((string)$objctra)));
    $parts = array_map('floatval', explode(':', $text));
    while (count($parts) < 3) {
        $parts[] = 0.0;
    }
    return fmod(($parts[0] + $parts[1] / 60.0 + $parts[2] / 3600.0) * 15.0, 360.0);
}

function projectParseDec($objctdec): ?float
{
    if ($objctdec === null || trim((string)$objctdec) === '') {
        return null;
    }
    if (is_numeric(trim((string)$objctdec))) {
        return (float)$objctdec;
    }
    $text = str_replace(['d', 'm', 's'], [':', ':', ''], strtolower(trim((string)$objctdec)));
    $sign = str_starts_with(ltrim($text), '-') ? -1.0 : 1.0;
    $parts = array_map('floatval', explode(':', ltrim(trim($text), '+-')));
    while (count($parts) < 3) {
        $parts[] = 0.0;
    }
    return $sign * (abs($parts[0]) + $parts[1] / 60.0 + $parts[2] / 3600.0);
}

function projectPositionOf(array $row): array
{
    if ($row['ra'] !== null && $row['ra'] !== '' && $row['dec'] !== null && $row['dec'] !== '') {
        return [(float)$row['ra'], (float)$row['dec']];
    }
    $ra = projectParseRa($row['objctra'] ?? null);
    $dec = projectParseDec($row['objctdec'] ?? null);
    if ($ra !== null && $dec !== null) {
        return [$ra, $dec];
    }
    return [null, null];
}

function projectHaversine(float $ra1, float $dec1, float $ra2, float $dec2): float
{
    $r1 = deg2rad($ra1);
    $d1 = deg2rad($dec1);
    $r2 = deg2rad($ra2);
    $d2 = deg2rad($dec2);
    $a = sin(($d2 - $d1) / 2) ** 2 + cos($d1) * cos($d2) * sin(($r2 - $r1) / 2) ** 2;
    return rad2deg(2 * asin(min(1.0, sqrt($a))));
}

function projectRotDist($r1, $r2): array
{
    if ($r1 === null || $r1 === '' || $r2 === null || $r2 === '') {
        return [0.0, true];
    }
    $d = fmod(abs((float)$r1 - (float)$r2), 360.0);
    return [min($d, 360.0 - $d), false];
}

function projectAstroNight(string $dateObs): ?string
{
    try {
        $dt = new DateTime($dateObs);
    } catch (Exception $e) {
        return null;
    }
    $dt->modify('-12 hours');
    return $dt->format('Y-m-d');
}

function projectNumPrefix(string $value, float $default): float
{
    if (preg_match('/^[\s]*([0-9]+(?:\.[0-9]+)?)/', $value, $m)) {
        return (float)$m[1];
    }
    return $default;
}

/**
 * Shared match-or-create building blocks. projectMatchPlan() is dry-run
 * (reads only) and feeds both the preview endpoint and projectAddFiles(),
 * so the two can never diverge.
 */

function projectFetchEligibleRow(PDO $conn, int $fid): array
{
    static $meta = null;
    if ($meta === null) {
        $meta = $conn->prepare(
            "SELECT id, path, name, imgtype, `filter`, exptime, date_obs, instrume, telescop, "
            . "cameraid, xbinning, ybinning, gain, xpixsz, ra, `dec`, objctra, objctdec, "
            . "`object`, fov_w, fov_h, objctrot "
            . "FROM files WHERE id = :id AND deleted_at IS NULL"
        );
    }
    if ($fid <= 0) {
        return [null, 'not_found'];
    }
    $meta->execute([':id' => $fid]);
    $row = $meta->fetch();
    if ($row === false) {
        return [null, 'not_found'];
    }
    if (!in_array(strtoupper((string)$row['imgtype']), ['LIGHT', 'DARK', 'FLAT', 'BIAS'], true)) {
        return [$row, 'imgtype'];
    }
    if (!canAccessPath((string)$row['path'])) {
        return [$row, 'forbidden'];
    }
    if (empty($row['date_obs']) || projectAstroNight((string)$row['date_obs']) === null) {
        return [$row, 'no_date'];
    }
    return [$row, null];
}

function projectSetupLabel(array $row): ?string
{
    $label = trim(trim((string)($row['instrume'] ?? '')) . ' + ' . trim((string)($row['telescop'] ?? '')), ' +');
    return $label !== '' ? $label : null;
}

function projectFindSetup(PDO $conn, int $projectId, string $fingerprint): ?int
{
    $fs = $conn->prepare("SELECT id FROM project_setups WHERE project_id = :pid AND fingerprint = :fp");
    $fs->execute([':pid' => $projectId, ':fp' => $fingerprint]);
    $fsRow = $fs->fetch();
    return $fsRow !== false ? (int)$fsRow['id'] : null;
}

function projectCreateSetup(PDO $conn, int $projectId, string $fingerprint, ?string $label): int
{
    $ins = $conn->prepare(
        "INSERT INTO project_setups (project_id, fingerprint, label) VALUES (:pid, :fp, :label)"
    );
    $ins->execute([':pid' => $projectId, ':fp' => $fingerprint, ':label' => $label]);
    return (int)$conn->lastInsertId();
}

function projectFindPanel(PDO $conn, int $setupId, array $row, array $tols): array
{
    [$ra, $dec] = projectPositionOf($row);
    $tolPos = max(projectNumPrefix($tols['tol_pos_arcmin'], 5.0) / 60.0, 1e-6);
    $fovMin = null;
    if ($row['fov_w'] !== null && $row['fov_h'] !== null && (float)$row['fov_w'] > 0 && (float)$row['fov_h'] > 0) {
        $fovMin = min((float)$row['fov_w'], (float)$row['fov_h']) / 60.0;
        $tolPos = max($tolPos, projectNumPrefix($tols['tol_pos_fovfrac'], 0.2) * $fovMin);
    }
    $tolRot = projectNumPrefix($tols['tol_rot'], 3.0);
    $tolFov = projectNumPrefix($tols['tol_fov'], 10.0) / 100.0;
    $bucket = strtoupper(trim((string)preg_replace('/\s+/', ' ', (string)($row['object'] ?? ''))));
    if ($bucket === '') {
        $bucket = 'UNKNOWN';
    }
    $panels = $conn->prepare(
        "SELECT id, ra, `dec`, rot_mean, fov_w, fov_h, label_object FROM project_panels WHERE setup_id = :sid"
    );
    $panels->execute([':sid' => $setupId]);
    foreach ($panels->fetchAll() as $p) {
        if ($ra !== null && $dec !== null) {
            if ($p['ra'] === null || $p['dec'] === null) {
                continue;
            }
            $sep = projectHaversine($ra, $dec, (float)$p['ra'], (float)$p['dec']);
            if ($sep > $tolPos) {
                continue;
            }
            [$dist, $unknown] = projectRotDist($row['objctrot'] ?? null, $p['rot_mean']);
            if (!$unknown && $dist > $tolRot) {
                continue;
            }
            if ($fovMin !== null && $p['fov_w'] !== null && $p['fov_h'] !== null) {
                // Both in degrees ($fovMin is arcmin/60).
                $panelFov = min((float)$p['fov_w'], (float)$p['fov_h']) / 60.0;
                if ($panelFov > 0 && abs($fovMin - $panelFov) / $panelFov > $tolFov) {
                    continue;
                }
            }
            return ['id' => (int)$p['id'], 'new' => false, 'sep' => $sep, 'rot_d' => $dist,
                'rot_unknown' => $unknown, 'ra' => $ra, 'dec' => $dec, 'bucket' => $bucket,
                'tol_pos' => $tolPos, 'tol_rot' => $tolRot];
        }
        if ($p['ra'] === null && (string)($p['label_object'] ?? '') === $bucket) {
            return ['id' => (int)$p['id'], 'new' => false, 'sep' => 0.0, 'rot_d' => 0.0,
                'rot_unknown' => true, 'ra' => null, 'dec' => null, 'bucket' => $bucket,
                'tol_pos' => $tolPos, 'tol_rot' => $tolRot];
        }
    }
    return ['id' => null, 'new' => true, 'sep' => 0.0, 'rot_d' => 0.0,
        'rot_unknown' => ($row['objctrot'] ?? null) === null || ($row['objctrot'] ?? null) === '',
        'ra' => $ra, 'dec' => $dec, 'bucket' => $bucket,
        'tol_pos' => $tolPos, 'tol_rot' => $tolRot];
}

function projectCreatePanel(PDO $conn, int $setupId, array $row, string $bucket, $ra, $dec): int
{
    $rotVal = ($row['objctrot'] !== null && $row['objctrot'] !== '') ? fmod((float)$row['objctrot'], 360.0) : null;
    $objLabel = $bucket !== 'UNKNOWN' ? trim((string)($row['object'] ?? '')) : $bucket;
    // Next consecutive number within the project (stable: never reused).
    $maxNo = $conn->prepare(
        "SELECT COALESCE(MAX(pp.panel_no), 0) FROM project_panels pp "
        . "JOIN project_setups ps ON ps.id = pp.setup_id "
        . "WHERE ps.project_id = (SELECT project_id FROM project_setups WHERE id = :sid)"
    );
    $maxNo->execute([':sid' => $setupId]);
    $nextNo = (int)$maxNo->fetchColumn() + 1;
    $ins = $conn->prepare(
        "INSERT INTO project_panels (setup_id, ra, `dec`, rot_mean, fov_w, fov_h, label_object, panel_no) "
        . "VALUES (:sid, :ra, :dec, :rot, :fovw, :fovh, :label, :no)"
    );
    $ins->execute([
        ':sid' => $setupId, ':ra' => $ra, ':dec' => $dec, ':rot' => $rotVal,
        ':fovw' => $row['fov_w'], ':fovh' => $row['fov_h'], ':label' => $objLabel, ':no' => $nextNo,
    ]);
    return (int)$conn->lastInsertId();
}

function projectFindOrCreateSession(PDO $conn, int $panelId, string $night): int
{
    $sess = $conn->prepare("SELECT id FROM project_sessions WHERE panel_id = :panel AND astro_night = :night");
    $sess->execute([':panel' => $panelId, ':night' => $night]);
    $sessRow = $sess->fetch();
    if ($sessRow !== false) {
        return (int)$sessRow['id'];
    }
    $ins = $conn->prepare("INSERT INTO project_sessions (panel_id, astro_night) VALUES (:panel, :night)");
    $ins->execute([':panel' => $panelId, ':night' => $night]);
    return (int)$conn->lastInsertId();
}

function getProjectSetups(PDO $conn, int $projectId): array
{
    $stmt = $conn->prepare("SELECT id, fingerprint, label FROM project_setups WHERE project_id = :pid ORDER BY id ASC");
    $stmt->execute([':pid' => $projectId]);
    return $stmt->fetchAll();
}

/**
 * Dry-run match for one file: no writes. $project null = brand-new project
 * (everything flagged new). Returns plan array for preview and add paths.
 */
function projectMatchPlan(PDO $conn, ?array $project, array $row, array $tols, int $fid): array
{
    $pid = $project !== null ? (int)$project['id'] : 0;
    $fp = projectBuildFingerprint($row);
    $setupId = null;
    $setupNew = true;
    if ($project !== null) {
        $ov = $conn->prepare(
            "SELECT ps.id FROM setup_overrides so JOIN project_setups ps ON ps.id = so.setup_id "
            . "WHERE so.file_id = :fid AND ps.project_id = :pid"
        );
        $ov->execute([':fid' => $fid, ':pid' => $pid]);
        $ovRow = $ov->fetch();
        if ($ovRow !== false) {
            $setupId = (int)$ovRow['id'];
            $setupNew = false;
        } else {
            $found = projectFindSetup($conn, $pid, $fp);
            if ($found !== null) {
                $setupId = $found;
                $setupNew = false;
            }
        }
    }
    $panel = ['id' => null, 'new' => true, 'sep' => 0.0, 'rot_d' => 0.0, 'rot_unknown' => true,
        'ra' => null, 'dec' => null, 'bucket' => '', 'tol_pos' => 0.0, 'tol_rot' => 0.0];
    if ($setupId !== null) {
        $panel = projectFindPanel($conn, $setupId, $row, $tols);
    } else {
        [$ra, $dec] = projectPositionOf($row);
        $bucket = strtoupper(trim((string)preg_replace('/\s+/', ' ', (string)($row['object'] ?? ''))));
        $panel['ra'] = $ra;
        $panel['dec'] = $dec;
        $panel['bucket'] = $bucket !== '' ? $bucket : 'UNKNOWN';
    }
    $night = projectAstroNight((string)$row['date_obs']);
    $isLight = strtoupper((string)$row['imgtype']) === 'LIGHT';
    $filt = trim((string)($row['filter'] ?? ''));
    return [
        'setup_id' => $setupId, 'setup_new' => $setupNew,
        'setup_fp' => $fp, 'setup_label' => projectSetupLabel($row),
        'panel' => $panel,
        'night' => $night,
        'level' => $isLight ? 'filter' : 'setup',
        'filter_name' => $filt !== '' ? $filt : null,
        'is_light' => $isLight,
    ];
}

/**
 * Dry-run preview for the 2-step modal: groups files by setup fingerprint,
 * no writes. $projectId null = new project (all groups flagged new).
 */
function projectPreviewFiles(PDO $conn, ?int $projectId, array $fileIds): array
{
    $project = ($projectId !== null && $projectId > 0) ? getProject($conn, $projectId) : null;
    if ($projectId !== null && $projectId > 0 && $project === null) {
        return ['groups' => [], 'skipped' => [['name' => '#' . $projectId, 'reason' => 'no_project']]];
    }
    $globals = getGlobalTolerances($conn);
    $tols = [];
    foreach (getToleranceDefs() as $key => $def) {
        if ($project !== null) {
            $ov = getProjectTolerances($project['tolerances']);
            $tols[$key] = (isset($ov[$key]) && $ov[$key] !== '') ? (string)$ov[$key] : (string)($globals[$key] ?? $def['default']);
        } else {
            $tols[$key] = (string)($globals[$key] ?? $def['default']);
        }
    }
    $groups = [];
    $skipped = [];
    foreach ($fileIds as $fid) {
        $fid = (int)$fid;
        if ($fid <= 0) {
            continue;
        }
        [$row, $skipReason] = projectFetchEligibleRow($conn, $fid);
        if ($row === null || $skipReason !== null) {
            $skipped[] = ['name' => $row !== null ? (string)$row['name'] : '#' . $fid, 'reason' => $skipReason ?? 'not_found'];
            continue;
        }
        $plan = projectMatchPlan($conn, $project, $row, $tols, $fid);
        $key = $plan['setup_fp'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'fp' => $key, 'setup_id' => $plan['setup_id'], 'setup_new' => $plan['setup_new'],
                'setup_label' => $plan['setup_label'], 'files' => [],
            ];
        }
        $groups[$key]['files'][] = [
            'id' => $fid, 'name' => (string)$row['name'], 'imgtype' => (string)$row['imgtype'],
            'filter' => trim((string)($row['filter'] ?? '')), 'date_obs' => (string)($row['date_obs'] ?? ''),
            'night' => $plan['night'], 'level' => $plan['level'],
            'panel_id' => $plan['panel']['id'], 'panel_new' => $plan['panel']['new'],
            'sep_arcmin' => $plan['panel']['sep'] * 60.0, 'rot_d' => $plan['panel']['rot_d'],
            'rot_unknown' => $plan['panel']['rot_unknown'],
            'panel_ra' => $plan['panel']['ra'], 'panel_dec' => $plan['panel']['dec'],
            'bucket' => $plan['panel']['bucket'],
            'tol_pos_arcmin' => $plan['panel']['tol_pos'] * 60.0, 'tol_rot' => $plan['panel']['tol_rot'],
        ];
        // Group setup outcome follows the majority (override rows aside, fp is group key).
        if ($plan['setup_id'] !== null && $groups[$key]['setup_id'] === null) {
            $groups[$key]['setup_id'] = $plan['setup_id'];
            $groups[$key]['setup_new'] = $plan['setup_new'];
        }
    }
    $panelIds = [];
    foreach ($groups as $g) {
        foreach ($g['files'] as $f) {
            if (!empty($f['panel_id'])) {
                $panelIds[(int)$f['panel_id']] = true;
            }
        }
    }
    $panelLabels = [];
    if (!empty($panelIds)) {
        $placeholders = implode(',', array_fill(0, count($panelIds), '?'));
        $pl = $conn->prepare(
            "SELECT id, panel_no, ra, `dec`, label_object FROM project_panels WHERE id IN ($placeholders)"
        );
        $pl->execute(array_keys($panelIds));
        foreach ($pl->fetchAll() as $p) {
            $coords = ($p['ra'] !== null && $p['dec'] !== null)
                ? number_format((float)$p['ra'], 3) . '/' . number_format((float)$p['dec'], 3) : '?';
            $label = 'P' . (int)($p['panel_no'] ?? $p['id']) . ' (' . $coords . ')';
            if ($p['label_object'] !== null && $p['label_object'] !== '') {
                $label .= ' ' . $p['label_object'];
            }
            $panelLabels[(int)$p['id']] = $label;
        }
    }
    return ['groups' => array_values($groups), 'skipped' => $skipped, 'panels' => $panelLabels];
}

function projectAddFiles(PDO $conn, int $projectId, array $fileIds): array
{
    $added = 0;
    $skipped = [];
    $project = getProject($conn, $projectId);
    if ($project === null) {
        return ['added' => 0, 'skipped' => [['name' => '#' . $projectId, 'reason' => 'no_project']]];
    }
    // Frozen means locked: no manual adds either (unfreeze first).
    if (getProjectAssignMode($project) === 'frozen') {
        return ['added' => 0, 'skipped' => [['name' => (string)$project['name'], 'reason' => 'frozen']]];
    }
    $tols = [];
    foreach (getToleranceDefs() as $key => $def) {
        $tols[$key] = resolve_tol($conn, $projectId, $key);
    }
    foreach ($fileIds as $fid) {
        $fid = (int)$fid;
        if ($fid <= 0) {
            continue;
        }
        [$row, $skipReason] = projectFetchEligibleRow($conn, $fid);
        if ($row === null || $skipReason !== null) {
            $skipped[] = ['name' => $row !== null ? (string)$row['name'] : '#' . $fid, 'reason' => $skipReason ?? 'not_found'];
            continue;
        }

        $conn->beginTransaction();
        try {
            $plan = projectMatchPlan($conn, $project, $row, $tols, $fid);
            // Create missing setup/panel (session/filter buckets are always created).
            if ($plan['setup_id'] === null) {
                $setupId = projectCreateSetup($conn, $projectId, $plan['setup_fp'], $plan['setup_label']);
            } else {
                $setupId = $plan['setup_id'];
            }

            // Panel: matched by the plan, else create.
            if ($plan['panel']['id'] === null) {
                $panelId = projectCreatePanel(
                    $conn, $setupId, $row,
                    $plan['panel']['bucket'], $plan['panel']['ra'], $plan['panel']['dec']
                );
            } else {
                $panelId = $plan['panel']['id'];
            }

            // Session: plain time bucket, find or create.
            $sessionId = projectFindOrCreateSession($conn, $panelId, $plan['night']);

            // Link: lights under filter level, calibrations at setup level.
            $isLight = strtoupper((string)$row['imgtype']) === 'LIGHT';
            $filt = trim((string)($row['filter'] ?? ''));
            if ($isLight) {
                $level = 'filter';
                $node = $sessionId;
                $filterName = $filt !== '' ? $filt : null;
            } else {
                $level = 'setup';
                $node = $setupId;
                $filterName = (strtoupper((string)$row['imgtype']) === 'FLAT' && $filt !== '') ? $filt : null;
            }
            $exists = $conn->prepare(
                "SELECT 1 FROM project_files WHERE file_id = :fid AND level = :level AND node_id = :node LIMIT 1"
            );
            $exists->execute([':fid' => $fid, ':level' => $level, ':node' => $node]);
            if ($exists->fetch() !== false) {
                $conn->rollBack();
                $skipped[] = ['name' => (string)$row['name'], 'reason' => 'already'];
                continue;
            }
            $link = $conn->prepare(
                "INSERT INTO project_files (file_id, level, node_id, filter_name, role, is_light) "
                . "VALUES (:fid, :level, :node, :filter, 'sub', :light)"
            );
            $link->execute([
                ':fid' => $fid, ':level' => $level, ':node' => $node,
                ':filter' => $filterName, ':light' => $isLight ? 1 : 0,
            ]);
            // Explicit user decision supersedes queued suggestions.
            $conn->prepare("DELETE FROM project_suggestions WHERE project_id = :pid AND file_id = :fid")
                ->execute([':pid' => $projectId, ':fid' => $fid]);
            $conn->commit();
            $added++;
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $skipped[] = ['name' => (string)($row['name'] ?? "#$fid"), 'reason' => 'error'];
        }
    }
    return ['added' => $added, 'skipped' => $skipped];
}
