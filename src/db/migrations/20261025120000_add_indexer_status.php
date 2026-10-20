<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Durable progress for the indexer, readable by the web container.
 *
 * The Python container and the PHP container share no writable filesystem and
 * PHP must not talk to the Docker socket, so the database is the only channel
 * between the two: reindex.py and watch_fs.py write here, index.php reads it.
 *
 * Two tables, two owners:
 *
 *  - indexer_runs: one row per index run. reindex.py creates it when the run
 *    starts, updates the phase and progress while it works, and closes it with
 *    done|error|interrupted. The web side keeps the last few and prunes the
 *    rest, so this stays a tiny table even on an archive indexed for years.
 *
 *  - indexer_watcher: a singleton (id = 1) owned by watch_fs.py. It says
 *    whether the watcher is alive, idle, scheduled, running a reindex, or
 *    working through the project-suggestion queue.
 *
 * Every timestamp is written with NOW() by MariaDB, never with a Python or PHP
 * clock: the containers can have different ideas about the timezone, and the
 * UI computes ages with TIMESTAMPDIFF() on the same clock that wrote them.
 *
 * Status is best effort by design. A missing table disables the Python
 * publisher instead of stopping indexing; the web side hides the card.
 */
final class AddIndexerStatus extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('indexer_runs')) {
            $runs = $this->table('indexer_runs');
            $runs->addColumn('started_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'null' => false])
                ->addColumn('finished_at', 'timestamp', ['null' => true, 'default' => null])
                ->addColumn('status', 'string', [
                    'limit' => 16,
                    'null' => false,
                    'default' => 'running',
                    'comment' => 'running|done|error|interrupted',
                ])
                ->addColumn('reason', 'string', [
                    'limit' => 32,
                    'null' => false,
                    'default' => 'manual',
                    'comment' => 'startup|created|modified|moved|deleted|scan|manual|backfill|recompute|suggest',
                ])
                ->addColumn('phase', 'string', [
                    'limit' => 24,
                    'null' => false,
                    'default' => 'starting',
                    'comment' => 'starting|loading|scanning|headers|projects|metrics|schema|backfill|cleanup|done',
                ])
                ->addColumn('phase_started_at', 'timestamp', ['null' => true, 'default' => null])
                ->addColumn('phase_done', 'integer', ['null' => false, 'default' => 0])
                ->addColumn('phase_total', 'integer', ['null' => false, 'default' => 0])
                ->addColumn('files_scanned', 'integer', ['null' => false, 'default' => 0, 'comment' => 'Files found on disk by the archive scan'])
                ->addColumn('db_files', 'integer', ['null' => false, 'default' => 0, 'comment' => 'Rows the run loaded before processing'])
                ->addColumn('skipped', 'integer', ['null' => false, 'default' => 0, 'comment' => 'Files unchanged since the last run'])
                ->addColumn('files_total', 'integer', ['null' => false, 'default' => 0])
                ->addColumn('files_done', 'integer', ['null' => false, 'default' => 0])
                ->addColumn('metrics_total', 'integer', ['null' => false, 'default' => 0])
                ->addColumn('metrics_done', 'integer', ['null' => false, 'default' => 0])
                ->addColumn('error_count', 'integer', ['null' => false, 'default' => 0])
                ->addColumn('last_error', 'text', ['null' => true, 'default' => null])
                ->addColumn('heartbeat_at', 'timestamp', ['null' => true, 'default' => null])
                ->addColumn('phase_mode', 'string', ['limit' => 8, 'null' => true, 'default' => null])
                ->addColumn('config', 'text', ['null' => true, 'default' => null, 'comment' => 'JSON-encoded run configuration'])
                ->addColumn('pid', 'integer', ['null' => true, 'default' => null])
                ->addColumn('host', 'string', ['limit' => 64, 'null' => true, 'default' => null])
                ->addIndex(['status', 'id'])
                ->addIndex(['started_at'])
                ->create();
        }

        if (!$this->hasTable('indexer_watcher')) {
            $watcher = $this->table('indexer_watcher');
            $watcher->addColumn('state', 'string', [
                    'limit' => 16,
                    'null' => false,
                    'default' => 'starting',
                    'comment' => 'starting|idle|scanning|scheduled|running|working|error|stopped',
                ])
                ->addColumn('started_at', 'timestamp', ['null' => true, 'default' => null])
                ->addColumn('seen_at', 'timestamp', ['null' => true, 'default' => null])
                ->addColumn('last_scan_at', 'timestamp', ['null' => true, 'default' => null])
                ->addColumn('pending_reason', 'string', ['limit' => 32, 'null' => true, 'default' => null])
                ->addColumn('failures', 'integer', ['null' => false, 'default' => 0])
                ->addColumn('cooldown_seconds', 'integer', ['null' => false, 'default' => 0])
                ->addColumn('known_files', 'integer', ['null' => false, 'default' => 0])
                ->addColumn('config', 'text', ['null' => true, 'default' => null, 'comment' => 'JSON-encoded watcher configuration'])
                ->create();
        }

        // The status card counts the frames that still owe their metrics. Without
        // this index that COUNT(*) walks every row of the archive on each poll.
        if ($this->hasTable('files') && !$this->hasIndexNamed('files', 'idx_metrics_pending')) {
            $this->table('files')
                ->addIndex(['metrics_pending'], ['name' => 'idx_metrics_pending'])
                ->update();
        }
    }

    /**
     * Index existence by name, read from information_schema: the Table::hasIndex()
     * signature differs across Phinx versions and this project already uses this
     * helper elsewhere.
     */
    private function hasIndexNamed(string $table, string $index): bool
    {
        $row = $this->fetchRow(
            'SELECT COUNT(*) AS n FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '
            . var_export($table, true) . ' AND INDEX_NAME = ' . var_export($index, true)
        );
        return $row !== false && (int)($row['n'] ?? 0) > 0;
    }
}
