<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Repair suggest_requests.project_id: it was created SIGNED while
 * projects.id is UNSIGNED, so the CASCADE foreign key was never created and
 * deleting a project left orphan queue rows behind. Align the type, drop
 * orphans, then add the missing key. Table API + primary-key deletes only.
 */
final class FixSuggestRequestsFk extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('suggest_requests') || !$this->hasTable('projects')) {
            return;
        }
        $orphans = $this->fetchAll(
            'SELECT sr.id FROM suggest_requests sr '
            . 'LEFT JOIN projects p ON p.id = sr.project_id WHERE p.id IS NULL'
        );
        foreach ($orphans as $row) {
            $this->execute('DELETE FROM suggest_requests WHERE id = ' . (int)$row['id']);
        }
        $t = $this->table('suggest_requests');
        $t->changeColumn('project_id', 'integer', ['null' => false, 'signed' => false]);
        $t->update();
        if (!$t->hasForeignKey('project_id')) {
            $t->addForeignKey('project_id', 'projects', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->update();
        }
    }
}
