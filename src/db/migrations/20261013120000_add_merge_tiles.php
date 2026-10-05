<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Cross-setup tile merging for mosaics: merge_tiles pools panels of different
 * setups sharing sky position/rotation/FoV (same rules as panel matching)
 * into one integration-group tile. Effective only with split_setup OFF.
 * Export renders the shared TILE_Tn keyword so WBPP can group by tile.
 */
final class AddMergeTiles extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('project_grouping')) {
            return;
        }
        $table = $this->table('project_grouping');
        if (!$table->hasColumn('merge_tiles')) {
            $table->addColumn('merge_tiles', 'boolean', ['null' => false, 'default' => false]);
        }
        $table->update();
    }
}
