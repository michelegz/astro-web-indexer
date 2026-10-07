<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Make dismissed suggestions re-evaluable when the ground shifts
 * (see tmp/project-fixes-scope-reset-dedup-resuggest-plan.md, decision 3A-3C).
 *
 * project_suggestions rows with status='dismissed' used to block any further
 * suggestion for that file forever. Two independent components are recorded so
 * a dismissal only survives while it is still meaningful:
 *
 * - config_hash: md5 of the effective tolerances the suggest path actually
 *   reads (tol_pos_arcmin, tol_pos_fovfrac, tol_rot, tol_fov). tol_exp and
 *   tol_temp are NOT part of it: the suggester never reads them, so they would
 *   resurrect rows with no possible match.
 * - match_inputs: canonical JSON of the RAW file header values feeding the
 *   matcher. Raw on purpose: no parsing is duplicated across PHP and Python,
 *   so the two sides cannot drift on RA/DEC or fingerprint formatting.
 *
 * Both NULL means "never evaluated" (row predates this migration) and counts as
 * stale, so existing dismissions are reconsidered exactly once.
 *
 * Portability: Table API only, no ENUM, no ENGINE-specific SQL. No new index:
 * (project_id, status) already covers the lookup.
 */
final class AddSuggestionStale extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('project_suggestions')) {
            $t = $this->table('project_suggestions');
            if (!$t->hasColumn('config_hash')) {
                $t->addColumn('config_hash', 'string', [
                    'limit' => 32,
                    'null' => true,
                    'default' => null,
                    'comment' => 'md5 of effective suggest-path tolerances; NULL = stale',
                ]);
            }
            if (!$t->hasColumn('match_inputs')) {
                $t->addColumn('match_inputs', 'text', [
                    'null' => true,
                    'default' => null,
                    'comment' => 'Canonical JSON of raw header values feeding the matcher; NULL = stale',
                ]);
            }
            $t->update();
        }
    }
}
