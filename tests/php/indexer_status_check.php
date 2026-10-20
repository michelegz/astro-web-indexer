<?php

declare(strict_types=1);

/**
 * Check — the PHP side of the indexer status channel.
 *
 * A synthetic run is inserted inside a transaction and read back through the
 * real helpers (getIndexerStatus / getIndexerRuns / awiDecorateIndexerWatcher),
 * then the transaction is rolled back. Nothing is left behind and no real run
 * or watcher row is touched.
 *
 * Usage:  docker exec awi-php sh -c 'cd /tmp/harness && php indexer_status_check.php'
 */

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/indexer_functions.php';

$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-58s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

$conn = connectDB();
$conn->beginTransaction();
$runId = null;

try {
    // A live run in its headers phase: 50 of 100 files, 20 seconds into the
    // phase, heartbeat just now. ETA and rate are derived from these numbers.
    $conn->exec(
        "INSERT INTO indexer_runs "
        . "(started_at, status, reason, phase, phase_started_at, phase_done, phase_total, "
        . " files_scanned, db_files, skipped, files_total, files_done, metrics_total, "
        . " metrics_done, error_count, heartbeat_at, phase_mode, pid, host) VALUES "
        . "(NOW() - INTERVAL 30 SECOND, 'running', 'created', 'headers', "
        . " NOW() - INTERVAL 20 SECOND, 50, 100, 499, 499, 480, 19, 0, 0, 0, 0, "
        . " NOW(), 'two', 123, 'test')"
    );
    $runId = (int)$conn->lastInsertId();

    $status = getIndexerStatus($conn, true);

    check('the status is available', ($status['available'] ?? false) === true, '');
    check('the current run is found', is_array($status['current'] ?? null), '');
    $current = $status['current'] ?? [];
    check('the phase is decoded', ($current['phase'] ?? '') === 'headers', (string)($current['phase'] ?? ''));
    check('the phase label is translated', !str_starts_with((string)($current['phase_label'] ?? ''), '[['),
        (string)($current['phase_label'] ?? ''));
    check('the reason label is translated', !str_starts_with((string)($current['reason_label'] ?? ''), '[['),
        (string)($current['reason_label'] ?? ''));
    check('the percentage is 50', abs(((float)($current['percent'] ?? 0)) - 50.0) < 0.01,
        (string)($current['percent'] ?? ''));
    check('the ETA is derived', ($current['eta_seconds'] ?? null) > 0,
        (string)($current['eta_seconds'] ?? 'null'));
    check('the rate is derived', ($current['rate_per_minute'] ?? null) > 0,
        (string)($current['rate_per_minute'] ?? 'null'));
    check('the heartbeat is fresh', ($current['stalled'] ?? true) === false, '');
    check('the archive count is present', array_key_exists('metrics_pending', $status['archive'] ?? []), '');

    // Finish it. The history query must stop calling it running and must derive
    // its duration from the timestamps, not from a PHP clock.
    $conn->exec("UPDATE indexer_runs SET status = 'done', finished_at = NOW() WHERE id = {$runId}");
    $runs = getIndexerRuns($conn, 5, true);
    $found = null;
    foreach ($runs as $row) {
        if (($row['id'] ?? 0) === $runId) {
            $found = $row;
            break;
        }
    }
    check('the finished run is in the history', $found !== null, '');
    check('the history reports it done', ($found['status'] ?? '') === 'done', (string)($found['status'] ?? ''));
    check('the duration comes from the row', ($found['elapsed_seconds'] ?? 0) >= 29,
        (string)($found['elapsed_seconds'] ?? 'null'));
    check('the current run is gone', getIndexerStatus($conn, true)['current'] === null, '');

    // Watcher decoration is pure: a long silence while idle is a fault, the same
    // silence while a project backfill is running is not.
    $idle = awiDecorateIndexerWatcher([
        'state' => 'idle', 'seen_age' => 300, 'scan_age' => 10,
        'uptime_seconds' => 1000, 'failures' => 0, 'known_files' => 499,
    ]);
    check('an idle watcher with an old heartbeat is stale', ($idle['stale'] ?? false) === true, '');
    $working = awiDecorateIndexerWatcher([
        'state' => 'working', 'seen_age' => 300, 'scan_age' => 10,
        'uptime_seconds' => 1000, 'failures' => 0, 'known_files' => 499,
    ]);
    check('a working watcher is busy, not stale', ($working['stale'] ?? true) === false, '');
    check('a watcher state is translated', !str_starts_with((string)$working['state_label'], '[['),
        (string)$working['state_label']);
} finally {
    $conn->rollBack();
}

echo "\nRESULT: " . ($failed ? count($failed) . ' FAILED' : 'OK') . "\n";
if ($failed) {
    echo 'FAILED: ' . implode(', ', $failed) . "\n";
    exit(1);
}
