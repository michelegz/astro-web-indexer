<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Stored rejection thresholds per integration group.
 *
 * A file is effectively included iff manually enabled AND passing the stored
 * thresholds — thresholds alone decide, no forced per-file state goes stale.
 * Direction is fixed by column semantics (max = exclude above for HFR/FWHM/
 * eccentricity; min = exclude below for star count/stellar SNR). NULL = metric
 * inactive. Group identity is (setup, panel, filter, exposure anchor).
 */
final class AddGroupThresholds extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('project_group_thresholds')) {
            return;
        }
        $table = $this->table('project_group_thresholds');
        $table->addColumn('project_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('setup_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('panel_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('filter_name', 'string', ['limit' => 50, 'null' => true, 'default' => null])
            ->addColumn('exptime', 'decimal', ['precision' => 12, 'scale' => 3, 'null' => true, 'default' => null])
            ->addColumn('hfr_max', 'float', ['null' => true, 'default' => null])
            ->addColumn('fwhm_max', 'float', ['null' => true, 'default' => null])
            ->addColumn('ecc_max', 'float', ['null' => true, 'default' => null])
            ->addColumn('stars_min', 'float', ['null' => true, 'default' => null])
            ->addColumn('snr_min', 'float', ['null' => true, 'default' => null])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('setup_id', 'project_setups', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('panel_id', 'project_panels', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addIndex(['project_id', 'setup_id', 'panel_id', 'filter_name', 'exptime'], ['unique' => true])
            ->create();
    }
}
