<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Real foreign keys for the project tables.
 *
 * The base migration declared FK columns as signed integers while Phinx
 * primary keys are unsigned, so MariaDB refused the constraints
 * (errno 150) and the tables were created without them. This migration
 * converts the FK columns to unsigned and adds the constraints.
 *
 * files.id is a *signed* INT (raw SQL initial schema), so file_id columns
 * stay signed. project_files.node_id is polymorphic (level-scoped) and
 * intentionally has no FK; its cleanup lives in deleteProject().
 */
final class FixProjectFks extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('project_setups')) {
            $this->table('project_setups')
                ->changeColumn('project_id', 'integer', ['signed' => false, 'null' => false])
                ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->update();
        }

        if ($this->hasTable('project_panels')) {
            $this->table('project_panels')
                ->changeColumn('setup_id', 'integer', ['signed' => false, 'null' => false])
                ->addForeignKey('setup_id', 'project_setups', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->update();
        }

        if ($this->hasTable('project_sessions')) {
            $this->table('project_sessions')
                ->changeColumn('panel_id', 'integer', ['signed' => false, 'null' => false])
                ->addForeignKey('panel_id', 'project_panels', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->update();
        }

        if ($this->hasTable('project_suggestions')) {
            $this->table('project_suggestions')
                ->changeColumn('project_id', 'integer', ['signed' => false, 'null' => false])
                ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('file_id', 'files', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->update();
        }

        if ($this->hasTable('setup_overrides')) {
            $this->table('setup_overrides')
                ->changeColumn('setup_id', 'integer', ['signed' => false, 'null' => false])
                ->addForeignKey('setup_id', 'project_setups', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('file_id', 'files', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->update();
        }

        if ($this->hasTable('panel_merges')) {
            $this->table('panel_merges')
                ->changeColumn('panel_a', 'integer', ['signed' => false, 'null' => false])
                ->changeColumn('panel_b', 'integer', ['signed' => false, 'null' => false])
                ->addForeignKey('panel_a', 'project_panels', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('panel_b', 'project_panels', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->update();
        }
    }
}
