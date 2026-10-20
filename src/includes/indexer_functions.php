<?php

declare(strict_types=1);

/**
 * Reading side of the indexer status channel.
 *
 * reindex.py and watch_fs.py write indexer_runs / indexer_watcher; this file
 * turns those rows into the small payload the status card and the API use.
 * Everything here is read-only and tolerant of an un-migrated database: the
 * card simply disappears instead of turning the home page into an error.
 *
 * Ages and elapsed times are computed by MariaDB (TIMESTAMPDIFF) against the
 * same NOW() that wrote the rows, so a PHP or browser timezone cannot make a
 * live run look minutes old.
 */

define('AWI_INDEXER_HISTORY', 5);

// A run with no heartbeat for five minutes is shown as stalled. The indexer
// warns above 60 s for a single frame, so this is deliberately generous.
define('AWI_INDEXER_STALE_SECONDS', 300);

// The watcher writes a heartbeat every 10 s and can block for minutes inside a
// project-suggestion backfill; two minutes of silence while "idle" is a fault.
define('AWI_INDEXER_WATCHER_STALE_SECONDS', 120);

/**
 * Whether the status tables exist yet.
 *
 * The migration is the only writer of the schema; a web container running
 * against an older database must keep working, not print warnings.
 */
function awiIndexerStatusAvailable(PDO $conn): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    try {
        $conn->query('SELECT 1 FROM indexer_runs LIMIT 1');
        $conn->query('SELECT 1 FROM indexer_watcher LIMIT 1');
        $available = true;
    } catch (PDOException $e) {
        $available = false;
    }
    return $available;
}

/**
 * Raw run rows plus the computed ages and rates the UI needs.
 *
 * @return list<array<string, mixed>>
 */
function getIndexerRuns(PDO $conn, int $limit = AWI_INDEXER_HISTORY, bool $includeError = true): array
{
    if (!awiIndexerStatusAvailable($conn)) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    try {
        $stmt = $conn->query(
            "SELECT *, "
            . "TIMESTAMPDIFF(SECOND, started_at, COALESCE(finished_at, NOW())) AS elapsed_seconds, "
            . "TIMESTAMPDIFF(SECOND, started_at, NOW()) AS started_age, "
            . "TIMESTAMPDIFF(SECOND, finished_at, NOW()) AS finished_age, "
            . "TIMESTAMPDIFF(SECOND, COALESCE(phase_started_at, started_at), NOW()) AS phase_elapsed, "
            . "TIMESTAMPDIFF(SECOND, COALESCE(heartbeat_at, started_at), NOW()) AS heartbeat_age "
            . "FROM indexer_runs WHERE status <> 'running' ORDER BY id DESC LIMIT {$limit}"
        );
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
    return array_map(
        static fn(array $row): array => awiDecorateIndexerRun($row, $includeError),
        $rows
    );
}

/**
 * The current run, the last finished runs, the watcher and the pending-metrics
 * count in one payload.
 *
 * @return array<string, mixed>
 */
function getIndexerStatus(PDO $conn, bool $includeError = true): array
{
    if (!awiIndexerStatusAvailable($conn)) {
        return ['available' => false];
    }

    $history = getIndexerRuns($conn, AWI_INDEXER_HISTORY, $includeError);

    $current = null;
    try {
        $stmt = $conn->query(
            "SELECT *, "
            . "TIMESTAMPDIFF(SECOND, started_at, COALESCE(finished_at, NOW())) AS elapsed_seconds, "
            . "TIMESTAMPDIFF(SECOND, started_at, NOW()) AS started_age, "
            . "TIMESTAMPDIFF(SECOND, finished_at, NOW()) AS finished_age, "
            . "TIMESTAMPDIFF(SECOND, COALESCE(phase_started_at, started_at), NOW()) AS phase_elapsed, "
            . "TIMESTAMPDIFF(SECOND, COALESCE(heartbeat_at, started_at), NOW()) AS heartbeat_age "
            . "FROM indexer_runs WHERE status = 'running' ORDER BY id DESC LIMIT 1"
        );
        $row = $stmt->fetch();
        if ($row !== false) {
            $current = awiDecorateIndexerRun($row, $includeError);
        }
    } catch (PDOException $e) {
        $current = null;
    }

    $watcher = null;
    try {
        $stmt = $conn->query(
            "SELECT *, "
            . "TIMESTAMPDIFF(SECOND, seen_at, NOW()) AS seen_age, "
            . "TIMESTAMPDIFF(SECOND, last_scan_at, NOW()) AS scan_age, "
            . "TIMESTAMPDIFF(SECOND, started_at, NOW()) AS uptime_seconds "
            . "FROM indexer_watcher WHERE id = 1"
        );
        $row = $stmt->fetch();
        if ($row !== false) {
            $watcher = awiDecorateIndexerWatcher($row);
        }
    } catch (PDOException $e) {
        $watcher = null;
    }

    // The only whole-archive count the card needs. idx_metrics_pending keeps it
    // from walking the files table on every five-second poll.
    $metricsPending = null;
    try {
        $metricsPending = (int)$conn->query(
            'SELECT COUNT(*) FROM files WHERE metrics_pending = 1 AND deleted_at IS NULL'
        )->fetchColumn();
    } catch (PDOException $e) {
        $metricsPending = null;
    }

    return [
        'available' => true,
        'current' => $current,
        'last' => $history[0] ?? null,
        'history' => $history,
        'watcher' => $watcher,
        'archive' => [
            'metrics_pending' => $metricsPending,
        ],
    ];
}

/** @param array<string, mixed> $row */
function awiDecorateIndexerRun(array $row, bool $includeError): array
{
    $status = (string)($row['status'] ?? 'unknown');
    $phase = (string)($row['phase'] ?? 'starting');
    $phaseTotal = (int)($row['phase_total'] ?? 0);
    $phaseDone = (int)($row['phase_done'] ?? 0);
    $phaseElapsed = isset($row['phase_elapsed']) ? (int)$row['phase_elapsed'] : null;
    $heartbeatAge = isset($row['heartbeat_age']) ? (int)$row['heartbeat_age'] : null;

    $percent = null;
    if ($phaseTotal > 0) {
        $percent = min(100.0, round($phaseDone / $phaseTotal * 100, 1));
    } elseif ($status === 'done') {
        $percent = 100.0;
    }

    $eta = null;
    $rate = null;
    if ($status === 'running' && $phaseElapsed !== null && $phaseElapsed >= 5 && $phaseDone >= 2) {
        $rate = round($phaseDone / $phaseElapsed * 60, 1);
        if ($phaseTotal > $phaseDone) {
            $eta = (int)round($phaseElapsed / $phaseDone * ($phaseTotal - $phaseDone));
        }
    }

    $config = null;
    if (!empty($row['config'])) {
        $decoded = json_decode((string)$row['config'], true);
        $config = is_array($decoded) ? $decoded : null;
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'status' => $status,
        'status_label' => awiIndexerStatusLabel($status),
        'reason' => (string)($row['reason'] ?? ''),
        'reason_label' => awiIndexerReasonLabel((string)($row['reason'] ?? '')),
        'phase' => $phase,
        'phase_label' => awiIndexerPhaseLabel($phase),
        'phase_mode' => $row['phase_mode'] ?? null,
        'started_at' => $row['started_at'] ?? null,
        'finished_at' => $row['finished_at'] ?? null,
        'started_age' => isset($row['started_age']) ? (int)$row['started_age'] : null,
        'finished_age' => isset($row['finished_age']) && $row['finished_age'] !== null
            ? (int)$row['finished_age'] : null,
        'elapsed_seconds' => isset($row['elapsed_seconds']) ? (int)$row['elapsed_seconds'] : null,
        'phase_elapsed_seconds' => $phaseElapsed,
        'heartbeat_age' => $heartbeatAge,
        'stalled' => $status === 'running' && $heartbeatAge !== null
            && $heartbeatAge > AWI_INDEXER_STALE_SECONDS,
        'phase_done' => $phaseDone,
        'phase_total' => $phaseTotal,
        'percent' => $percent,
        'eta_seconds' => $eta,
        'rate_per_minute' => $rate,
        'files_scanned' => (int)($row['files_scanned'] ?? 0),
        'db_files' => (int)($row['db_files'] ?? 0),
        'skipped' => (int)($row['skipped'] ?? 0),
        'files_total' => (int)($row['files_total'] ?? 0),
        'files_done' => (int)($row['files_done'] ?? 0),
        'metrics_total' => (int)($row['metrics_total'] ?? 0),
        'metrics_done' => (int)($row['metrics_done'] ?? 0),
        'error_count' => (int)($row['error_count'] ?? 0),
        // Paths in worker errors are admin-only: this is the same information
        // the file table already shows to an unrestricted user, not to a
        // directory-restricted one.
        'last_error' => $includeError && !empty($row['last_error']) ? (string)$row['last_error'] : null,
        'config' => $config,
    ];
}

/** @param array<string, mixed> $row */
function awiDecorateIndexerWatcher(array $row): array
{
    $state = (string)($row['state'] ?? 'unknown');
    $seenAge = isset($row['seen_age']) ? (int)$row['seen_age'] : null;
    $config = null;
    if (!empty($row['config'])) {
        $decoded = json_decode((string)$row['config'], true);
        $config = is_array($decoded) ? $decoded : null;
    }

    return [
        'state' => $state,
        'state_label' => awiIndexerWatcherStateLabel($state),
        'pending_reason' => $row['pending_reason'] ?? null,
        'pending_reason_label' => awiIndexerReasonLabel((string)($row['pending_reason'] ?? '')),
        'seen_age' => $seenAge,
        'scan_age' => isset($row['scan_age']) && $row['scan_age'] !== null
            ? (int)$row['scan_age'] : null,
        'uptime_seconds' => isset($row['uptime_seconds']) ? (int)$row['uptime_seconds'] : null,
        'failures' => (int)($row['failures'] ?? 0),
        'cooldown_seconds' => (int)($row['cooldown_seconds'] ?? 0),
        'known_files' => (int)($row['known_files'] ?? 0),
        // A stopped watcher is not "stale": it says so. A working one is busy,
        // not dead. Only an idle/starting row with an old heartbeat is a fault.
        'stale' => in_array($state, ['idle', 'starting'], true)
            && $seenAge !== null && $seenAge > AWI_INDEXER_WATCHER_STALE_SECONDS,
        'config' => $config,
    ];
}

function awiIndexerPhaseLabel(string $phase): string
{
    $map = [
        'starting' => 'indexer_phase_starting',
        'loading' => 'indexer_phase_loading',
        'scanning' => 'indexer_phase_scanning',
        'headers' => 'indexer_phase_headers',
        'projects' => 'indexer_phase_projects',
        'metrics' => 'indexer_phase_metrics',
        'schema' => 'indexer_phase_schema',
        'backfill' => 'indexer_phase_backfill',
        'cleanup' => 'indexer_phase_cleanup',
        'done' => 'indexer_phase_done',
    ];
    return __($map[$phase] ?? 'indexer_phase_starting');
}

function awiIndexerReasonLabel(string $reason): string
{
    $map = [
        'startup' => 'indexer_reason_startup',
        'created' => 'indexer_reason_created',
        'modified' => 'indexer_reason_modified',
        'moved' => 'indexer_reason_moved',
        'deleted' => 'indexer_reason_deleted',
        'scan' => 'indexer_reason_scan',
        'manual' => 'indexer_reason_manual',
        'backfill' => 'indexer_reason_backfill',
        'recompute' => 'indexer_reason_recompute',
        'suggest' => 'indexer_reason_suggest',
    ];
    return __($map[$reason] ?? 'indexer_reason_other');
}

function awiIndexerStatusLabel(string $status): string
{
    $map = [
        'running' => 'indexer_status_running',
        'done' => 'indexer_status_done',
        'error' => 'indexer_status_error',
        'interrupted' => 'indexer_status_interrupted',
    ];
    return __($map[$status] ?? 'indexer_status_interrupted');
}

function awiIndexerWatcherStateLabel(string $state): string
{
    $map = [
        'starting' => 'indexer_watcher_starting',
        'idle' => 'indexer_watcher_idle',
        'scanning' => 'indexer_watcher_scanning',
        'scheduled' => 'indexer_watcher_scheduled',
        'running' => 'indexer_watcher_running',
        'working' => 'indexer_watcher_working',
        'error' => 'indexer_watcher_error',
        'stopped' => 'indexer_watcher_stopped',
    ];
    return __($map[$state] ?? 'indexer_watcher_unknown');
}
