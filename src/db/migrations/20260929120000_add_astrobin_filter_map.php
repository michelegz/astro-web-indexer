<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddAstrobinFilterMap extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('astrobin_filter_map')) {
            $table = $this->table('astrobin_filter_map');
            $table->addColumn('filter_name', 'string', ['limit' => 64, 'null' => false, 'comment' => 'FITS FILTER value (trimmed, matched case-insensitively)'])
                ->addColumn('astrobin_id', 'integer', ['null' => false, 'comment' => 'Numeric filter ID from the AstroBin equipment database URL'])
                ->addColumn('label', 'string', ['limit' => 128, 'null' => true, 'default' => null, 'comment' => 'Free note, e.g. product name'])
                ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['filter_name'], ['unique' => true])
                ->create();
        }
    }
}
