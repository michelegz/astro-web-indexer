<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Async re-suggest queue for projects (see tmp/suggest-queue-plan.md).
 *
 * PHP (web container) never spawns the Python indexer: it only enqueues a
 * row here. The Python watcher (same container as reindex.py) polls this
 * table and runs suggest_projects_backfill(project_id).
 *
 * One pending row per project is enough (runs are coalesced): a backfill
 * pass reconsiders every never-suggested and stale-dismissed file of the
 * project, so five triggers in a minute need a single run.
 *
 * Portability: Table API only, no ENUM, no ENGINE-specific SQL.
 */
final class AddSuggestRequests extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('suggest_requests') && $this->hasTable('projects')) {
            $t = $this->table('suggest_requests');
            $t->addColumn('project_id', 'integer', ['null' => false, 'signed' => true])
                ->addColumn('reason', 'string', [
                    'limit' => 32,
                    'null' => false,
                    'default' => 'manual',
                    'comment' => 'panel_created|setup_created|tolerances_changed|manual',
                ])
                ->addColumn('status', 'string', [
                    'limit' => 16,
                    'null' => false,
                    'default' => 'pending',
                    'comment' => 'pending|running|done|error',
                ])
                ->addColumn('result', 'text', ['null' => true, 'default' => null])
                ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('started_at', 'timestamp', ['null' => true, 'default' => null])
                ->addColumn('finished_at', 'timestamp', ['null' => true, 'default' => null])
                ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addIndex(['status', 'id'])
                ->addIndex(['project_id', 'status'])
                ->create();
        }
    }
}
