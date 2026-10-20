<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Remember that a row still owes its star metrics.
 *
 * The indexer runs in two passes: the fast one writes the row (header,
 * preview, frame statistics) so the file shows up in the frontend, the slow
 * one computes the star metrics (sep over the whole frame). This column says
 * which of the two a row is waiting for.
 *
 * 1 = the metrics are still owed, 0 = the row is complete.
 *
 * The default is 0 on purpose: the rows indexed before this migration are
 * complete, and a fresh installation must not start with every row pending.
 * A row that changes is set back to 1 by the fast pass and cleared by the
 * slow one; a run interrupted in between leaves the flag at 1, so the next
 * run picks the file up again instead of forgetting it.
 */
final class AddMetricsPending extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('files')) {
            return;
        }
        $files = $this->table('files');
        if (!$files->hasColumn('metrics_pending')) {
            $files->addColumn('metrics_pending', 'boolean', [
                'default' => false,
                'null' => false,
                'comment' => '1 = the star metrics are still owed for this row',
            ])->update();
        }
    }
}
