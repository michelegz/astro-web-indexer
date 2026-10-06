<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * One pending suggestion request per project, enforced by the database.
 *
 * The queue coalesces: a backfill pass reconsiders every never-suggested and
 * stale-dismissed file of a project, so five triggers in a minute need a single run.
 * That invariant lived only in enqueueSuggestRequest(), as a SELECT for an existing
 * pending row followed by an INSERT — a read-then-write that two writers can both
 * pass. The scenario is not hypothetical: projectAddFiles() enqueues panel_created and
 * setup_created from the web container after its commit, so it is outside any
 * transaction and runs while another user can be pressing request_suggest. When both
 * landed, the project got two pending rows and the Python watcher ran the backfill
 * twice.
 *
 * The trick is to reuse the property that made the old index on
 * project_group_thresholds ineffective. A UNIQUE constraint treats NULLs as distinct,
 * so a column that carries the project id only while status is 'pending' allows any
 * number of finished rows and still permits exactly one pending one. MariaDB indexes
 * generated columns, so this needs no trigger and no engine-specific rewrite.
 *
 * up()/down() are explicit: change() cannot invert a raw execute().
 */
final class AddSuggestPendingUnique extends AbstractMigration
{
    private const TABLE = 'suggest_requests';
    private const KEY = 'uq_suggest_pending';
    private const COL = 'pending_key';

    public function up(): void
    {
        if (!$this->hasTable(self::TABLE)) {
            return;
        }
        // Rows that predate the key: one pending row per project, oldest kept, since
        // the watcher picks pending by id and the oldest is the one already going.
        $dupes = $this->fetchAll(
            'SELECT project_id, MIN(id) AS keep_id FROM `' . self::TABLE . '` '
            . "WHERE status = 'pending' GROUP BY project_id"
        );
        foreach ($dupes as $row) {
            $pid = (int)$row['project_id'];
            $this->execute(
                'DELETE FROM `' . self::TABLE . '` WHERE project_id = ' . $pid
                . " AND status = 'pending' AND id > " . (int)$row['keep_id']
            );
        }

        if (!$this->hasColumn(self::TABLE, self::COL)) {
            $this->execute(
                'ALTER TABLE `' . self::TABLE . '` ADD COLUMN `' . self::COL . '` INT AS '
                . "(CASE WHEN `status` = 'pending' THEN `project_id` ELSE NULL END) PERSISTENT"
            );
        }
        if (!$this->hasIndexNamed(self::TABLE, self::KEY)) {
            $this->table(self::TABLE)
                ->addIndex([self::COL], ['unique' => true, 'name' => self::KEY])
                ->update();
        }
    }

    public function down(): void
    {
        if (!$this->hasTable(self::TABLE)) {
            return;
        }
        if ($this->hasIndexNamed(self::TABLE, self::KEY)) {
            $this->execute('ALTER TABLE `' . self::TABLE . '` DROP INDEX `' . self::KEY . '`');
        }
        if ($this->hasColumn(self::TABLE, self::COL)) {
            $this->execute('ALTER TABLE `' . self::TABLE . '` DROP COLUMN `' . self::COL . '`');
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        $row = $this->fetchRow(
            'SELECT COUNT(*) AS n FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . var_export($table, true)
            . ' AND COLUMN_NAME = ' . var_export($column, true)
        );
        return $row !== false && (int)($row['n'] ?? 0) > 0;
    }

    private function hasIndexNamed(string $table, string $index): bool
    {
        $row = $this->fetchRow(
            'SELECT COUNT(*) AS n FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . var_export($table, true)
            . ' AND INDEX_NAME = ' . var_export($index, true)
        );
        return $row !== false && (int)($row['n'] ?? 0) > 0;
    }
}
