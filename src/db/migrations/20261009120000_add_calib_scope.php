<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Session scope for calibration links (WBPP-style transverse grouping).
 *
 * A calibration linked e.g. at setup level applies to the whole chain by
 * default; with scope rows it applies only to the listed sessions. Empty
 * scope = current behavior, so no data migration is needed.
 */
final class AddCalibScope extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('project_calib_scope') && $this->hasTable('project_files')) {
            $scope = $this->table('project_calib_scope', ['id' => false, 'primary_key' => ['file_id', 'level', 'node_id', 'session_id']]);
            // NB: project_sessions.id is an *unsigned* Phinx PK (see
            // 20261003120000_fix_project_fks.php): session_id must match it,
            // while file_id/node_id stay signed like project_files.
            $scope->addColumn('file_id', 'integer', ['null' => false, 'signed' => true])
                ->addColumn('level', 'string', ['limit' => 16, 'null' => false])
                ->addColumn('node_id', 'integer', ['null' => false, 'signed' => true])
                ->addColumn('session_id', 'integer', ['null' => false, 'signed' => false])
                ->addForeignKey(['file_id', 'level', 'node_id'], 'project_files', ['file_id', 'level', 'node_id'], ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('session_id', 'project_sessions', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addIndex(['session_id'])
                ->create();
        }
    }
}
