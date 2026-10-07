<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Consecutive per-project panel numbers.
 *
 * Panel labels used the global auto-increment id (shared across projects and
 * gapped by deletions: P21, P23, ...). panel_no is assigned per project,
 * ordered by creation, and stays stable when siblings are pruned.
 */
final class AddPanelNumbers extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('project_panels')) {
            return;
        }
        $panels = $this->table('project_panels');
        if (!$panels->hasColumn('panel_no')) {
            $panels->addColumn('panel_no', 'integer', ['null' => true, 'default' => null])
                ->update();
        }

        // Backfill: consecutive numbers per project, oldest panel first.
        $this->execute(
            "UPDATE project_panels pp "
            . "JOIN (SELECT pp2.id, ROW_NUMBER() OVER "
            . "(PARTITION BY ps.project_id ORDER BY pp2.id) AS rn "
            . "FROM project_panels pp2 "
            . "JOIN project_setups ps ON ps.id = pp2.setup_id) t ON t.id = pp.id "
            . "SET pp.panel_no = t.rn WHERE pp.panel_no IS NULL"
        );

        $this->table('project_panels')
            ->changeColumn('panel_no', 'integer', ['null' => false])
            ->update();
    }
}
