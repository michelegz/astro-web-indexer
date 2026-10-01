<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Consecutive per-project setup numbers.
 *
 * Same rationale as panel_no: setup labels used the global auto-increment id
 * (shared across projects and gapped by deletions). setup_no is assigned per
 * project, ordered by creation, and stays stable when siblings are pruned.
 */
final class AddSetupNumbers extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('project_setups')) {
            return;
        }
        $setups = $this->table('project_setups');
        if (!$setups->hasColumn('setup_no')) {
            $setups->addColumn('setup_no', 'integer', ['null' => true, 'default' => null])
                ->update();
        }

        // Backfill: consecutive numbers per project, oldest setup first.
        $this->execute(
            "UPDATE project_setups ps "
            . "JOIN (SELECT id, ROW_NUMBER() OVER "
            . "(PARTITION BY project_id ORDER BY id) AS rn "
            . "FROM project_setups) t ON t.id = ps.id "
            . "SET ps.setup_no = t.rn WHERE ps.setup_no IS NULL"
        );

        $this->table('project_setups')
            ->changeColumn('setup_no', 'integer', ['null' => false])
            ->update();
    }
}
