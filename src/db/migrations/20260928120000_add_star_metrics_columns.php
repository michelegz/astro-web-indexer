<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddStarMetricsColumns extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('files')) {
            // Star/quality metrics computed at index time by the Python indexer
            // (sep-based, LIGHT frames only). All NULLable: NULL means "not computed".
            $this->execute(
                "ALTER TABLE `files` " .
                "ADD COLUMN IF NOT EXISTS `hfr_avg` FLOAT NULL COMMENT 'Mean half-flux radius in pixels (sep)', " .
                "ADD COLUMN IF NOT EXISTS `fwhm_avg` FLOAT NULL COMMENT 'Mean FWHM in pixels (approx 2xHFR)', " .
                "ADD COLUMN IF NOT EXISTS `ecc_avg` FLOAT NULL COMMENT 'Mean eccentricity 0-1 (sep)', " .
                "ADD COLUMN IF NOT EXISTS `star_count` INT NULL COMMENT 'Number of accepted stars (sep)', " .
                "ADD COLUMN IF NOT EXISTS `snr_weight` FLOAT NULL COMMENT 'SubframeSelector-style SNR weight (MAD^2/noise^2)', " .
                "ADD COLUMN IF NOT EXISTS `psf_signal` FLOAT NULL COMMENT 'PixInsight-style PSF signal weight'"
            );
        }
    }
}
