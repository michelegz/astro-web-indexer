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

function projectAddFiles(PDO $conn, int $projectId, array $fileIds): array
{
    $added = 0;
    $skipped = [];
    $project = getProject($conn, $projectId);
    if ($project === null) {
        return ['added' => 0, 'skipped' => [['name' => '#' . $projectId, 'reason' => 'no_project']]];
    }
    $tols = [];
    foreach (getToleranceDefs() as $key => $def) {
        $tols[$key] = resolve_tol($conn, $projectId, $key);
    }
    $meta = $conn->prepare(
        "SELECT id, path, name, imgtype, `filter`, exptime, date_obs, instrume, telescop, "
        . "cameraid, xbinning, ybinning, gain, xpixsz, ra, `dec`, objctra, objctdec, "
        . "`object`, fov_w, fov_h, objctrot "
        . "FROM files WHERE id = :id AND deleted_at IS NULL"
    );
    foreach ($fileIds as $fid) {
        $fid = (int)$fid;
        if ($fid <= 0) {
            continue;
        }
        $meta->execute([':id' => $fid]);
        $row = $meta->fetch();
        if ($row === false) {
            $skipped[] = ['name' => '#' . $fid, 'reason' => 'not_found'];
            continue;
        }
        if (!in_array(strtoupper((string)$row['imgtype']), ['LIGHT', 'DARK', 'FLAT', 'BIAS'], true)) {
            $skipped[] = ['name' => (string)$row['name'], 'reason' => 'imgtype'];
            continue;
        }
        if (!canAccessPath((string)$row['path'])) {
            $skipped[] = ['name' => (string)$row['name'], 'reason' => 'forbidden'];
            continue;
        }
        $night = !empty($row['date_obs']) ? projectAstroNight((string)$row['date_obs']) : null;
        if ($night === null) {
            $skipped[] = ['name' => (string)$row['name'], 'reason' => 'no_date'];
            continue;
        }

        $conn->beginTransaction();
        try {
            // Setup: override in this project wins, else fingerprint match, else create.
            $setupId = null;
            $ov = $conn->prepare(
                "SELECT ps.id FROM setup_overrides so JOIN project_setups ps ON ps.id = so.setup_id "
                . "WHERE so.file_id = :fid AND ps.project_id = :pid"
            );
            $ov->execute([':fid' => $fid, ':pid' => $projectId]);
            $ovRow = $ov->fetch();
            if ($ovRow !== false) {
                $setupId = (int)$ovRow['id'];
            } else {
                $fp = projectBuildFingerprint($row);
                $fs = $conn->prepare(
                    "SELECT id FROM project_setups WHERE project_id = :pid AND fingerprint = :fp"
                );
                $fs->execute([':pid' => $projectId, ':fp' => $fp]);
                $fsRow = $fs->fetch();
                if ($fsRow !== false) {
                    $setupId = (int)$fsRow['id'];
                } else {
                    $label = trim(trim((string)($row['instrume'] ?? '')) . ' + ' . trim((string)($row['telescop'] ?? '')), ' +');
                    $ins = $conn->prepare(
                        "INSERT INTO project_setups (project_id, fingerprint, label) VALUES (:pid, :fp, :label)"
                    );
                    $ins->execute([':pid' => $projectId, ':fp' => $fp, ':label' => $label !== '' ? $label : null]);
                    $setupId = (int)$conn->lastInsertId();
                }
            }

            // Panel: match by coords/rotation/FoV within tolerances, else create.
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
            $panelId = null;
            foreach ($panels->fetchAll() as $p) {
                if ($ra !== null && $dec !== null) {
                    if ($p['ra'] === null || $p['dec'] === null) {
                        continue;
                    }
                    if (projectHaversine($ra, $dec, (float)$p['ra'], (float)$p['dec']) > $tolPos) {
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
                    $panelId = (int)$p['id'];
                    break;
                }
                if ($p['ra'] === null && (string)($p['label_object'] ?? '') === $bucket) {
                    $panelId = (int)$p['id'];
                    break;
                }
            }
            if ($panelId === null) {
                $rotVal = ($row['objctrot'] !== null && $row['objctrot'] !== '') ? fmod((float)$row['objctrot'], 360.0) : null;
                $objLabel = $bucket !== 'UNKNOWN' ? trim((string)($row['object'] ?? '')) : $bucket;
                $ins = $conn->prepare(
                    "INSERT INTO project_panels (setup_id, ra, `dec`, rot_mean, fov_w, fov_h, label_object) "
                    . "VALUES (:sid, :ra, :dec, :rot, :fovw, :fovh, :label)"
                );
                $ins->execute([
                    ':sid' => $setupId, ':ra' => $ra, ':dec' => $dec, ':rot' => $rotVal,
                    ':fovw' => $row['fov_w'], ':fovh' => $row['fov_h'], ':label' => $objLabel,
                ]);
                $panelId = (int)$conn->lastInsertId();
            }

            // Session: find or create (plain time bucket).
            $sess = $conn->prepare(
                "SELECT id FROM project_sessions WHERE panel_id = :panel AND astro_night = :night"
            );
            $sess->execute([':panel' => $panelId, ':night' => $night]);
            $sessRow = $sess->fetch();
            if ($sessRow !== false) {
                $sessionId = (int)$sessRow['id'];
            } else {
                $ins = $conn->prepare("INSERT INTO project_sessions (panel_id, astro_night) VALUES (:panel, :night)");
                $ins->execute([':panel' => $panelId, ':night' => $night]);
                $sessionId = (int)$conn->lastInsertId();
            }

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
