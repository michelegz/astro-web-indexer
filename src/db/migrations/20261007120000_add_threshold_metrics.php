<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Threshold columns for the remaining metrics: HFR spread (exclude above)
 * and relative PSF quality (exclude below).
 */
final class AddThresholdMetrics extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('project_group_thresholds')) {
            return;
        }
        $table = $this->table('project_group_thresholds');
        if (!$table->hasColumn('hfrsd_max')) {
            $table->addColumn('hfrsd_max', 'float', ['null' => true, 'default' => null]);
        }
        if (!$table->hasColumn('psf_min')) {
            $table->addColumn('psf_min', 'float', ['null' => true, 'default' => null]);
        }
        $table->update();
    }
}
