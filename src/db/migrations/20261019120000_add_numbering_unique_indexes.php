<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Unique keys on the per-project display numbering.
 *
 * setup_no, panel_no and session_no were allocated as MAX()+1 with no unique
 * constraint, so two concurrent writers (the PHP web container doing a manual
 * add and the Python watcher doing a backfill pass, which run side by side by
 * design) could both read the same MAX and both commit the same number. The
 * visible symptom was two setups rendered S3 in one project, and the ZIP export
 * writing both into SETUP_S3/ with their panels and calibration folders mixed.
 *
 * This migration first repairs the rows that are already colliding, then adds the
 * keys that make the race impossible to lose silently: from here on the duplicate
 * insert fails with 1062 and the caller retries with a fresh MAX.
 *
 * The session ordering is a separate concern: see RenumberSessionsByNight.
 */
final class AddNumberingUniqueIndexes extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('project_setups') || !$this->hasTable('project_panels')) {
            return;
        }

        $this->renumberCollisions('project_setups', 'setup_no', 'project_id');
        $this->renumberCollisions('project_panels', 'panel_no', 'setup_id');

        if (!$this->hasIndexNamed('project_setups', 'uq_project_setups_no')) {
            $this->table('project_setups')
                ->addIndex(['project_id', 'setup_no'], ['unique' => true, 'name' => 'uq_project_setups_no'])
                ->update();
        }
        if (!$this->hasIndexNamed('project_panels', 'uq_project_panels_no')) {
            $this->table('project_panels')
                ->addIndex(['setup_id', 'panel_no'], ['unique' => true, 'name' => 'uq_project_panels_no'])
                ->update();
        }
    }

    /**
     * Index existence by name, read straight from information_schema so this does
     * not depend on the Table::hasIndex() signature of the Phinx version.
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

    /**
     * Give every colliding row of $column a fresh value inside its $scope group.
     * For each colliding number the oldest id keeps it and the others are moved
     * above the current maximum, re-read on every iteration so each lands on a
     * distinct value.
     */
    private function renumberCollisions(string $table, string $column, string $scope): void
    {
        $duplicates = $this->fetchAll(
            "SELECT d.id FROM `{$table}` d "
            . "JOIN (SELECT `{$scope}`, `{$column}`, MIN(id) AS keep_id FROM `{$table}` "
            . "GROUP BY `{$scope}`, `{$column}`) k "
            . "ON k.`{$scope}` = d.`{$scope}` AND k.`{$column}` = d.`{$column}` "
            . 'AND k.keep_id <> d.id ORDER BY d.id'
        );
        foreach ($duplicates as $row) {
            $id = (int)$row['id'];
            $top = $this->fetchRow(
                "SELECT COALESCE(MAX(`{$column}`), 0) AS top FROM `{$table}` WHERE id = {$id}"
            );
            $next = (int)($top['top'] ?? 0) + 1;
            $this->execute("UPDATE `{$table}` SET `{$column}` = {$next} WHERE id = {$id}");
        }
    }
}