<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Per-link enable flag (subframe selection precursor).
 *
 * Disabled links stay in the project but are excluded from counts, exposure
 * totals and calibration diagnostics; the tree renders them greyed out with
 * an enable action instead of deleting them.
 */
final class AddLinkEnabled extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('project_files')) {
            return;
        }
        $links = $this->table('project_files');
        if (!$links->hasColumn('enabled')) {
            $links->addColumn('enabled', 'boolean', ['default' => true, 'null' => false])
                ->update();
        }
    }
}
