<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddAuthTables extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('users')) {
            $users = $this->table('users');
            $users->addColumn('username', 'string', ['limit' => 100])
                  ->addColumn('password', 'string', ['limit' => 255])
                  ->addColumn('is_admin', 'boolean', ['default' => false])
                  ->addColumn('can_download', 'boolean', ['default' => true])
                  ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                  ->addIndex(['username'], ['unique' => true])
                  ->create();
        }

        if (!$this->hasTable('user_permissions')) {
            $perms = $this->table('user_permissions');
            $perms->addColumn('user_id', 'integer', ['signed' => true])
                  ->addColumn('root_dir', 'string', ['limit' => 255])
                  ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                  ->addIndex(['user_id', 'root_dir'], ['unique' => true])
                  ->create();
        }
    }
}
