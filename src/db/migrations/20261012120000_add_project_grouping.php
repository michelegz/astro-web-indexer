<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Configurable integration-group split criteria, per project.
 *
 * Pool key of getIntegrationGroups() contains only active levels:
 * split_setup / split_panel / split_filter / split_exposure (+ exp_tol
 * overriding tol_exp) / split_temp (+ temp_tol overriding tol_temp).
 * Defaults reproduce the historical behaviour (all ON except temp OFF);
 * NULL tolerances inherit the project/global tolerance.
 */
final class AddProjectGrouping extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('project_grouping')) {
            return;
        }
        $table = $this->table('project_grouping', ['id' => false, 'primary_key' => ['project_id']]);
        $table->addColumn('project_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('split_setup', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('split_panel', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('split_filter', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('split_exposure', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('split_temp', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('exp_tol', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addColumn('temp_tol', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }
}
