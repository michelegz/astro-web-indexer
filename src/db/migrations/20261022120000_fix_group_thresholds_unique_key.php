<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Make the group-threshold key actually unique.
 *
 * The key added with the table is UNIQUE (project_id, setup_id, panel_id,
 * filter_name, exptime), and the last two columns are nullable. In MySQL and MariaDB
 * a UNIQUE constraint treats every NULL as distinct from every other NULL, so a row
 * with no filter anchor or no exposure anchor was never a duplicate of anything: the
 * constraint held for the rows where it looked like it held and silently did nothing
 * for the rest.
 *
 * saveGroupThresholds() therefore never got its ON DUPLICATE KEY UPDATE to fire for
 * those groups and appended a row on every save. getProjectThresholds() keys the rows
 * by group identity and assigns rather than merges, so once duplicates existed the
 * value returned for a group came from whichever row the unordered SELECT happened to
 * yield last — thresholds that decide file rejection, decided by storage order.
 *
 * Two persisted generated columns carry the NULL-free identity into a key that does
 * hold. NULL exptime maps to -1, which no real exposure can take: the column is
 * DECIMAL(12,3) and exposure is positive.
 *
 * This is the load-bearing half of the fix: with the old key in place the old upsert
 * never fired, so DELETE + INSERT in the save path plus this key are two independent
 * guarantees of one row per group, and neither depends on the other.
 *
 * up()/down() are explicit rather than change(): change() cannot invert raw execute(),
 * so a rollback of this migration would have dropped nothing and left the table in a
 * state that matched no version of the schema. down() puts the original key back.
 */
final class FixGroupThresholdsUniqueKey extends AbstractMigration
{
    private const TABLE = 'project_group_thresholds';
    private const KEY = 'uq_pgt_group';

    public function up(): void
    {
        if (!$this->hasTable(self::TABLE)) {
            return;
        }

        // The old key is named after its first column, which Phinx does when no name
        // is given. It doubles as the foreign-key index for project_id, so a plain
        // index goes in first: MariaDB refuses to drop an index a constraint needs.
        if ($this->hasIndexNamed(self::TABLE, 'project_id') && !$this->hasIndexNamed(self::TABLE, 'project_id_fk')) {
            $this->table(self::TABLE)->addIndex(['project_id'], ['name' => 'project_id_fk'])->update();
        }
        if ($this->hasIndexNamed(self::TABLE, 'project_id')) {
            $this->execute('ALTER TABLE `' . self::TABLE . '` DROP INDEX `project_id`');
        }

        if (!$this->hasColumn(self::TABLE, 'filter_name_key')) {
            $this->execute(
                'ALTER TABLE `' . self::TABLE . '` '
                . 'ADD COLUMN `filter_name_key` VARCHAR(50) AS (IFNULL(`filter_name`, \'\')) PERSISTENT'
            );
        }
        if (!$this->hasColumn(self::TABLE, 'exptime_key')) {
            $this->execute(
                'ALTER TABLE `' . self::TABLE . '` '
                . 'ADD COLUMN `exptime_key` DECIMAL(12,3) AS (IFNULL(`exptime`, -1)) PERSISTENT'
            );
        }

        if (!$this->hasIndexNamed(self::TABLE, self::KEY)) {
            $this->table(self::TABLE)
                ->addIndex(['project_id', 'setup_id', 'panel_id', 'filter_name_key', 'exptime_key'],
                    ['unique' => true, 'name' => self::KEY])
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
        $this->execute('ALTER TABLE `' . self::TABLE . '` DROP COLUMN `filter_name_key`, DROP COLUMN `exptime_key`');

        // Back to the original key, including the name Phinx gave it. Only if the
        // table has no row that would collide under it: re-adding a unique key that
        // duplicates predate the migration is exactly what we are rolling back from.
        $clashes = $this->fetchRow(
            'SELECT COUNT(*) AS n FROM (SELECT 1 FROM `' . self::TABLE . '` GROUP BY project_id, setup_id, '
            . 'panel_id, filter_name, exptime HAVING COUNT(*) > 1) d'
        );
        if ((int)($clashes['n'] ?? 0) === 0 && !$this->hasIndexNamed(self::TABLE, 'project_id')) {
            $this->table(self::TABLE)
                ->addIndex(['project_id', 'setup_id', 'panel_id', 'filter_name', 'exptime'],
                    ['unique' => true, 'name' => 'project_id'])
                ->update();
        }
        if ($this->hasIndexNamed(self::TABLE, 'project_id_fk')) {
            $this->execute('ALTER TABLE `' . self::TABLE . '` DROP INDEX `project_id_fk`');
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

    /**
     * Index existence by name, read from information_schema rather than through the
     * Table API, whose hasIndex() signature varies across Phinx versions.
     */
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
