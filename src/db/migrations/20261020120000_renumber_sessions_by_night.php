<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Reorder session_no to follow the astro-night.
 *
 * AddSessionNumbers backfilled session_no with ROW_NUMBER() partitioned by
 * project and ordered by astro_night, and the project tree renders sessions in
 * night order, so the numbering is meant to increase with the night. Allocation,
 * however, is MAX()+1 at insert time, which only matches that while nights arrive
 * in chronological order. Adding lights from an older night later yields
 * "N3 . 2026-10-01" next to "N1 . 2026-10-10", which defeats the point of the
 * prefix and contradicts the invariant the original migration documented.
 *
 * This renumbers the existing rows the same way the backfill did, so the data
 * matches the intent again. Allocation is fixed separately in the code.
 */
final class RenumberSessionsByNight extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('project_sessions') || !$this->hasTable('project_panels')
            || !$this->hasTable('project_setups')) {
            return;
        }

        $this->execute(
            'UPDATE project_sessions ss '
            . 'JOIN project_panels pp ON pp.id = ss.panel_id '
            . 'JOIN project_setups ps ON ps.id = pp.setup_id '
            . 'JOIN (SELECT ss2.id, ROW_NUMBER() OVER '
            . '(PARTITION BY ps2.project_id ORDER BY ss2.astro_night, ss2.id) AS rn '
            . 'FROM project_sessions ss2 '
            . 'JOIN project_panels pp2 ON pp2.id = ss2.panel_id '
            . 'JOIN project_setups ps2 ON ps2.id = pp2.setup_id) t ON t.id = ss.id '
            . 'SET ss.session_no = t.rn'
        );
    }
}