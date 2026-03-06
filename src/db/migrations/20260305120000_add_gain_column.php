<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddGainColumn extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('files')) {
            $this->execute(
                "ALTER TABLE `files` ADD COLUMN IF NOT EXISTS `gain` FLOAT NULL COMMENT 'Camera gain setting (GAIN FITS header)' AFTER `egain`"
            );
        }
    }
}
