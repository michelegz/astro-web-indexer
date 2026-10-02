<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Consecutive per-project session numbers.
 *
 * Same rationale as setup_no/panel_no: session displays used the global
 * auto-increment id or the bare night. session_no is assigned per project,
 * ordered by night, and stays stable when siblings are pruned.
 */
final class AddSessionNumbers extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('project_sessions')) {
            return;
        }
        $sessions = $this->table('project_sessions');
        if (!$sessions->hasColumn('session_no')) {
            $sessions->addColumn('session_no', 'integer', ['null' => true, 'default' => null])
                ->update();
        }

        // Backfill: consecutive numbers per project, oldest night first.
        $this->execute(
            "UPDATE project_sessions ss "
            . "JOIN project_panels pp ON pp.id = ss.panel_id "
            . "JOIN project_setups ps ON ps.id = pp.setup_id "
            . "JOIN (SELECT ss2.id, ROW_NUMBER() OVER "
            . "(PARTITION BY ps2.project_id ORDER BY ss2.astro_night, ss2.id) AS rn "
            . "FROM project_sessions ss2 "
            . "JOIN project_panels pp2 ON pp2.id = ss2.panel_id "
            . "JOIN project_setups ps2 ON ps2.id = pp2.setup_id) t ON t.id = ss.id "
            . "SET ss.session_no = t.rn WHERE ss.session_no IS NULL"
        );

        $this->table('project_sessions')
            ->changeColumn('session_no', 'integer', ['null' => false])
            ->update();
    }
}
