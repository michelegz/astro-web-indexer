<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Per-project filter aliases: raw FITS FILTER values mapped to one canonical
 * name (e.g. 'H-ALPHA' -> 'Ha'). Canonicalization applies at read time to
 * the project tree, integration grouping, calibration matching, thresholds
 * and the ZIP export folders. No relation to the AstroBin filter map, which
 * serves a different purpose (equipment database IDs).
 */
final class AddProjectFilterAliases extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('project_filter_aliases')) {
            return;
        }
        $table = $this->table('project_filter_aliases', ['id' => false, 'primary_key' => ['project_id', 'alias']]);
        $table->addColumn('project_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('alias', 'string', ['limit' => 50, 'null' => false, 'comment' => 'Raw filter name as found in files (trimmed)'])
            ->addColumn('canonical', 'string', ['limit' => 50, 'null' => false, 'comment' => 'Canonical name this alias maps to (trimmed)'])
            ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }
}
