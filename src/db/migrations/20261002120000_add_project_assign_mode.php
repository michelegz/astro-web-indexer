<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Explicit assignment control for projects (see tmp/awi-projects-plan.md rev. 2026-09-30).
 *
 * - projects.assign_mode: manual|suggest|frozen|auto (default suggest).
 *   Nothing is ever linked silently in suggest/manual/frozen modes.
 * - project_suggestions: persistent review queue for the wizard.
 *   pending = awaiting user decision, accepted = moved to project_files,
 *   dismissed = never proposed again.
 *
 * Portability: Table API only, no ENUM, no ENGINE-specific SQL.
 */
final class AddProjectAssignMode extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('projects') && !$this->table('projects')->hasColumn('assign_mode')) {
            $this->table('projects')
                ->addColumn('assign_mode', 'string', [
                    'limit' => 16,
                    'null' => false,
                    'default' => 'suggest',
                    'comment' => 'manual|suggest|frozen|auto; default suggest (wizard review)',
                ])
                ->update();
        }

        if (!$this->hasTable('project_suggestions') && $this->hasTable('files')) {
            $suggestions = $this->table('project_suggestions');
            $suggestions->addColumn('project_id', 'integer', ['null' => false, 'signed' => true])
                ->addColumn('file_id', 'integer', ['null' => false, 'signed' => true])
                ->addColumn('level', 'string', ['limit' => 16, 'null' => false, 'comment' => 'project|setup|panel|session|filter'])
                ->addColumn('node_id', 'integer', ['null' => false, 'signed' => true, 'comment' => 'Parent row id, same semantics as project_files.node_id'])
                ->addColumn('filter_name', 'string', ['limit' => 50, 'null' => true, 'default' => null, 'comment' => 'Only for level=filter'])
                ->addColumn('role', 'string', ['limit' => 16, 'null' => false, 'default' => 'sub', 'comment' => 'sub|master'])
                ->addColumn('reason', 'text', ['null' => true, 'default' => null, 'comment' => 'Human-readable why this file was suggested'])
                ->addColumn('status', 'string', ['limit' => 16, 'null' => false, 'default' => 'pending', 'comment' => 'pending|accepted|dismissed'])
                ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('file_id', 'files', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addIndex(['project_id', 'status'])
                ->addIndex(['project_id', 'file_id', 'level', 'node_id'], ['unique' => true])
                ->create();
        }
    }
}
