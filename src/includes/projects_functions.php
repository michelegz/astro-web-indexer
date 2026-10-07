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
 * Suggestions previously discarded by the user. They still come back on their
 * own when the matching config or the file headers change, so this count is
 * informational: the manual "fish again" button is the fallback.
 */
function getDismissedCount(PDO $conn, int $projectId): int
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM project_suggestions WHERE project_id = :id AND status = 'dismissed'"
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
 * Tolerances the Python suggester actually reads (docker/python/
 * indexer_lib/projects.py, suggest_file). tol_exp and tol_temp are excluded on
 * purpose: they drive diagnostics/grouping only, so including them would
 * re-open dismissed rows that can never match.
 * Order is part of the contract with the Python side.
 */
const PROJECT_SUGGEST_TOL_KEYS = ['tol_pos_arcmin', 'tol_pos_fovfrac', 'tol_rot', 'tol_fov'];

/**
 * File header columns the suggester matches on, in a fixed order. Raw values
 * only: no parsing is shared with Python, so the two implementations cannot
 * drift on RA/DEC or fingerprint formatting. Order is part of the contract.
 * Appended at the end, never reordered: rotator_angle/readoutm feed matching
 * (meteo columns stay out of the hash on purpose: display-only).
 */
const PROJECT_SUGGEST_MATCH_FIELDS = [
    'imgtype', 'filter', 'instrume', 'telescop', 'cameraid', 'xbinning',
    'ybinning', 'gain', 'offset', 'xpixsz', 'ra', 'dec', 'objctra',
    'objctdec', 'object', 'fov_w', 'fov_h', 'objctrot', 'date_obs',
    'rotator_angle', 'readoutm',
];

/**
 * Canonical token for one tolerance value: numeric prefix normalized with
 * string ops only (no float formatting, which differs across runtimes), so
 * '5', '5.0' and ' 5.00 ' all collapse to the same token. Unparsable values
 * keep their trimmed raw form. Mirrors _suggest_tol_token() in projects.py.
 */
function projectSuggestTolToken(string $raw): string
{
    $text = trim($raw);
    $num = '';
    foreach (str_split($text) as $ch) {
        if (ctype_digit($ch) || $ch === '.' || $ch === '-') {
            $num .= $ch;
        } else {
            break;
        }
    }
    if ($num === '' || $num === '-' || $num === '.' || $num === '-.') {
        return $text;
    }
    $sign = '';
    if ($num[0] === '-') {
        $sign = '-';
        $num = substr($num, 1);
    }
    $dot = strpos($num, '.');
    if ($dot === false) {
        $int = $num;
        $frac = '';
    } else {
        $int = substr($num, 0, $dot);
        $frac = substr($num, $dot + 1);
    }
    $int = ltrim($int, '0');
    if ($int === '') {
        $int = '0';
    }
    $frac = rtrim($frac, '0');
    return $sign . $int . ($frac !== '' ? '.' . $frac : '');
}

/**
 * md5 of the effective tolerances the suggester reads (project override ??>
 * global ??> code default, exactly as resolve_tol()/Python tol() do).
 * Stored at dismissal time so a later dismissal stays valid until the user
 * really changes the matching config.
 */
function getProjectSuggestConfigHash(PDO $conn, int $projectId): string
{
    $lines = [];
    foreach (PROJECT_SUGGEST_TOL_KEYS as $key) {
        $lines[] = $key . '=' . projectSuggestTolToken(resolve_tol($conn, $projectId, $key));
    }
    return md5(implode("\n", $lines));
}

/**
 * Canonical snapshot of the raw header values the matcher consumes.
 *
 * Every column is read as CAST(... AS CHAR) on purpose: PDO hands floats and
 * doubles back as strings while the Python driver hands back float objects,
 * so the same value would otherwise be rendered differently on each side.
 * Letting MySQL produce one text rendering for both makes the comparison
 * byte-exact and keeps this side free of any parsing logic.
 */
function getProjectSuggestMatchInputs(PDO $conn, int $fileId): ?string
{
    $selects = [];
    foreach (PROJECT_SUGGEST_MATCH_FIELDS as $f) {
        $selects[] = 'CAST(`' . $f . '` AS CHAR) AS `' . $f . '`';
    }
    $stmt = $conn->prepare(
        'SELECT ' . implode(', ', $selects) . ' FROM files WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $fileId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    $lines = [];
    foreach (PROJECT_SUGGEST_MATCH_FIELDS as $f) {
        $v = $row[$f] ?? null;
        $lines[] = $f . '=' . ($v === null ? '~' : (string)$v);
    }
    return implode("\n", $lines);
}

/**
 * Discard a suggestion: never proposed again until the matching config or the
 * file headers change (compare config_hash / match_inputs). Prunes the node if
 * it became an empty panel/session with no other references.
 * Returns true if a pending row was actually discarded.
 */
function dismissSuggestion(PDO $conn, int $projectId, int $suggestionId): bool
{
    $stmt = $conn->prepare(
        "SELECT s.level, s.node_id, s.file_id, f.path FROM project_suggestions s "
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
    // Snapshot the decision context INSIDE the transaction: the hash must
    // describe the config as it is at dismissal time, not as it was when the
    // file was suggested.
    $conn->beginTransaction();
    try {
        $conn->prepare(
            "UPDATE project_suggestions SET status = 'dismissed', "
            . "config_hash = :hash, match_inputs = :inputs WHERE id = :sid"
        )->execute([
            ':hash' => getProjectSuggestConfigHash($conn, $projectId),
            ':inputs' => getProjectSuggestMatchInputs($conn, (int)$row['file_id']),
            ':sid' => $suggestionId,
        ]);
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
 * Drop every dismissed suggestion of a project so the next suggest pass
 * reproposes them all (manual "fish again" escape hatch). Pending and accepted
 * rows are untouched. Returns the number of dismissed rows removed.
 */
function resuggestDismissed(PDO $conn, int $projectId): int
{
    $stmt = $conn->prepare(
        "DELETE FROM project_suggestions WHERE project_id = :pid AND status = 'dismissed'"
    );
    $stmt->execute([':pid' => $projectId]);
    return $stmt->rowCount();
}

/**
 * Valid reasons for a re-suggest request (PHP enqueue side; the Python
 * worker treats them as opaque labels).
 */
function getSuggestRequestReasons(): array
{
    return ['panel_created', 'setup_created', 'tolerances_changed', 'manual'];
}

/**
 * Enqueue an async re-suggest pass for one project. The web container never
 * runs the Python indexer itself: the Python watcher polls this table and
 * runs suggest_projects_backfill(project_id).
 *
 * Coalesced: at most one pending row per project (five triggers in a minute
 * need a single pass). Skipped in frozen/manual modes, where automation
 * never runs anyway. Returns true when a row was created or one was already
 * pending; false when skipped or on invalid input.
 */
function enqueueSuggestRequest(PDO $conn, int $projectId, string $reason): bool
{
    if ($projectId <= 0 || !in_array($reason, getSuggestRequestReasons(), true)) {
        return false;
    }
    try {
        $project = getProject($conn, $projectId);
    } catch (Exception $e) {
        return false;
    }
    if ($project === null) {
        return false;
    }
    if (in_array(getProjectAssignMode($project), ['frozen', 'manual'], true)) {
        return false;
    }
    try {
        $exists = $conn->prepare(
            "SELECT 1 FROM suggest_requests WHERE project_id = :pid AND status = 'pending' LIMIT 1"
        );
    } catch (Exception $e) {
        // Table missing (migrations not applied yet): skip silently.
        return false;
    }
    try {
        // Fast path, to avoid raising an exception on the common coalesced case: this
        // is called once per created panel and setup. It is not the guarantee, only
        // the optimisation. Two writers can both pass it — a panel_created enqueued by
        // the web container after its commit, while another user presses
        // request_suggest — so the invariant is enforced by uq_suggest_pending instead,
        // which rejects the second insert.
        $exists->execute([':pid' => $projectId]);
        if ($exists->fetch() !== false) {
            return true;
        }
        $conn->prepare(
            "INSERT INTO suggest_requests (project_id, reason, status) VALUES (:pid, :reason, 'pending')"
        )->execute([':pid' => $projectId, ':reason' => $reason]);
        return true;
    } catch (Throwable $e) {
        // 1062 on uq_suggest_pending means another request for the same project won
        // the race between the check above and this insert. Coalescing is the point
        // of the queue, so a lost race is a success, not an error.
        if (($e->getCode() === '23000') || str_contains($e->getMessage(), '1062')) {
            return true;
        }
        error_log('enqueueSuggestRequest failed for project ' . $projectId . ': '
            . $e->getMessage());
        return false;
    }
}

/**
 * Latest re-suggest request of a project for the wizard status line.
 * Returns null when the table is missing or the project never queued one.
 */
function getSuggestRequestStatus(PDO $conn, int $projectId): ?array
{
    try {
        $stmt = $conn->prepare(
            "SELECT status, reason, result, created_at, started_at, finished_at "
            . "FROM suggest_requests WHERE project_id = :pid ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':pid' => $projectId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Plain-text status line for the latest re-suggest request (callers escape
 * for HTML). Shows state plus the worker counts when available.
 */
function suggestStatusLine(array $status): string
{
    $state = (string)($status['status'] ?? '');
    $reason = (string)($status['reason'] ?? '');
    $label = $state !== '' ? $state : '?';
    if ($reason !== '') {
        $label .= ' · ' . $reason;
    }
    $result = (string)($status['result'] ?? '');
    if ($result !== '') {
        $decoded = json_decode($result, true);
        if (is_array($decoded)) {
            $parts = [];
            foreach (['suggested', 'linked', 'skipped'] as $k) {
                if (isset($decoded[$k])) {
                    $parts[] = $k . ' ' . (int)$decoded[$k];
                }
            }
            if ($parts !== []) {
                $label .= ' (' . implode(', ', $parts) . ')';
            }
        }
    }
    return $label;
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
    // A session targeted by a calibration scope is not empty: it carries
    // live intent just like a pending suggestion (see moveProjectLink).
    $scoped = $conn->prepare(
        "SELECT 1 FROM project_calib_scope WHERE session_id = :node LIMIT 1"
    );
    $scoped->execute([':node' => $nodeId]);
    if ($scoped->fetch() !== false) {
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
    // Same live-intent rule for scopes targeting this panel's sessions.
    $scoped = $conn->prepare(
        "SELECT 1 FROM project_calib_scope sc "
        . "JOIN project_sessions ss ON ss.id = sc.session_id "
        . "WHERE ss.panel_id = :node LIMIT 1"
    );
    $scoped->execute([':node' => $nodeId]);
    if ($scoped->fetch() !== false) {
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
    // Scopes targeting this setup's sessions keep it alive (see above).
    $scoped = $conn->prepare(
        "SELECT 1 FROM project_calib_scope sc "
        . "JOIN project_sessions ss ON ss.id = sc.session_id "
        . "JOIN project_panels pp ON pp.id = ss.panel_id "
        . "WHERE pp.setup_id = :sid LIMIT 1"
    );
    $scoped->execute([':sid' => $setupId]);
    if ($scoped->fetch() !== false) {
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
 * Move one calibration link one level along its chain (session ⇄ panel ⇄ setup).
 * The move is DELETE + INSERT preserving role/enabled/filter_name/is_light,
 * then the old chain is pruned bottom-up. Session scope (project_calib_scope)
 * is always dropped: the user picks the target node, not a subset of sessions
 * under it, so carrying (or silently intersecting) the old scope would either
 * lose it without notice or hand back a scope the user never asked for. The
 * unscoped link keeps working by position. Callers surface scope_cleared so
 * the UI can tell the user to re-check the assignment.
 * Returns ['moved' => true, 'scope_cleared' => bool].
 * Throws on missing link, frozen project, foreign nodes or non-adjacent
 * targets (no identical duplicates).
 */
function moveProjectLink(PDO $conn, int $projectId, int $fid,
    string $fromLevel, int $fromNode, string $toLevel, int $toNode): array
{
    $allowed = ['session', 'panel', 'setup'];
    if ($fid <= 0 || !in_array($fromLevel, $allowed, true) || !in_array($toLevel, $allowed, true)) {
        throw new InvalidArgumentException(__('projects_error_name'));
    }
    if ($fromLevel === $toLevel && $fromNode === $toNode) {
        throw new InvalidArgumentException(__('projects_move_exists'));
    }
    $project = getProject($conn, $projectId);
    if ($project === null) {
        throw new InvalidArgumentException(__('projects_error_name'));
    }
    if (getProjectAssignMode($project) === 'frozen') {
        throw new InvalidArgumentException(__('projects_add_frozen'));
    }
    if (!projectOwnsNode($conn, $projectId, $fromLevel, $fromNode)
        || !projectOwnsNode($conn, $projectId, $toLevel, $toNode)) {
        throw new InvalidArgumentException(__('projects_error_name'));
    }
    if (!projectMoveAdjacent($conn, $fromLevel, $fromNode, $toLevel, $toNode)) {
        throw new InvalidArgumentException(__('projects_error_name'));
    }
    $row = $conn->prepare(
        "SELECT role, enabled, filter_name, is_light FROM project_files "
        . "WHERE file_id = :fid AND level = :level AND node_id = :node LIMIT 1"
    );
    $row->execute([':fid' => $fid, ':level' => $fromLevel, ':node' => $fromNode]);
    $link = $row->fetch();
    if ($link === false) {
        throw new InvalidArgumentException(__('projects_error_name'));
    }
    $dup = $conn->prepare(
        "SELECT 1 FROM project_files WHERE file_id = :fid AND level = :level AND node_id = :node LIMIT 1"
    );
    $dup->execute([':fid' => $fid, ':level' => $toLevel, ':node' => $toNode]);
    if ($dup->fetch() !== false) {
        throw new InvalidArgumentException(__('projects_move_exists'));
    }
    $conn->beginTransaction();
    try {
        $scopeRows = $conn->prepare(
            "SELECT session_id FROM project_calib_scope WHERE file_id = :fid AND level = :level AND node_id = :node"
        );
        $scopeRows->execute([':fid' => $fid, ':level' => $fromLevel, ':node' => $fromNode]);
        $scopeSessions = array_map('intval', $scopeRows->fetchAll(PDO::FETCH_COLUMN));
        $conn->prepare(
            "DELETE FROM project_files WHERE file_id = :fid AND level = :level AND node_id = :node"
        )->execute([':fid' => $fid, ':level' => $fromLevel, ':node' => $fromNode]);
        // Explicit cleanup on top of the FK cascade (engine-independent):
        // stale rows under the old identity must never resurrect.
        $conn->prepare(
            "DELETE FROM project_calib_scope WHERE file_id = :fid AND level = :level AND node_id = :node"
        )->execute([':fid' => $fid, ':level' => $fromLevel, ':node' => $fromNode]);
        $conn->prepare(
            "INSERT INTO project_files (file_id, level, node_id, filter_name, role, is_light, enabled) "
            . "VALUES (:fid, :level, :node, :filter, :role, :light, :en)"
        )->execute([
            ':fid' => $fid, ':level' => $toLevel, ':node' => $toNode,
            ':filter' => $link['filter_name'], ':role' => $link['role'],
            ':light' => $link['is_light'], ':en' => $link['enabled'],
        ]);
        // The DELETE above already dropped every scope row of the old identity
        // and none is re-inserted: the scope is reported to the caller instead.
        projectPruneUpwards($conn, $fromLevel, $fromNode);
        $conn->commit();
        return ['moved' => true, 'scope_cleared' => !empty($scopeSessions)];
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

/**
 * Adjacency check for calibration moves: target must be the direct parent or
 * a direct child of the origin node within the same chain.
 */
function projectMoveAdjacent(PDO $conn, string $fromLevel, int $fromNode, string $toLevel, int $toNode): bool
{
    if ($fromLevel === 'session' && $toLevel === 'panel') {
        $st = $conn->prepare("SELECT panel_id FROM project_sessions WHERE id = :n");
        $st->execute([':n' => $fromNode]);
        $r = $st->fetch();
        return $r !== false && (int)$r['panel_id'] === $toNode;
    }
    if ($fromLevel === 'panel' && $toLevel === 'setup') {
        $st = $conn->prepare("SELECT setup_id FROM project_panels WHERE id = :n");
        $st->execute([':n' => $fromNode]);
        $r = $st->fetch();
        return $r !== false && (int)$r['setup_id'] === $toNode;
    }
    if ($fromLevel === 'panel' && $toLevel === 'session') {
        $st = $conn->prepare("SELECT panel_id FROM project_sessions WHERE id = :n");
        $st->execute([':n' => $toNode]);
        $r = $st->fetch();
        return $r !== false && (int)$r['panel_id'] === $fromNode;
    }
    if ($fromLevel === 'setup' && $toLevel === 'panel') {
        $st = $conn->prepare("SELECT setup_id FROM project_panels WHERE id = :n");
        $st->execute([':n' => $toNode]);
        $r = $st->fetch();
        return $r !== false && (int)$r['setup_id'] === $fromNode;
    }
    if ($fromLevel === 'setup' && $toLevel === 'session') {
        $st = $conn->prepare(
            "SELECT pp.setup_id FROM project_sessions ss "
            . "JOIN project_panels pp ON pp.id = ss.panel_id WHERE ss.id = :n"
        );
        $st->execute([':n' => $toNode]);
        $r = $st->fetch();
        return $r !== false && (int)$r['setup_id'] === $fromNode;
    }
    return false;
}
/**
 * Bulk set session scope on calibration links (transverse grouping across
 * sessions, e.g. one dark set for several nights). Replace semantics: an
 * empty session list clears the scope (link applies to the whole chain
 * again). Sessions must descend from the link node; frozen projects refuse.
 * Returns ['updated' => n, 'skipped' => m].
 */
function setProjectCalibScope(PDO $conn, int $projectId, array $keys, array $sessionIds): array
{
    $updated = 0;
    $skipped = 0;
    $project = getProject($conn, $projectId);
    if ($project === null) {
        throw new InvalidArgumentException(__('projects_error_name'));
    }
    if (getProjectAssignMode($project) === 'frozen') {
        throw new InvalidArgumentException(__('projects_add_frozen'));
    }
    $sessionIds = array_values(array_unique(array_map('intval', $sessionIds)));
    $sessionIds = array_values(array_filter($sessionIds, fn($s) => $s > 0));
    foreach ($keys as $key) {
        $parsed = parseProjectLinkKey((string)$key);
        if ($parsed === null) {
            $skipped++;
            continue;
        }
        [$fid, $level, $node] = $parsed;
        if ($level !== 'setup' && $level !== 'panel') {
            $skipped++;
            continue;
        }
        if (!projectOwnsNode($conn, $projectId, $level, $node)) {
            $skipped++;
            continue;
        }
        $row = $conn->prepare(
            "SELECT is_light FROM project_files WHERE file_id = :fid AND level = :level AND node_id = :node LIMIT 1"
        );
        $row->execute([':fid' => $fid, ':level' => $level, ':node' => $node]);
        $link = $row->fetch();
        if ($link === false || !empty($link['is_light'])) {
            $skipped++;
            continue;
        }
        // Every session must descend from the link node (same setup / panel).
        $valid = true;
        foreach ($sessionIds as $sid) {
            $st = $conn->prepare(
                "SELECT pp.setup_id, ss.panel_id FROM project_sessions ss "
                . "JOIN project_panels pp ON pp.id = ss.panel_id "
                . "JOIN project_setups ps ON ps.id = pp.setup_id "
                . "WHERE ss.id = :s AND ps.project_id = :pid LIMIT 1"
            );
            $st->execute([':s' => $sid, ':pid' => $projectId]);
            $srow = $st->fetch();
            if ($srow === false) {
                $valid = false;
                break;
            }
            if ($level === 'setup' && (int)$srow['setup_id'] !== $node) {
                $valid = false;
                break;
            }
            if ($level === 'panel' && (int)$srow['panel_id'] !== $node) {
                $valid = false;
                break;
            }
        }
        if (!$valid) {
            $skipped++;
            continue;
        }
        $conn->beginTransaction();
        try {
            $conn->prepare(
                "DELETE FROM project_calib_scope WHERE file_id = :fid AND level = :level AND node_id = :node"
            )->execute([':fid' => $fid, ':level' => $level, ':node' => $node]);
            $ins = $conn->prepare(
                "INSERT INTO project_calib_scope (file_id, level, node_id, session_id) "
                . "VALUES (:fid, :level, :node, :sid)"
            );
            foreach ($sessionIds as $sid) {
                $ins->execute([':fid' => $fid, ':level' => $level, ':node' => $node, ':sid' => $sid]);
            }
            $conn->commit();
            $updated++;
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $skipped++;
        }
    }
    return ['updated' => $updated, 'skipped' => $skipped];
}

/**
 * Bulk promote calibration links one level up (session/filter->panel,
 * panel->setup). Lights and setup-level links are no-ops, not failures.
 * Returns ['done' => n, 'skipped' => m, 'noop' => k, 'scope_cleared' => n].
 */
function promoteCalibLinks(PDO $conn, int $projectId, array $keys): array
{
    $done = 0;
    $skipped = 0;
    $noop = 0;
    $scopeCleared = 0;
    $project = getProject($conn, $projectId);
    if ($project === null) {
        throw new InvalidArgumentException(__('projects_error_name'));
    }
    if (getProjectAssignMode($project) === 'frozen') {
        throw new InvalidArgumentException(__('projects_add_frozen'));
    }
    foreach ($keys as $key) {
        $parsed = parseProjectLinkKey((string)$key);
        if ($parsed === null) {
            $skipped++;
            continue;
        }
        [$fid, $level, $node] = $parsed;
        if (!in_array($level, ['session', 'filter', 'panel'], true)) {
            $noop++;
            continue;
        }
        if (!projectOwnsNode($conn, $projectId, $level, $node)) {
            $skipped++;
            continue;
        }
        $row = $conn->prepare(
            "SELECT is_light FROM project_files WHERE file_id = :fid AND level = :level AND node_id = :node LIMIT 1"
        );
        $row->execute([':fid' => $fid, ':level' => $level, ':node' => $node]);
        $link = $row->fetch();
        if ($link === false) {
            $skipped++;
            continue;
        }
        if (!empty($link['is_light'])) {
            $noop++;
            continue;
        }
        if ($level === 'panel') {
            $pst = $conn->prepare("SELECT setup_id FROM project_panels WHERE id = :n");
            $pst->execute([':n' => $node]);
            $prow = $pst->fetch();
            if ($prow === false) {
                $skipped++;
                continue;
            }
            $to = ['setup', (int)$prow['setup_id']];
        } else {
            // Session- and filter-level links both live on a session row.
            $sst = $conn->prepare("SELECT panel_id FROM project_sessions WHERE id = :n");
            $sst->execute([':n' => $node]);
            $srow = $sst->fetch();
            if ($srow === false) {
                $skipped++;
                continue;
            }
            $to = ['panel', (int)$srow['panel_id']];
        }
        try {
            $res = moveProjectLink($conn, $projectId, $fid, $level, $node, $to[0], $to[1]);
            $done++;
            // Only successful moves can have dropped a scope: a throw rolls
            // the whole move back, so nothing was cleared.
            if (!empty($res['scope_cleared'])) {
                $scopeCleared++;
            }
        } catch (Exception $e) {
            $skipped++;
        }
    }
    return ['done' => $done, 'skipped' => $skipped, 'noop' => $noop, 'scope_cleared' => $scopeCleared];
}

/**
 * Bulk demote calibration links to their own night session (panel/setup
 * level only; created when missing). Lights, session-level links and files
 * without date_obs are no-ops, not failures. For setup-level links the panel
 * holding a session on that night wins, else the first panel by panel_no.
 * Returns ['done', 'skipped', 'noop', 'scope_cleared'].
 */
function demoteCalibLinks(PDO $conn, int $projectId, array $keys): array
{
    $done = 0;
    $skipped = 0;
    $noop = 0;
    $scopeCleared = 0;
    $project = getProject($conn, $projectId);
    if ($project === null) {
        throw new InvalidArgumentException(__('projects_error_name'));
    }
    if (getProjectAssignMode($project) === 'frozen') {
        throw new InvalidArgumentException(__('projects_add_frozen'));
    }
    foreach ($keys as $key) {
        $parsed = parseProjectLinkKey((string)$key);
        if ($parsed === null) {
            $skipped++;
            continue;
        }
        [$fid, $level, $node] = $parsed;
        if ($level !== 'panel' && $level !== 'setup') {
            $noop++;
            continue;
        }
        if (!projectOwnsNode($conn, $projectId, $level, $node)) {
            $skipped++;
            continue;
        }
        $row = $conn->prepare(
            "SELECT f.date_obs, pf.is_light FROM project_files pf "
            . "JOIN files f ON f.id = pf.file_id "
            . "WHERE pf.file_id = :fid AND pf.level = :level AND pf.node_id = :node LIMIT 1"
        );
        $row->execute([':fid' => $fid, ':level' => $level, ':node' => $node]);
        $link = $row->fetch();
        if ($link === false) {
            $skipped++;
            continue;
        }
        if (!empty($link['is_light']) || empty($link['date_obs'])) {
            $noop++;
            continue;
        }
        $night = projectAstroNight((string)$link['date_obs']);
        if ($night === null) {
            $noop++;
            continue;
        }
        if ($level === 'panel') {
            $targetPanel = $node;
        } else {
            $pst = $conn->prepare(
                "SELECT pp.id FROM project_panels pp "
                . "LEFT JOIN project_sessions ss ON ss.panel_id = pp.id AND ss.astro_night = :night "
                . "WHERE pp.setup_id = :sid ORDER BY (ss.id IS NULL), pp.panel_no ASC, pp.id ASC LIMIT 1"
            );
            $pst->execute([':sid' => $node, ':night' => $night]);
            $prow = $pst->fetch();
            if ($prow === false) {
                $skipped++;
                continue;
            }
            $targetPanel = (int)$prow['id'];
        }
        try {
            $targetSession = projectFindOrCreateSession($conn, $targetPanel, $night);
            $res = moveProjectLink($conn, $projectId, $fid, $level, $node, 'session', $targetSession);
            $done++;
            // Only successful moves can have dropped a scope: a throw rolls
            // the whole move back, so nothing was cleared.
            if (!empty($res['scope_cleared'])) {
                $scopeCleared++;
            }
        } catch (Exception $e) {
            $skipped++;
        }
    }
    return ['done' => $done, 'skipped' => $skipped, 'noop' => $noop, 'scope_cleared' => $scopeCleared];
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
                // Scope rows carry no enforced FK: drop them explicitly or a
                // future re-link at the same identity would resurrect them.
                $conn->prepare(
                    "DELETE FROM project_calib_scope WHERE file_id = :fid AND level = :level AND node_id = :node"
                )->execute([':fid' => $fid, ':level' => $level, ':node' => $node]);
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
        'tol_exp' => ['default' => '1%', 'hint' => '% or s'],
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
    // Pending suggestions deliberately do NOT veto the project. The indexer runs
    // unscoped (it has no user context), so it can legitimately hold a proposal
    // on a file outside this user's directories; hiding the whole project for a
    // suggestion the user cannot even see makes their own files' project vanish
    // with no message. Out-of-scope suggestions are filtered out of the review
    // queue instead (getProjectTree), and acceptSuggestion() already refuses to
    // link them. Links above are still a veto: a project that genuinely contains
    // files the user may not read is not theirs to open.
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
    // overrides, merges and suggestions cascade via FK. Scope rows carry no
    // enforced FK either: clean them explicitly (by link identity and by
    // session) or they linger as garbage that a future re-link could resurrect.
    $conn->beginTransaction();
    try {
        $conn->prepare(
            "DELETE sc FROM project_calib_scope sc "
            . "JOIN project_files pf ON pf.file_id = sc.file_id AND pf.level = sc.level AND pf.node_id = sc.node_id "
            . "WHERE (pf.level = 'project' AND pf.node_id = :pid) "
            . "OR (pf.level = 'setup' AND pf.node_id IN (SELECT id FROM project_setups WHERE project_id = :pid2)) "
            . "OR (pf.level = 'panel' AND pf.node_id IN (SELECT pp.id FROM project_panels pp "
            . "JOIN project_setups ps ON ps.id = pp.setup_id WHERE ps.project_id = :pid3)) "
            . "OR (pf.level IN ('session','filter') AND pf.node_id IN (SELECT ss.id FROM project_sessions ss "
            . "JOIN project_panels pp ON pp.id = ss.panel_id "
            . "JOIN project_setups ps ON ps.id = pp.setup_id WHERE ps.project_id = :pid4))"
        )->execute([':pid' => $id, ':pid2' => $id, ':pid3' => $id, ':pid4' => $id]);
        $conn->prepare(
            "DELETE sc FROM project_calib_scope sc "
            . "JOIN project_sessions ss ON ss.id = sc.session_id "
            . "JOIN project_panels pp ON pp.id = ss.panel_id "
            . "JOIN project_setups ps ON ps.id = pp.setup_id "
            . "WHERE ps.project_id = :pid"
        )->execute([':pid' => $id]);
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
 *
 * A tolerance is a magnitude, so a negative value is refused rather than stored. The
 * form fields are free text and used to accept anything up to 64 chars: a stored '-5'
 * then read as -5.0 in the Python suggester, where `dist > tol_rot` is always true and
 * no panel is ever matched, while projectNumPrefix() here fell back to the default.
 * The two halves of the feature silently disagreed about the same setting. Rejecting it
 * at the boundary is what keeps them agreeing; clamping would hide the mistake.
 *
 * The value keeps its unit: the defaults are '1%', '2C', '3deg', '10%', so this checks
 * that the string *starts* with a non-negative number rather than being one. That is
 * the same notion both readers use, so what is accepted here is exactly what they will
 * later parse, and what is refused is what they would silently misread.
 */
function saveProjectTolerances(PDO $conn, int $id, array $overrides): void
{
    $defs = getToleranceDefs();
    $clean = [];
    foreach ($defs as $key => $def) {
        $val = trim((string)($overrides[$key] ?? ''));
        if ($val === '') {
            continue;
        }
        if (!preg_match('/^\d+(\.\d+)?/', $val)) {
            throw new InvalidArgumentException(__('projects_tolerances_invalid', [
                'value' => $val,
                'expected' => (string)($def['hint'] ?? ''),
            ]));
        }
        $clean[$key] = substr($val, 0, 64);
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
 * Normalize a name-ish value for display in the tree: trimmed, upper-cased, inner
 * whitespace collapsed, empty shown as '?'.
 *
 * Note: a docblock describing "manual match-or-create (add to project)" used to sit here
 * and document this helper. That text belongs to projectAddFiles() further down, which
 * had no docblock at all; this helper had been inserted between the comment and its
 * function, so the comment silently documented the wrong thing. It has been moved, after
 * checking each claim against the code: the return shape, the removal of superseded
 * suggestions for the same file+project, and the setup/panel/session chain all hold.
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

/**
 * Human-readable setup fingerprint: each part labeled so positional values
 * (gain, pixel size, offset...) are identifiable. Handles legacy 6-part
 * fingerprints (no offset) and the '|CUSTOM:name' suffix. Returns plain
 * text; callers escape for HTML.
 */
function renderSetupFingerprint(?string $fp): string
{
    $labels = ['instrume', 'telescop', 'cameraid', 'binning', 'gain', 'xpixsz', 'offset'];
    $out = [];
    foreach (explode('|', (string)($fp ?? '')) as $i => $part) {
        $part = trim($part);
        if (str_starts_with($part, 'CUSTOM:')) {
            $out[] = trim(substr($part, 7)) !== '' ? trim(substr($part, 7)) : $part;
            continue;
        }
        $label = $labels[$i] ?? null;
        $out[] = ($label !== null ? __($label) . ': ' : '') . ($part !== '' ? $part : '?');
    }
    return implode(' | ', $out);
}

function projectBuildFingerprint(array $row): string
{
    // Instrument fingerprint: INSTRUME|TELESCOP|CAMERAID|XBINxYBIN|GAIN|XPIXSZ|OFFSET.
    // Numeric parts rely on PHP's shortest round-trip float formatting
    // (100.0 -> "100", 3.76 -> "3.76"); the Python builder applies the same
    // canonical rule, so both sides match byte-identically. NULL/missing -> '?'.
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
        projectNormPart($row['offset'] ?? null),
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
    // Unknown stays unknown. A non-numeric value used to reach (float)$r1, which
    // silently yields 0.0, so a file whose rotation metadata is junk ("N/A", a
    // timestamp) looked aligned with a panel at 0 degrees, was not rejected by the
    // tolerance and got an "ok" badge instead of "unknown". is_numeric() also covers
    // null and the empty string, which the old explicit checks handled. The Python
    // twin rotation_distance() already reports unknown for these.
    if (!is_numeric($r1) || !is_numeric($r2)) {
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
            . "cameraid, xbinning, ybinning, gain, `offset`, xpixsz, ra, `dec`, objctra, objctdec, "
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

/**
 * True when $e is a duplicate-key error raised by $indexName.
 *
 * The numbering allocators read MAX()+1 and can lose the race against a
 * concurrent writer (the manual add path and the indexer watcher run side by
 * side), so on that specific collision the number is recomputed and retried.
 *
 * The check is deliberately narrow: project_setups also carries a unique key on
 * (project_id, fingerprint), and a duplicate fingerprint is an expected outcome
 * that the caller resolves by re-reading the existing setup. Retrying that one
 * would spin without ever succeeding, so only the numbering key is retried and
 * anything else is rethrown untouched.
 */
function projectRetryOnDuplicate(PDOException $e, string $indexName): bool
{
    $driverCode = (int)($e->errorInfo[1] ?? 0);
    if ($driverCode !== 1062 && $e->getCode() !== '23000') {
        return false;
    }
    // The driver message names the key: "Duplicate entry '3-3' for key
    // 'uq_project_setups_no'". Without a usable message it is safer to propagate
    // than to swallow an unrelated conflict.
    $message = (string)($e->errorInfo[2] ?? $e->getMessage());
    return $message !== '' && str_contains($message, $indexName);
}

function projectCreateSetup(PDO $conn, int $projectId, string $fingerprint, ?string $label): int
{
    // Next consecutive number within the project. Numbers are reused once the
    // highest-numbered sibling is pruned, so an old export ZIP can show S3 for a
    // different setup than the one it names.
    $ins = $conn->prepare(
        "INSERT INTO project_setups (project_id, fingerprint, label, setup_no) VALUES (:pid, :fp, :label, :no)"
    );
    // The manual add path and the indexer watcher write concurrently by design, so
    // MAX()+1 can be read by both before either inserts. The unique key turns that
    // into a retryable error instead of two rows sharing an S number.
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $maxNo = $conn->prepare(
            "SELECT COALESCE(MAX(setup_no), 0) FROM project_setups WHERE project_id = :pid"
        );
        $maxNo->execute([':pid' => $projectId]);
        $nextNo = (int)$maxNo->fetchColumn() + 1;
        try {
            $ins->execute([':pid' => $projectId, ':fp' => $fingerprint, ':label' => $label, ':no' => $nextNo]);
            return (int)$conn->lastInsertId();
        } catch (PDOException $e) {
            if (!projectRetryOnDuplicate($e, 'uq_project_setups_no')) {
                throw $e;
            }
        }
    }
    throw new PDOException("Could not allocate a free setup_no for project {$projectId}");
}

/**
 * Rename a setup's custom label (empty clears it back to the fingerprint
 * display). Verifies the setup belongs to the project. Returns false when
 * the setup does not exist in this project.
 */
function renameProjectSetup(PDO $conn, int $projectId, int $setupId, ?string $label): bool
{
    $check = $conn->prepare("SELECT id FROM project_setups WHERE id = :sid AND project_id = :pid");
    $check->execute([':sid' => $setupId, ':pid' => $projectId]);
    if ($check->fetch() === false) {
        return false;
    }
    $label = $label !== null ? trim($label) : '';
    $upd = $conn->prepare("UPDATE project_setups SET label = :label WHERE id = :sid");
    $upd->execute([':label' => $label !== '' ? mb_substr($label, 0, 255) : null, ':sid' => $setupId]);
    return true;
}

/**
 * OBJECT bucket key: uppercased, trimmed, inner whitespace collapsed, 'UNKNOWN'
 * when empty. Mirrors normalize_object() in docker/python/indexer_lib/projects.py,
 * and is applied on both the write and the read side of project_panels.
 * label_object, so a panel created from a differently cased or spaced header
 * still matches the next file.
 */
function projectNormalizeObject($raw): string
{
    $text = strtoupper(trim((string)preg_replace('/\s+/', ' ', (string)($raw ?? ''))));
    return $text !== '' ? $text : 'UNKNOWN';
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
    $bucket = projectNormalizeObject($row['object'] ?? null);
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
        if ($p['ra'] === null && projectNormalizeObject($p['label_object'] ?? null) === $bucket) {
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
    // Store the normalized bucket, not the raw header: the panel is found again
    // by comparing label_object with the bucket of the next file's OBJECT, so a
    // raw 'ngc 7000' could never match a 'NGC 7000'. projectFindPanel also
    // normalizes what it reads, so panels written before this rule still match.
    $objLabel = $bucket;
    $ins = $conn->prepare(
        "INSERT INTO project_panels (setup_id, ra, `dec`, rot_mean, fov_w, fov_h, label_object, panel_no) "
        . "VALUES (:sid, :ra, :dec, :rot, :fovw, :fovh, :label, :no)"
    );
    // Next consecutive number within the project; retry on a lost race (see
    // projectCreateSetup for why the web container and the watcher collide here).
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $maxNo = $conn->prepare(
            "SELECT COALESCE(MAX(pp.panel_no), 0) FROM project_panels pp "
            . "JOIN project_setups ps ON ps.id = pp.setup_id "
            . "WHERE ps.project_id = (SELECT project_id FROM project_setups WHERE id = :sid)"
        );
        $maxNo->execute([':sid' => $setupId]);
        $nextNo = (int)$maxNo->fetchColumn() + 1;
        try {
            $ins->execute([
                ':sid' => $setupId, ':ra' => $ra, ':dec' => $dec, ':rot' => $rotVal,
                ':fovw' => $row['fov_w'], ':fovh' => $row['fov_h'], ':label' => $objLabel, ':no' => $nextNo,
            ]);
            return (int)$conn->lastInsertId();
        } catch (PDOException $e) {
            if (!projectRetryOnDuplicate($e, 'uq_project_panels_no')) {
                throw $e;
            }
        }
    }
    throw new PDOException("Could not allocate a free panel_no for setup {$setupId}");
}

function projectFindOrCreateSession(PDO $conn, int $panelId, string $night): int
{
    $sess = $conn->prepare("SELECT id FROM project_sessions WHERE panel_id = :panel AND astro_night = :night");
    $sess->execute([':panel' => $panelId, ':night' => $night]);
    $sessRow = $sess->fetch();
    if ($sessRow !== false) {
        return (int)$sessRow['id'];
    }
    $projectId = projectIdOfPanel($conn, $panelId);
    $maxNo = $conn->prepare(
        "SELECT COALESCE(MAX(ss.session_no), 0) FROM project_sessions ss "
        . "JOIN project_panels pp ON pp.id = ss.panel_id "
        . "JOIN project_setups ps ON ps.id = pp.setup_id WHERE ps.project_id = :pid"
    );
    $ins = $conn->prepare("INSERT INTO project_sessions (panel_id, astro_night, session_no) VALUES (:panel, :night, :no)");
    $maxNo->execute([':pid' => $projectId]);
    $nextNo = (int)$maxNo->fetchColumn() + 1;
    try {
        $ins->execute([':panel' => $panelId, ':night' => $night, ':no' => $nextNo]);
        // Read the id before the renumbering statement runs: lastInsertId()
        // tracks the last INSERT and is no longer meaningful afterwards.
        $newId = (int)$conn->lastInsertId();
    } catch (PDOException $e) {
        // The only unique key here is (panel_id, astro_night): a concurrent writer
        // created this exact session, and its row is the right answer.
        $sess->execute([':panel' => $panelId, ':night' => $night]);
        $again = $sess->fetch();
        if ($again !== false) {
            return (int)$again['id'];
        }
        throw $e;
    }
    projectRenumberSessionsByNight($conn, $projectId);
    return $newId;
}

function projectIdOfPanel(PDO $conn, int $panelId): int
{
    $q = $conn->prepare("SELECT ps.project_id FROM project_panels pp "
        . "JOIN project_setups ps ON ps.id = pp.setup_id WHERE pp.id = :pid");
    $q->execute([':pid' => $panelId]);
    return (int)$q->fetchColumn();
}

/**
 * Renumber every session of a project so session_no increases with the astro-night.
 *
 * The project tree renders sessions in night order and shows session_no as the N
 * prefix, so the two have to agree. A number cannot be picked correctly at insert
 * time: inserting a night older than existing ones would need to renumber rows
 * that are already there, and a MAX+1 counter gets it exactly backwards (N3 for the
 * oldest night of three). Since a project holds a handful of sessions, renumbering
 * the whole project after each insert is cheap and also repairs anything that
 * drifted.
 *
 * The tie-break on id keeps two panels of the same night on distinct numbers.
 */
function projectRenumberSessionsByNight(PDO $conn, int $projectId): void
{
    $conn->prepare(
        "UPDATE project_sessions ss "
        . "JOIN project_panels pp ON pp.id = ss.panel_id "
        . "JOIN project_setups ps ON ps.id = pp.setup_id "
        . "JOIN (SELECT ss2.id, ROW_NUMBER() OVER "
        . "(PARTITION BY ps2.project_id ORDER BY ss2.astro_night, ss2.id) AS rn "
        . "FROM project_sessions ss2 "
        . "JOIN project_panels pp2 ON pp2.id = ss2.panel_id "
        . "JOIN project_setups ps2 ON ps2.id = pp2.setup_id) t ON t.id = ss.id "
        . "SET ss.session_no = t.rn WHERE ps.project_id = :pid"
    )->execute([':pid' => $projectId]);
}

/**
 * First session id of an astro-night anywhere in a setup (panel-agnostic).
 * Null when absent. Read-only: never creates rows (flats must not sprout
 * panels or orphan sessions). Mirrors find_setup_session() in
 * docker/python/indexer_lib/projects.py.
 */
function projectFindSetupSession(PDO $conn, int $setupId, string $night): ?int
{
    $st = $conn->prepare(
        "SELECT ss.id FROM project_sessions ss "
        . "JOIN project_panels pp ON pp.id = ss.panel_id "
        . "WHERE pp.setup_id = :sid AND ss.astro_night = :night "
        . "ORDER BY pp.panel_no ASC, pp.id ASC, ss.id ASC LIMIT 1"
    );
    $st->execute([':sid' => $setupId, ':night' => $night]);
    $r = $st->fetch();
    return $r === false ? null : (int)$r['id'];
}

function getProjectSetups(PDO $conn, int $projectId): array
{
    $stmt = $conn->prepare("SELECT id, fingerprint, label, setup_no FROM project_setups WHERE project_id = :pid ORDER BY id ASC");
    $stmt->execute([':pid' => $projectId]);
    return $stmt->fetchAll();
}

/**
 * Dry-run match for one file: no writes. $project null = brand-new project
 * (everything flagged new). Returns plan array for preview and add paths.
 * $forcedSetupId pins the setup (batch group merge): validated against the
 * project, then panel/session matching runs under it like a table override.
 */
function projectMatchPlan(PDO $conn, ?array $project, array $row, array $tols, int $fid, ?int $forcedSetupId = null): array
{
    $pid = $project !== null ? (int)$project['id'] : 0;
    $fp = projectBuildFingerprint($row);
    $setupId = null;
    $setupNew = true;
    if ($forcedSetupId !== null && $forcedSetupId > 0
        && ($project === null || projectOwnsNode($conn, $pid, 'setup', $forcedSetupId))) {
        $setupId = $forcedSetupId;
        $setupNew = false;
    } elseif ($project !== null) {
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
    $night = projectAstroNight((string)$row['date_obs']);
    $isLight = strtoupper((string)$row['imgtype']) === 'LIGHT';
    $isFlat = strtoupper((string)$row['imgtype']) === 'FLAT';
    $filt = trim((string)($row['filter'] ?? ''));
    // Flats skip panel matching entirely (no RA/DEC/FoV/OBJECT check): they
    // belong to the night, not the target. The panel slot stays empty and is
    // never created (see projectAddFiles); the UI must not offer "New panel".
    $flatSessionId = null;
    if ($isFlat) {
        $panel['new'] = false;
        if ($setupId !== null && $night !== null) {
            $flatSessionId = projectFindSetupSession($conn, $setupId, $night);
        }
    } elseif ($setupId !== null) {
        $panel = projectFindPanel($conn, $setupId, $row, $tols);
    } else {
        [$ra, $dec] = projectPositionOf($row);
        $bucket = strtoupper(trim((string)preg_replace('/\s+/', ' ', (string)($row['object'] ?? ''))));
        $panel['ra'] = $ra;
        $panel['dec'] = $dec;
        $panel['bucket'] = $bucket !== '' ? $bucket : 'UNKNOWN';
    }
    return [
        'setup_id' => $setupId, 'setup_new' => $setupNew,
        'setup_fp' => $fp, 'setup_label' => projectSetupLabel($row),
        'panel' => $panel,
        'night' => $night,
        'flat_session_id' => $flatSessionId,
        // Lights under filter level; flats in the night's setup-wide session
        // (setup fallback when dateless or with no session that night);
        // darks/bias always at setup level.
        'level' => $isLight ? 'filter' : (($isFlat && $flatSessionId !== null) ? 'session' : 'setup'),
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
        // Dateless calibrations are still linkable (setup level): only lights
        // strictly require a date for their night session.
        $calNoDate = $row !== null && $skipReason === 'no_date'
            && strtoupper((string)$row['imgtype']) !== 'LIGHT';
        if ($row === null || ($skipReason !== null && !$calNoDate)) {
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
    // Duplicate-link warnings: files already linked elsewhere in this project
    // are still added, but flagged (⧉×n) instead of silently duplicated.
    $dupWhere = $project !== null ? projectPreviewDupWhere($conn, (int)$project['id'], $groups) : [];
    foreach ($groups as &$gref) {
        foreach ($gref['files'] as &$fref) {
            $fref['dup'] = $dupWhere[(int)$fref['id']] ?? [];
        }
        unset($fref);
    }
    unset($gref);
    return ['groups' => array_values($groups), 'skipped' => $skipped, 'panels' => $panelLabels];
}

/**
 * Existing link locations per file id, as human labels (setup S{n}, panel
 * P{n}, session {night}). Only files with at least one link are returned.
 */
function projectPreviewDupWhere(PDO $conn, int $projectId, array $groups): array
{
    $fids = [];
    foreach ($groups as $g) {
        foreach ($g['files'] as $f) {
            $fids[(int)$f['id']] = true;
        }
    }
    if (empty($fids)) {
        return [];
    }
    $in = implode(',', array_fill(0, count($fids), '?'));
    $lr = $conn->prepare(
        "SELECT file_id, level, node_id, filter_name FROM project_files WHERE file_id IN ($in)"
    );
    $lr->execute(array_keys($fids));
    $rows = $lr->fetchAll();
    if (empty($rows)) {
        return [];
    }
    $setups = [];
    foreach ($conn->query(
        "SELECT id, setup_no FROM project_setups WHERE project_id = " . (int)$projectId
    )->fetchAll() as $s) {
        $setups[(int)$s['id']] = 'setup S' . (int)($s['setup_no'] ?? $s['id']);
    }
    $panels = [];
    foreach ($conn->query(
        "SELECT pp.id, pp.panel_no FROM project_panels pp "
        . "JOIN project_setups ps ON ps.id = pp.setup_id WHERE ps.project_id = " . (int)$projectId
    )->fetchAll() as $p) {
        $panels[(int)$p['id']] = 'panel P' . (int)($p['panel_no'] ?? $p['id']);
    }
    $sessions = [];
    foreach ($conn->query(
        "SELECT ss.id, ss.astro_night FROM project_sessions ss "
        . "JOIN project_panels pp ON pp.id = ss.panel_id "
        . "JOIN project_setups ps ON ps.id = pp.setup_id WHERE ps.project_id = " . (int)$projectId
    )->fetchAll() as $s) {
        $sessions[(int)$s['id']] = 'session ' . (string)$s['astro_night'];
    }
    $out = [];
    foreach ($rows as $r) {
        $label = null;
        switch ($r['level']) {
            case 'project':
                $label = 'project';
                break;
            case 'setup':
                $label = $setups[(int)$r['node_id']] ?? 'setup #' . (int)$r['node_id'];
                break;
            case 'panel':
                $label = $panels[(int)$r['node_id']] ?? 'panel #' . (int)$r['node_id'];
                break;
            case 'session':
            case 'filter':
                $label = $sessions[(int)$r['node_id']] ?? 'session #' . (int)$r['node_id'];
                if ($r['level'] === 'filter' && trim((string)($r['filter_name'] ?? '')) !== '') {
                    $label .= ' · ' . trim((string)$r['filter_name']);
                }
                break;
        }
        if ($label !== null) {
            $out[(int)$r['file_id']][] = $label;
        }
    }
    foreach ($out as &$labels) {
        $labels = array_values(array_unique($labels));
    }
    unset($labels);
    return $out;
}

/**
 * Stable lights-first ordering for add batches (real and hypothetical).
 * Flats anchor to night sessions that lights create, so processing lights
 * first places calibrations where the user expects them. Homogeneous batches
 * are unaffected by construction. Shared by projectAddFiles() and the
 * hypothetical-tree preview so the two can never diverge on order.
 */
function orderAddIds(PDO $conn, array $fileIds): array
{
    $ids = array_values(array_filter(array_map('intval', $fileIds), fn($v) => $v > 0));
    if (count($ids) < 2) {
        return $ids;
    }
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $conn->prepare("SELECT id, imgtype FROM files WHERE id IN ($in)");
        $st->execute($ids);
        $types = [];
        foreach ($st->fetchAll() as $r) {
            $types[(int)$r['id']] = strtoupper((string)($r['imgtype'] ?? ''));
        }
    } catch (Exception $e) {
        return $ids;
    }
    $lights = [];
    $rest = [];
    foreach ($ids as $fid) {
        if (($types[$fid] ?? '') === 'LIGHT') {
            $lights[] = $fid;
        } else {
            $rest[] = $fid;
        }
    }
    return array_merge($lights, $rest);
}

/**
 * Parse an add-to-project request body shared by the real add endpoint and
 * the transactional tree preview: {ids, project_id, overrides, new_project}.
 * Overrides: {fileId: setupId | "new:Custom name"}. Returns
 * ['project_id' => int, 'ids' => int[], 'overrides' => [fid => sid],
 *  'customSetups' => [fid => name], 'groupFpOverrides' => [fid => fingerprint],
 *  'new_project' => ?['name','notes']].
 * A "groupfp:<fingerprint>" value merges the file into another batch group's
 * setup (created on demand in the same request, one-shot: never persisted to
 * setup_overrides).
 * Throws InvalidArgumentException on invalid input (endpoints map it to 400).
 */
function parseProjectAddRequest(array $data): array
{
    if (!isset($data['ids']) || !is_array($data['ids'])) {
        throw new InvalidArgumentException(
            'Invalid input. Required: ids (array of int), project_id (int) or new_project ({name, notes}).'
        );
    }
    $projectId = isset($data['project_id']) ? (int)$data['project_id'] : 0;
    // Dedupe: list + thumbnail views submit the same files twice.
    $ids = array_values(array_unique(array_filter($data['ids'], 'is_int')));
    // Cap batch size to keep the request bounded.
    $ids = array_slice($ids, 0, 2000);
    $overrides = [];
    $customSetups = [];
    $groupFpOverrides = [];
    if (isset($data['overrides']) && is_array($data['overrides'])) {
        foreach ($data['overrides'] as $fid => $sid) {
            if (!is_numeric($fid) || (int)$fid <= 0) {
                continue;
            }
            $fid = (int)$fid;
            if (is_string($sid) && str_starts_with($sid, 'groupfp:')) {
                $fp = substr(trim(substr($sid, 8)), 0, 512);
                if ($fp === '') {
                    continue;
                }
                $groupFpOverrides[$fid] = $fp;
            } elseif (is_string($sid) && str_starts_with($sid, 'new:')) {
                $name = substr(str_replace('|', ' ', trim(substr($sid, 4))), 0, 64);
                if ($name === '') {
                    continue;
                }
                $customSetups[$fid] = $name;
            } elseif (is_numeric($sid) && (int)$sid > 0) {
                $overrides[$fid] = (int)$sid;
            }
        }
    }
    $newProject = null;
    if (isset($data['new_project']) && is_array($data['new_project'])) {
        $name = substr(trim((string)($data['new_project']['name'] ?? '')), 0, 255);
        if ($name === '') {
            throw new InvalidArgumentException('Invalid input. new_project.name is required.');
        }
        $newProject = ['name' => $name, 'notes' => trim((string)($data['new_project']['notes'] ?? ''))];
    }
    if ($projectId <= 0 && $newProject === null) {
        throw new InvalidArgumentException('Invalid input. Provide project_id or new_project.');
    }
    return [
        'project_id' => $projectId,
        'ids' => $ids,
        'overrides' => $overrides,
        'customSetups' => $customSetups,
        'groupFpOverrides' => $groupFpOverrides,
        'new_project' => $newProject,
    ];
}

/**
 * i18n key for an add-to-project skip reason code (shared by the real add
 * and the tree preview so both label skips identically).
 */
function projectAddReasonKey(string $reason): string
{
    static $keys = [
        'no_project' => 'projects_add_reason_no_project',
        'not_found' => 'projects_add_reason_not_found',
        'imgtype' => 'projects_add_reason_imgtype',
        'forbidden' => 'projects_add_reason_forbidden',
        'no_date' => 'projects_add_reason_no_date',
        'already' => 'projects_add_reason_already',
        'error' => 'projects_add_reason_error',
        'frozen' => 'projects_add_reason_frozen',
    ];
    return $keys[$reason] ?? 'projects_add_reason_error';
}

/**
 * Shared prelude for adding files to a project (real add and transactional
 * tree preview): inline project creation, custom-setup creation with
 * duplicate-name blocking, and setup-override upserts. No file links here;
 * call projectAddFiles() afterwards. Returns
 * ['project_id' => int, 'ids' => int[], 'customSkipped' => [['fid','name','no']]].
 * Runs inside the caller's transaction when one is open (preview rolls back).
 */
function projectAddPrepare(PDO $conn, int $projectId, array $ids, array $overrides, array $customSetups, ?array $newProject): array
{
    // Frozen means locked. Checked here as well as in projectAddFiles(): this
    // function writes (project, custom setups, setup_overrides) and runs first,
    // so without the guard a frozen project would still be mutated by a request
    // that projectAddFiles() then reports as "added 0".
    if ($newProject === null) {
        $project = getProject($conn, $projectId);
        if ($project === null) {
            return ['project_id' => $projectId, 'ids' => [], 'customSkipped' => []];
        }
        if (getProjectAssignMode($project) === 'frozen') {
            return ['project_id' => $projectId, 'ids' => [], 'customSkipped' => [],
                'frozen' => true];
        }
    }
    if ($newProject !== null) {
        $projectId = createProject($conn, $newProject['name'], $newProject['notes']);
    }
    $customSkipped = [];
    if (!empty($customSetups)) {
        $fpRow = $conn->prepare(
            "SELECT id, name, instrume, telescop, cameraid, xbinning, ybinning, gain, `offset`, xpixsz FROM files WHERE id = :fid"
        );
        // Existing custom names in this project (case-insensitive): creating
        // a duplicate is blocked with a message instead of silently reusing.
        $existingCustoms = [];
        $cs = $conn->prepare(
            "SELECT id, setup_no, fingerprint FROM project_setups "
            . "WHERE project_id = :pid AND fingerprint LIKE '%|CUSTOM:%'"
        );
        $cs->execute([':pid' => $projectId]);
        foreach ($cs->fetchAll() as $srow) {
            $pos = strrpos((string)$srow['fingerprint'], '|CUSTOM:');
            if ($pos !== false) {
                $existingCustoms[mb_strtolower(trim(substr((string)$srow['fingerprint'], $pos + 8)))] = [
                    'id' => (int)$srow['id'],
                    'no' => $srow['setup_no'],
                ];
            }
        }
        // Only files actually being added can seed a custom setup.
        $allowed = array_flip($ids);
        $blockedFids = [];
        foreach ($customSetups as $fid => $name) {
            if (!isset($allowed[$fid])) {
                continue;
            }
            $lname = mb_strtolower($name);
            if (isset($existingCustoms[$lname])) {
                // 'name' => null reached the UI as the literal text "null": the add
                // response is rendered as `${s.name} (${s.message})`. Same fallback the
                // other skips use, so an unnamed file shows as #<id> like everywhere else.
                $customSkipped[] = ['fid' => $fid, 'name' => '#' . $fid, 'no' => $existingCustoms[$lname]['no']];
                $blockedFids[$fid] = true;
                continue;
            }
            $fpRow->execute([':fid' => $fid]);
            $frow = $fpRow->fetch();
            if ($frow === false) {
                continue;
            }
            $fp = projectBuildFingerprint($frow) . '|CUSTOM:' . $name;
            $exists = $conn->prepare(
                "SELECT id, setup_no FROM project_setups WHERE project_id = :pid AND fingerprint = :fp"
            );
            $exists->execute([':pid' => $projectId, ':fp' => $fp]);
            $exRow = $exists->fetch();
            if ($exRow !== false) {
                // Same-request duplicate (or exact race already committed):
                // blocked like a pre-existing name.
                $customSkipped[] = [
                    'fid' => $fid,
                    'name' => (string)($frow['name'] ?? ''),
                    'no' => $exRow['setup_no'],
                ];
                $blockedFids[$fid] = true;
                continue;
            }
            try {
                $sid = projectCreateSetup($conn, $projectId, $fp, $name);
            } catch (PDOException $e) {
                // Concurrent creation won the race: re-check, then block cleanly.
                $exists->execute([':pid' => $projectId, ':fp' => $fp]);
                $exRow = $exists->fetch();
                if ($exRow === false) {
                    throw $e;
                }
                $customSkipped[] = [
                    'fid' => $fid,
                    'name' => (string)($frow['name'] ?? ''),
                    'no' => $exRow['setup_no'],
                ];
                $blockedFids[$fid] = true;
                continue;
            }
            $existingCustoms[$lname] = ['id' => $sid, 'no' => null];
            // Fetch the fresh setup_no for a potential later message.
            $norow = $conn->prepare("SELECT setup_no FROM project_setups WHERE id = :sid");
            $norow->execute([':sid' => $sid]);
            $existingCustoms[$lname]['no'] = $norow->fetchColumn();
            $overrides[$fid] = $sid;
        }
        if (!empty($blockedFids)) {
            $ids = array_values(array_filter($ids, fn($id) => !isset($blockedFids[$id])));
            // Resolve file names for blocked entries missing them.
            $nm = $conn->prepare("SELECT name FROM files WHERE id = :fid");
            foreach ($customSkipped as &$csk) {
                if ($csk['name'] === null) {
                    $nm->execute([':fid' => $csk['fid']]);
                    $nrow = $nm->fetch();
                    $csk['name'] = $nrow !== false ? (string)$nrow['name'] : '#' . $csk['fid'];
                }
            }
            unset($csk);
        }
    }
    if (!empty($overrides)) {
        $check = $conn->prepare("SELECT id FROM project_setups WHERE id = :sid AND project_id = :pid");
        $upsert = $conn->prepare(
            "INSERT INTO setup_overrides (file_id, setup_id) VALUES (:fid, :sid) "
            . "ON DUPLICATE KEY UPDATE setup_id = VALUES(setup_id)"
        );
        // Only files actually being added can carry an override.
        $allowed = array_flip($ids);
        foreach ($overrides as $fid => $sid) {
            if (!isset($allowed[$fid])) {
                continue;
            }
            $check->execute([':sid' => $sid, ':pid' => $projectId]);
            if ($check->fetch() === false) {
                continue; // setup from another project: ignore silently
            }
            $upsert->execute([':fid' => $fid, ':sid' => $sid]);
        }
    }
    return ['project_id' => $projectId, 'ids' => array_values($ids), 'customSkipped' => $customSkipped];
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
 *
 * This docblock used to document projectNormPart(), two hundred lines up: the helper had
 * been inserted between the comment and its function, so projectAddFiles() had no
 * documentation at all and this helper was described as the matcher.
 */
function projectAddFiles(PDO $conn, int $projectId, array $fileIds, array $groupFpOverrides = []): array
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
    // Batch group merge ("groupfp:<fingerprint>" overrides, one-shot, never
    // persisted): fingerprint carried by every batch file + display label per
    // fingerprint (first carrier wins). A merge target counts only when some
    // batch file actually carries it; otherwise it is ignored and the file
    // follows normal matching (same as a foreign-setup override).
    $batchFp = [];
    $batchLabelByFp = [];
    if (!empty($groupFpOverrides)) {
        try {
            $allIds = array_values(array_unique(array_map('intval', $fileIds)));
            $allIds = array_values(array_filter($allIds, fn($v) => $v > 0));
            if (!empty($allIds)) {
                $in = implode(',', array_fill(0, count($allIds), '?'));
                $st = $conn->prepare(
                    "SELECT id, instrume, telescop, cameraid, xbinning, ybinning, gain, `offset`, xpixsz "
                    . "FROM files WHERE id IN ($in)"
                );
                $st->execute($allIds);
                foreach ($st->fetchAll() as $r) {
                    $fp = projectBuildFingerprint($r);
                    $batchFp[(int)$r['id']] = $fp;
                    if (!isset($batchLabelByFp[$fp])) {
                        $batchLabelByFp[$fp] = projectSetupLabel($r);
                    }
                }
            }
        } catch (Exception $e) {
            $batchFp = [];
            $batchLabelByFp = [];
        }
    }
    // fp => target fp for carriers (chains resolve transitively below).
    $fpMergeTo = [];
    foreach ($groupFpOverrides as $gfid => $t) {
        $gfid = (int)$gfid;
        if (isset($batchFp[$gfid]) && is_string($t) && $t !== '') {
            $fpMergeTo[$batchFp[$gfid]] = $t;
        }
    }
    // Effective merge target for a fingerprint, or null for normal matching:
    // unknown targets and cycles fall back to the file's own fingerprint.
    $resolveMerge = function (string $fp) use ($fpMergeTo, $batchLabelByFp): ?string {
        if (!isset($fpMergeTo[$fp])) {
            return null;
        }
        $seen = [$fp => true];
        $cur = $fpMergeTo[$fp];
        while (isset($fpMergeTo[$cur])) {
            if (!isset($batchLabelByFp[$cur])) {
                return null;
            }
            $cur = $fpMergeTo[$cur];
            if (isset($seen[$cur])) {
                return null;
            }
            $seen[$cur] = true;
        }
        if (!isset($batchLabelByFp[$cur]) || $cur === $fp) {
            return null;
        }
        return $cur;
    };
    $fpSetupMap = []; // effective target fp => setupId (created or found in-request)
    $createdPanel = false;
    $createdSetup = false;
    // Nestable: the transactional tree preview runs this whole function
    // inside an outer transaction (rolled back afterwards). Per-file atomicity
    // then comes from savepoints instead of real transactions.
    $outerTx = $conn->inTransaction();
    $sp = function (string $action) use ($conn): void {
        $conn->exec($action === 'begin' ? 'SAVEPOINT hypo_add_file'
            : ($action === 'release' ? 'RELEASE SAVEPOINT hypo_add_file'
            : 'ROLLBACK TO SAVEPOINT hypo_add_file'));
    };
    foreach (orderAddIds($conn, $fileIds) as $fid) {
        $fid = (int)$fid;
        if ($fid <= 0) {
            continue;
        }
        [$row, $skipReason] = projectFetchEligibleRow($conn, $fid);
        // See projectPreviewFiles: dateless calibrations fall back to setup level.
        $calNoDate = $row !== null && $skipReason === 'no_date'
            && strtoupper((string)$row['imgtype']) !== 'LIGHT';
        if ($row === null || ($skipReason !== null && !$calNoDate)) {
            $skipped[] = ['name' => $row !== null ? (string)$row['name'] : '#' . $fid, 'reason' => $skipReason ?? 'not_found'];
            continue;
        }

        if ($outerTx) {
            $sp('begin');
        } else {
            $conn->beginTransaction();
        }
        try {
            // Batch group merge: pin this file to its effective target setup.
            // The setup is found or created once per fingerprint inside this
            // same per-file transaction, so failed files leave nothing behind
            // and later same-fp files match it normally.
            $forcedSid = null;
            if (!empty($groupFpOverrides) && isset($batchFp[$fid])) {
                $effTarget = $resolveMerge($batchFp[$fid]);
                if ($effTarget !== null) {
                    if (!isset($fpSetupMap[$effTarget])) {
                        $foundTarget = projectFindSetup($conn, $projectId, $effTarget);
                        if ($foundTarget !== null) {
                            $fpSetupMap[$effTarget] = $foundTarget;
                        } else {
                            $fpSetupMap[$effTarget] = projectCreateSetup(
                                $conn, $projectId, $effTarget, $batchLabelByFp[$effTarget] ?? null
                            );
                            $createdSetup = true;
                        }
                    }
                    $forcedSid = $fpSetupMap[$effTarget];
                }
            }
            $plan = projectMatchPlan($conn, $project, $row, $tols, $fid, $forcedSid);
            $isFlatRow = strtoupper((string)$row['imgtype']) === 'FLAT';
            // Create missing setup/panel (session/filter buckets are always created).
            // Flats never create panels: no coordinates, no panel.
            if ($plan['setup_id'] === null) {
                $setupId = projectCreateSetup($conn, $projectId, $plan['setup_fp'], $plan['setup_label']);
                $createdSetup = true;
            } else {
                $setupId = $plan['setup_id'];
            }

            // Panel: matched by the plan, else create (never for flats).
            if ($plan['panel']['id'] === null && !$isFlatRow) {
                $panelId = projectCreatePanel(
                    $conn, $setupId, $row,
                    $plan['panel']['bucket'], $plan['panel']['ra'], $plan['panel']['dec']
                );
                $createdPanel = true;
            } else {
                $panelId = $plan['panel']['id'];
            }

            // Link level comes from the shared match plan (never diverges from
            // preview): lights under filter level, flats in the night's
            // setup-wide session (setup fallback when dateless or with no
            // session that night), darks/bias at setup level.
            // Sessions are plain time buckets, created on demand (never for flats).
            $isLight = strtoupper((string)$row['imgtype']) === 'LIGHT';
            $filt = trim((string)($row['filter'] ?? ''));
            if ($plan['level'] === 'filter') {
                $sessionId = projectFindOrCreateSession($conn, (int)$panelId, (string)$plan['night']);
                $level = 'filter';
                $node = $sessionId;
                $filterName = $filt !== '' ? $filt : null;
            } elseif ($plan['level'] === 'session') {
                if ($isFlatRow) {
                    if (($plan['flat_session_id'] ?? null) !== null
                        && projectOwnsNode($conn, $projectId, 'session', (int)$plan['flat_session_id'])) {
                        $level = 'session';
                        $node = (int)$plan['flat_session_id'];
                        $filterName = $filt !== '' ? $filt : null;
                    } else {
                        // Planned session vanished (or belongs elsewhere):
                        // fall back to setup rather than creating rows.
                        $level = 'setup';
                        $node = $setupId;
                        $filterName = $filt !== '' ? $filt : null;
                    }
                } else {
                    $sessionId = projectFindOrCreateSession($conn, (int)$panelId, (string)$plan['night']);
                    $level = 'session';
                    $node = $sessionId;
                    $filterName = $filt !== '' ? $filt : null;
                }
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
                if ($outerTx) {
                    $sp('rollback');
                } else {
                    $conn->rollBack();
                }
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
            if ($outerTx) {
                $sp('release');
            } else {
                $conn->commit();
            }
            $added++;
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                if ($outerTx) {
                    $sp('rollback');
                } else {
                    $conn->rollBack();
                }
            }
            $skipped[] = ['name' => (string)($row['name'] ?? "#$fid"), 'reason' => 'error'];
        }
    }
    // A brand-new setup/panel changes the matching context: never-suggested
    // archive files may match it now. Queue one async backfill pass for the
    // project (coalesced, skipped in frozen/manual inside the helper).
    if ($createdPanel) {
        enqueueSuggestRequest($conn, $projectId, 'panel_created');
    } elseif ($createdSetup) {
        enqueueSuggestRequest($conn, $projectId, 'setup_created');
    }
    return ['added' => $added, 'skipped' => $skipped];
}

/**
 * Stored rejection thresholds per integration group.
 *
 * Direction is fixed per metric type (lower-is-better excludes above,
 * higher-is-better excludes below); NULL value = metric inactive. A light is
 * effectively included iff manually enabled AND passing the stored
 * thresholds, so thresholds alone decide without forcing per-file state.
 */
function groupThresholdDirs(): array
{
    return [
        'hfr' => 'above',
        'fwhm' => 'above',
        'hfr_sd' => 'above',
        'eccentricity' => 'above',
        'star_count' => 'below',
        'snr_weight' => 'below',
        'psf_signal' => 'below',
    ];
}

function thresholdDbColumn(string $metric): ?string
{
    return [
        'hfr' => 'hfr_max',
        'fwhm' => 'fwhm_max',
        'hfr_sd' => 'hfrsd_max',
        'eccentricity' => 'ecc_max',
        'star_count' => 'stars_min',
        'snr_weight' => 'snr_min',
        'psf_signal' => 'psf_min',
    ][$metric] ?? null;
}

function groupThresholdKey(int $setupId, int $panelId, ?string $filter, $exptime): string
{
    $f = ($filter !== null && $filter !== '') ? $filter : '';
    $e = ($exptime !== null && $exptime !== '') ? number_format(round((float)$exptime, 3), 3, '.', '') : '';
    return $setupId . '|' . $panelId . '|' . $f . '|' . $e;
}

/**
 * All stored thresholds of a project, keyed by groupThresholdKey().
 * Values are [metric => float|null].
 */
function getProjectThresholds(PDO $conn, int $projectId): array
{
    // Ordering is irrelevant while the invariant holds (one row per group), but it is
    // stated rather than left to the engine: keys are assigned, not merged, so with a
    // duplicate present the row that wins would be whichever one storage returned
    // last. DESC makes that the most recent write.
    $stmt = $conn->prepare("SELECT * FROM project_group_thresholds WHERE project_id = :pid ORDER BY id DESC");
    $stmt->execute([':pid' => $projectId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $tols = [];
        foreach (groupThresholdDirs() as $metric => $dir) {
            $col = thresholdDbColumn($metric);
            $tols[$metric] = ($col !== null && $row[$col] !== null) ? (float)$row[$col] : null;
        }
        $key = groupThresholdKey(
            (int)$row['setup_id'],
            (int)$row['panel_id'],
            $row['filter_name'],
            $row['exptime']
        );
        $out[$key] = $tols;
    }
    return $out;
}

/**
 * Store a group's thresholds; all-null removes the row (no active rules).
 * Values: [metric => float|string|null]; localizes to columns.
 *
 * DELETE + INSERT, not INSERT ... ON DUPLICATE KEY UPDATE. The unique key is
 * (project_id, setup_id, panel_id, filter_name, exptime) and the last two columns
 * are nullable: in MySQL/MariaDB a UNIQUE constraint does not consider two NULLs
 * equal, so any NULL in the tuple lets a second row through and the upsert never
 * fires. Saving a group with no filter anchor or no exposure anchor therefore
 * appended a row instead of updating, on every save. The read above keys by group
 * and assigns rather than merges, so the duplicates then decided non-deterministically
 * which thresholds applied.
 *
 * The DELETE matches NULLs explicitly, since `filter_name = NULL` is never true.
 * The DB key is now genuinely enforced (see FixGroupThresholdsUniqueKey), so this
 * path does not depend on an upsert firing.
 */
function saveGroupThresholds(PDO $conn, int $projectId, int $setupId, int $panelId, ?string $filter, $exptime, array $values): void
{
    if (!projectOwnsNode($conn, $projectId, 'setup', $setupId)
        || !projectOwnsNode($conn, $projectId, 'panel', $panelId)) {
        throw new InvalidArgumentException('Invalid group nodes');
    }
    // Threshold keys use the canonical filter so aliased names share one row.
    $canon = canonFilterName(getProjectFilterAliases($conn, $projectId), $filter);
    $filter = $canon !== '' ? substr($canon, 0, 50) : null;
    $exp = ($exptime !== null && $exptime !== '') ? round((float)$exptime, 3) : null;
    $cols = [];
    $anyAction = false;
    foreach (groupThresholdDirs() as $metric => $dir) {
        $raw = $values[$metric] ?? null;
        $val = ($raw !== null && $raw !== '' && is_numeric($raw)) ? (float)$raw : null;
        $cols[thresholdDbColumn($metric)] = $val;
        if ($val !== null) {
            $anyAction = true;
        }
    }

    $owns = $conn->inTransaction();
    if (!$owns) {
        $conn->beginTransaction();
    }
    try {
        $del = $conn->prepare(
            "DELETE FROM project_group_thresholds WHERE project_id = :pid AND setup_id = :sid "
            . "AND panel_id = :panel AND ((filter_name = :filter) OR (filter_name IS NULL AND :filter2 IS NULL)) "
            . "AND ((exptime = :exp) OR (exptime IS NULL AND :exp2 IS NULL))"
        );
        $del->execute([
            ':pid' => $projectId, ':sid' => $setupId, ':panel' => $panelId,
            ':filter' => $filter, ':filter2' => $filter, ':exp' => $exp, ':exp2' => $exp,
        ]);
        if ($anyAction) {
            $ins = $conn->prepare(
                "INSERT INTO project_group_thresholds "
                . "(project_id, setup_id, panel_id, filter_name, exptime, hfr_max, fwhm_max, hfrsd_max, ecc_max, stars_min, snr_min, psf_min) "
                . "VALUES (:pid, :sid, :panel, :filter, :exp, :hfr, :fwhm, :hfrsd, :ecc, :stars, :snr, :psf)"
            );
            $ins->execute([
                ':pid' => $projectId, ':sid' => $setupId, ':panel' => $panelId,
                ':filter' => $filter, ':exp' => $exp,
                ':hfr' => $cols['hfr_max'], ':fwhm' => $cols['fwhm_max'], ':hfrsd' => $cols['hfrsd_max'],
                ':ecc' => $cols['ecc_max'], ':stars' => $cols['stars_min'], ':snr' => $cols['snr_min'],
                ':psf' => $cols['psf_min'],
            ]);
        }
        if (!$owns) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if (!$owns && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

/**
 * Whether a light fails stored thresholds (fixed per-metric direction).
 * Files missing a metric are never rejected by it.
 */
function lightThresholdRejected(array $light, array $tols): bool
{
    foreach (groupThresholdDirs() as $metric => $dir) {
        $t = $tols[$metric] ?? null;
        if ($t === null) {
            continue;
        }
        $v = $light[$metric] ?? null;
        if ($v === null || $v === '') {
            continue;
        }
        $v = (float)$v;
        if ($dir === 'above' ? $v >= (float)$t : $v <= (float)$t) {
            return true;
        }
    }
    return false;
}

/**
 * Integration-group split criteria for a project. Missing row (table absent
 * on old DBs, or never saved) => historical defaults: everything ON except
 * temperature, no tolerance overrides (NULL = inherit tol_exp/tol_temp).
 */
function defaultProjectGrouping(): array
{
    return [
        'split_setup' => true,
        'split_panel' => true,
        'split_filter' => true,
        'split_exposure' => true,
        'split_temp' => false,
        'merge_tiles' => false,
        'exp_tol' => null,
        'temp_tol' => null,
    ];
}

function getProjectGrouping(PDO $conn, int $projectId): array
{
    $out = defaultProjectGrouping();
    try {
        $stmt = $conn->prepare("SELECT * FROM project_grouping WHERE project_id = :pid");
        $stmt->execute([':pid' => $projectId]);
        $row = $stmt->fetch();
    } catch (Exception $e) {
        return $out;
    }
    if ($row === false) {
        return $out;
    }
    foreach (['split_setup', 'split_panel', 'split_filter', 'split_exposure', 'split_temp', 'merge_tiles'] as $k) {
        if (array_key_exists($k, $row)) {
            $out[$k] = (bool)$row[$k];
        }
    }
    foreach (['exp_tol', 'temp_tol'] as $k) {
        if (array_key_exists($k, $row)) {
            $v = trim((string)($row[$k] ?? ''));
            $out[$k] = $v !== '' ? substr($v, 0, 64) : null;
        }
    }
    return $out;
}

/**
 * Whether cross-setup tile merging actually applies: merge_tiles ON with
 * setup split OFF and panel split ON. Any other combination ignores the
 * flag (with panel split OFF everything merges anyway; with setup split ON
 * there is nothing cross-setup to merge).
 */
function groupingTilesEffective(array $g): bool
{
    return !empty($g['merge_tiles']) && empty($g['split_setup']) && !empty($g['split_panel']);
}

/**
 * Upsert grouping criteria. Checkbox-style values: truthy = ON.
 * Tolerance strings are validated loosely (empty = inherit); hard failures
 * come from the parsers at grouping time, never here.
 */
function saveProjectGrouping(PDO $conn, int $projectId, array $values): void
{
    if (getProject($conn, $projectId) === null) {
        throw new InvalidArgumentException('Invalid project');
    }
    $g = defaultProjectGrouping();
    foreach (['split_setup', 'split_panel', 'split_filter', 'split_exposure', 'split_temp', 'merge_tiles'] as $k) {
        if (array_key_exists($k, $values)) {
            $g[$k] = !empty($values[$k]);
        }
    }
    foreach (['exp_tol', 'temp_tol'] as $k) {
        $raw = trim((string)($values[$k] ?? ''));
        $g[$k] = $raw !== '' ? substr($raw, 0, 64) : null;
    }
    $conn->prepare(
        "INSERT INTO project_grouping "
        . "(project_id, split_setup, split_panel, split_filter, split_exposure, split_temp, merge_tiles, exp_tol, temp_tol) "
        . "VALUES (:pid, :ss, :sp, :sf, :se, :st, :mt, :et, :tt) "
        . "ON DUPLICATE KEY UPDATE split_setup = VALUES(split_setup), split_panel = VALUES(split_panel), "
        . "split_filter = VALUES(split_filter), split_exposure = VALUES(split_exposure), "
        . "split_temp = VALUES(split_temp), merge_tiles = VALUES(merge_tiles), "
        . "exp_tol = VALUES(exp_tol), temp_tol = VALUES(temp_tol)"
    )->execute([
        ':pid' => $projectId,
        ':ss' => $g['split_setup'] ? 1 : 0,
        ':sp' => $g['split_panel'] ? 1 : 0,
        ':sf' => $g['split_filter'] ? 1 : 0,
        ':se' => $g['split_exposure'] ? 1 : 0,
        ':st' => $g['split_temp'] ? 1 : 0,
        ':mt' => $g['merge_tiles'] ? 1 : 0,
        ':et' => $g['exp_tol'],
        ':tt' => $g['temp_tol'],
    ]);
}

/**
 * Per-project filter alias map: [lowercase alias => canonical name].
 * Always queried fresh (tiny indexed lookup); callers load it once per
 * request and thread it through the pure canonicalization helper below.
 */
function getProjectFilterAliases(PDO $conn, int $projectId): array
{
    $out = [];
    try {
        $stmt = $conn->prepare("SELECT alias, canonical FROM project_filter_aliases WHERE project_id = :pid");
        $stmt->execute([':pid' => $projectId]);
        $rows = $stmt->fetchAll();
    } catch (Exception $e) {
        return $out;
    }
    if (!is_array($rows)) {
        return $out;
    }
    foreach ($rows as $row) {
        $alias = trim((string)($row['alias'] ?? ''));
        $canon = trim((string)($row['canonical'] ?? ''));
        if ($alias !== '' && $canon !== '') {
            $out[mb_strtolower($alias)] = $canon;
        }
    }
    return $out;
}

/**
 * Canonical filter name for display-independent identity: trimmed raw name
 * unless an alias maps it (case-insensitive) to a canonical name. Empty
 * stays empty (the "no filter" group).
 */
function canonFilterName(array $aliasMap, ?string $name): string
{
    $trimmed = trim((string)($name ?? ''));
    if ($trimmed === '') {
        return '';
    }
    return $aliasMap[mb_strtolower($trimmed)] ?? $trimmed;
}

/**
 * Upsert per-project filter aliases from [rawAlias => rawCanonical] pairs.
 * Empty canonical deletes the mapping; alias equal to its canonical
 * (case-insensitive) is a no-op delete. Canonicals that are themselves
 * aliases are rejected (no chains, single-hop resolution only). Matching is
 * always case-insensitive regardless of DB collation.
 *
 * Two passes: every pair is validated against an in-memory view of the alias map
 * before anything is written, and the writes then go out in one transaction. The
 * loop-reject used to throw from the middle of the writing, so a loop discovered on
 * the fourth pair left the first three committed and the caller reported a rollback
 * that never happened. The in-memory view is mutated during pass one exactly as the
 * DB would have been, so same-request chains are still caught.
 */
function saveProjectFilterAliases(PDO $conn, int $projectId, array $pairs): void
{
    if (getProject($conn, $projectId) === null) {
        throw new InvalidArgumentException('Invalid project');
    }
    $existing = getProjectFilterAliases($conn, $projectId);
    $canonValues = function () use (&$existing): array {
        $out = [];
        foreach ($existing as $canon) {
            $out[mb_strtolower($canon)] = true;
        }
        return $out;
    };

    // Pass one: decide what to delete and what to insert, or refuse the lot.
    $deletes = [];
    $inserts = [];
    foreach ($pairs as $rawAlias => $rawCanon) {
        $alias = substr(trim((string)$rawAlias), 0, 50);
        $canon = substr(trim((string)$rawCanon), 0, 50);
        if ($alias === '') {
            continue;
        }
        $aliasLower = mb_strtolower($alias);
        $deletes[] = $alias;
        // Refresh existing view as we go (same-request chains stay rejected).
        if ($canon === '' || $aliasLower === mb_strtolower($canon)) {
            unset($existing[$aliasLower]);
            continue;
        }
        $canonLower = mb_strtolower($canon);
        if (isset($existing[$canonLower]) || isset($canonValues()[$aliasLower])) {
            throw new InvalidArgumentException(__('projects_filter_aliases_error_loop', ['name' => $canon]));
        }
        $inserts[] = ['alias' => $alias, 'canon' => $canon];
        $existing[$aliasLower] = $canon;
    }
    if ($deletes === [] && $inserts === []) {
        return;
    }

    // Pass two: apply. In one transaction, so a reader never sees an alias removed
    // and not yet re-inserted, and so the whole set lands or none of it does.
    $owns = $conn->inTransaction();
    if (!$owns) {
        $conn->beginTransaction();
    }
    try {
        $del = $conn->prepare(
            "DELETE FROM project_filter_aliases WHERE project_id = :pid AND LOWER(alias) = LOWER(:alias)"
        );
        $ins = $conn->prepare(
            "INSERT INTO project_filter_aliases (project_id, alias, canonical) VALUES (:pid, :alias, :canon)"
        );
        foreach ($deletes as $alias) {
            $del->execute([':pid' => $projectId, ':alias' => $alias]);
        }
        foreach ($inserts as $pair) {
            $ins->execute([':pid' => $projectId, ':alias' => $pair['alias'], ':canon' => $pair['canon']]);
        }
        if (!$owns) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if (!$owns && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}