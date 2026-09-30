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
        $stmt = $conn->prepare("SELECT ra, `dec`, rot_mean, label_object FROM project_panels WHERE id = :id");
        $stmt->execute([':id' => $nodeId]);
        $row = $stmt->fetch();
        if (!$row) {
            return "#$nodeId";
        }
        $coords = ($row['ra'] !== null && $row['dec'] !== null)
            ? number_format((float)$row['ra'], 3) . ' / ' . number_format((float)$row['dec'], 3)
            : '?';
        $label = "P$nodeId ($coords)";
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
        "SELECT s.file_id, s.level, s.node_id, s.filter_name, s.role, f.imgtype "
        . "FROM project_suggestions s JOIN files f ON f.id = s.file_id "
        . "WHERE s.id = :sid AND s.project_id = :pid AND s.status = 'pending'"
    );
    $stmt->execute([':sid' => $suggestionId, ':pid' => $projectId]);
    $row = $stmt->fetch();
    if ($row === false) {
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
        "SELECT level, node_id FROM project_suggestions "
        . "WHERE id = :sid AND project_id = :pid AND status = 'pending'"
    );
    $stmt->execute([':sid' => $suggestionId, ':pid' => $projectId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return false;
    }
    $conn->beginTransaction();
    try {
        $conn->prepare("UPDATE project_suggestions SET status = 'dismissed' WHERE id = :sid")
            ->execute([':sid' => $suggestionId]);
        pruneEmptyNode($conn, (string)$row['level'], (int)$row['node_id']);
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

/**
 * Delete a panel/session node left without links or live suggestions.
 * Setups and projects are never pruned automatically.
 */
function pruneEmptyNode(PDO $conn, string $level, int $nodeId): void
{
    if ($nodeId <= 0) {
        return;
    }
    if ($level === 'panel') {
        // Panel prune: no direct links, no linked sessions below, no live suggestions.
        $link = $conn->prepare(
            "SELECT 1 FROM project_files WHERE level = 'panel' AND node_id = :node LIMIT 1"
        );
        $link->execute([':node' => $nodeId]);
        if ($link->fetch() !== false) {
            return;
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
            return;
        }
        $live = $conn->prepare(
            "SELECT 1 FROM project_suggestions WHERE level = 'panel' AND node_id = :node "
            . "AND status IN ('pending','accepted') LIMIT 1"
        );
        $live->execute([':node' => $nodeId]);
        if ($live->fetch() !== false) {
            return;
        }
        // Sessions cascade via FK.
        $conn->prepare("DELETE FROM project_panels WHERE id = :node")->execute([':node' => $nodeId]);
    } elseif ($level === 'session' || $level === 'filter') {
        // Filter-level links live on the session row.
        $link = $conn->prepare(
            "SELECT 1 FROM project_files WHERE level IN ('session','filter') AND node_id = :node LIMIT 1"
        );
        $link->execute([':node' => $nodeId]);
        if ($link->fetch() !== false) {
            return;
        }
        $live = $conn->prepare(
            "SELECT 1 FROM project_suggestions WHERE level IN ('session','filter') AND node_id = :node "
            . "AND status IN ('pending','accepted') LIMIT 1"
        );
        $live->execute([':node' => $nodeId]);
        if ($live->fetch() !== false) {
            return;
        }
        $conn->prepare("DELETE FROM project_sessions WHERE id = :node")->execute([':node' => $nodeId]);
    }
    // Setups and projects are never pruned automatically.
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
    ];
}

function getProjects(PDO $conn): array
{
    $stmt = $conn->query("SELECT id, name, notes, tolerances, assign_mode, created_at FROM projects ORDER BY id ASC");
    return $stmt->fetchAll();
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
    // Setups, panels, sessions and links cascade via FK; files on disk untouched.
    $stmt = $conn->prepare("DELETE FROM projects WHERE id = :id");
    $stmt->execute([':id' => $id]);
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
