<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Single exposure tolerance: tol_exp_dark is merged into tol_exp.
 *
 * Dark/light exposure matching, clustering and export folders now share one
 * variable. Stored overrides of the old key are dropped (projects fall back
 * to the global tol_exp default of 1%).
 */
final class RemoveDarkExpTol extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('global_settings')) {
            $this->execute("DELETE FROM global_settings WHERE setting_key = 'tol_exp_dark'");
        }
        if ($this->hasTable('projects')) {
            $this->execute(
                "UPDATE projects SET tolerances = JSON_REMOVE(tolerances, '$.tol_exp_dark') "
                . "WHERE JSON_CONTAINS_PATH(tolerances, 'one', '$.tol_exp_dark')"
            );
        }
    }
}
