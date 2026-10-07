<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Project hierarchy (WBPP-like, v1 free, Pro-ready):
 *
 *   PROJECT -> SETUP (instrument fingerprint) -> PANEL (coords + rotation)
 *     -> SESSION (astro-night noon-to-noon) -> FILTER -> light frames,
 *   with calibration files linkable at any level (sub or master).
 *
 * Design notes (see tmp/awi-projects-plan.md):
 * - Subject identity is coordinates + FoV; OBJECT is display-only / fallback.
 * - Rotation (objctrot) splits panels beyond tolerance; NULL = wildcard.
 * - Per-project tolerance overrides live in projects.tolerances (JSON text);
 *   global defaults live in global_settings. Overrides never rewrite manual links.
 * - v1 stores logical links only, no physical file moves.
 *
 * Portability: Table API only (no ENGINE-specific SQL) so the migration also
 * applies to a future SQLite desktop database. String columns are used instead
 * of ENUM for the same reason. `setting_key` is used instead of `key`
 * (reserved word in MySQL).
 */
final class AddProjectTables extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('projects')) {
            $projects = $this->table('projects');
            $projects->addColumn('name', 'string', ['limit' => 255, 'null' => false])
                ->addColumn('notes', 'text', ['null' => true, 'default' => null])
                ->addColumn('tolerances', 'text', ['null' => true, 'default' => null, 'comment' => 'JSON-encoded per-project tolerance overrides; NULL = inherit globals'])
                ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                ->create();
        }

        if (!$this->hasTable('project_setups')) {
            $setups = $this->table('project_setups');
            $setups->addColumn('project_id', 'integer', ['null' => false, 'signed' => true])
                ->addColumn('fingerprint', 'string', ['limit' => 512, 'null' => false, 'comment' => 'INSTRUME|TELESCOP|CAMERAID|XBINxYBIN|GAIN|XPIXSZ, upper/trimmed'])
                ->addColumn('label', 'string', ['limit' => 255, 'null' => true, 'default' => null])
                ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addIndex(['project_id', 'fingerprint'], ['unique' => true])
                ->create();
        }

        if (!$this->hasTable('project_panels')) {
            $panels = $this->table('project_panels');
            $panels->addColumn('setup_id', 'integer', ['null' => false, 'signed' => true])
                ->addColumn('ra', 'decimal', ['precision' => 11, 'scale' => 7, 'null' => true, 'default' => null, 'comment' => 'Panel center RA (degrees)'])
                ->addColumn('dec', 'decimal', ['precision' => 10, 'scale' => 7, 'null' => true, 'default' => null, 'comment' => 'Panel center Dec (degrees)'])
                ->addColumn('rot_mean', 'float', ['null' => true, 'default' => null, 'comment' => 'Mean OBJCTROT (degrees, 0-360)'])
                ->addColumn('fov_w', 'float', ['null' => true, 'default' => null, 'comment' => 'Field of view width (arcmin)'])
                ->addColumn('fov_h', 'float', ['null' => true, 'default' => null, 'comment' => 'Field of view height (arcmin)'])
                ->addColumn('label_object', 'string', ['limit' => 255, 'null' => true, 'default' => null, 'comment' => 'Display-only OBJECT label, most frequent in cluster'])
                ->addForeignKey('setup_id', 'project_setups', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addIndex(['setup_id'])
                ->create();
        }

        if (!$this->hasTable('project_sessions')) {
            $sessions = $this->table('project_sessions');
            $sessions->addColumn('panel_id', 'integer', ['null' => false, 'signed' => true])
                ->addColumn('astro_night', 'date', ['null' => false, 'comment' => 'Astro-night (noon-to-noon) of DATE_OBS'])
                ->addForeignKey('panel_id', 'project_panels', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addIndex(['panel_id', 'astro_night'], ['unique' => true])
                ->create();
        }

        if (!$this->hasTable('project_files') && $this->hasTable('files')) {
            // Logical links only: one file can appear at several levels
            // (e.g. a master dark shared at setup level + lights at filter level).
            $links = $this->table('project_files', ['id' => false, 'primary_key' => ['file_id', 'level', 'node_id']]);
            $links->addColumn('file_id', 'integer', ['null' => false, 'signed' => true])
                ->addColumn('level', 'string', ['limit' => 16, 'null' => false, 'comment' => 'project|setup|panel|session|filter'])
                ->addColumn('node_id', 'integer', ['null' => false, 'signed' => true, 'comment' => 'Parent row id: projects.id for level=project, project_setups.id for setup, project_panels.id for panel, project_sessions.id for session/filter (filter level uses filter_name too)'])
                ->addColumn('filter_name', 'string', ['limit' => 50, 'null' => true, 'default' => null, 'comment' => 'Only for level=filter'])
                ->addColumn('role', 'string', ['limit' => 16, 'null' => false, 'default' => 'sub', 'comment' => 'sub|master'])
                ->addColumn('is_light', 'boolean', ['null' => false, 'default' => false])
                ->addForeignKey('file_id', 'files', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addIndex(['level', 'node_id'])
                ->addIndex(['file_id'])
                ->create();
        }

        if (!$this->hasTable('setup_overrides') && $this->hasTable('files')) {
            $overrides = $this->table('setup_overrides', ['id' => false, 'primary_key' => ['file_id']]);
            $overrides->addColumn('file_id', 'integer', ['null' => false, 'signed' => true])
                ->addColumn('setup_id', 'integer', ['null' => false, 'signed' => true])
                ->addForeignKey('file_id', 'files', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('setup_id', 'project_setups', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->create();
        }

        if (!$this->hasTable('panel_merges')) {
            $merges = $this->table('panel_merges');
            $merges->addColumn('panel_a', 'integer', ['null' => false, 'signed' => true])
                ->addColumn('panel_b', 'integer', ['null' => false, 'signed' => true])
                ->addColumn('action', 'string', ['limit' => 16, 'null' => false, 'comment' => 'merge|split'])
                ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('panel_a', 'project_panels', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->addForeignKey('panel_b', 'project_panels', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
                ->create();
        }

        if (!$this->hasTable('global_settings')) {
            $settings = $this->table('global_settings', ['id' => false, 'primary_key' => ['setting_key']]);
            $settings->addColumn('setting_key', 'string', ['limit' => 64, 'null' => false])
                ->addColumn('setting_value', 'string', ['limit' => 64, 'null' => false])
                ->create();

            $this->table('global_settings')->insert([
                ['setting_key' => 'tol_exp_dark', 'setting_value' => '10%'],
                ['setting_key' => 'tol_temp', 'setting_value' => '2C'],
                ['setting_key' => 'tol_rot', 'setting_value' => '3deg'],
                ['setting_key' => 'tol_pos_arcmin', 'setting_value' => '5'],
                ['setting_key' => 'tol_pos_fovfrac', 'setting_value' => '0.2'],
                ['setting_key' => 'tol_fov', 'setting_value' => '10%'],
            ])->saveData();
        }
    }
}
