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
 * Tolerance definitions: setting key => [global default, unit hint].
 * Values are stored as plain strings (e.g. '10%', '2C', '3deg').
 */
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
