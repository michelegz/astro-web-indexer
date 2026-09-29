<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ConsolidateStarMetrics extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('files')) {
            return;
        }

        // The initial schema already had hfr/eccentricity/star_count placeholders
        // (plus fwhm in arcsec); the *_avg duplicates introduced later are folded
        // back into them. FWHM is converted pixels -> arcsec via resolution.
        $this->execute(
            "UPDATE `files` SET `hfr` = `hfr_avg` WHERE `hfr` IS NULL AND `hfr_avg` IS NOT NULL"
        );
        $this->execute(
            "UPDATE `files` SET `eccentricity` = `ecc_avg` WHERE `eccentricity` IS NULL AND `ecc_avg` IS NOT NULL"
        );
        $this->execute(
            "UPDATE `files` SET `fwhm` = `fwhm_avg` * `resolution` " .
            "WHERE `fwhm` IS NULL AND `fwhm_avg` IS NOT NULL " .
            "AND `resolution` IS NOT NULL AND `resolution` > 0"
        );
        $this->execute(
            "ALTER TABLE `files` DROP COLUMN IF EXISTS `hfr_avg`, " .
            "DROP COLUMN IF EXISTS `fwhm_avg`, DROP COLUMN IF EXISTS `ecc_avg`"
        );
    }
}
