<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class InitialSchema extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change(): void
    {
        // Check if the table already exists to prevent dropping data
        if ($this->hasTable('files')) {
            // If the table exists, this migration should do nothing.
            // It's a baseline for new installations only.
            // For existing installations, it will be marked as migrated manually.
            return;
        }

        // Creates the files table using SQL from the init.sql file
        $sql = <<<'SQL'
CREATE TABLE `files` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `path` VARCHAR(768) NOT NULL UNIQUE,
    `file_hash` VARCHAR(16) NULL,
    `name` VARCHAR(255) NOT NULL,
    `mtime` BIGINT NULL,
    `file_size` BIGINT NULL,
    `width` INT NULL COMMENT 'Image width in pixels',
    `height` INT NULL COMMENT 'Image height in pixels',
    `resolution` FLOAT NULL COMMENT 'Arcsec per pixel',
    `fov_w` FLOAT NULL COMMENT 'Field of View width in arcmin',
    `fov_h` FLOAT NULL COMMENT 'Field of View height in arcmin',
    `object` VARCHAR(255) NULL,
    `objctra` VARCHAR(255) NULL COMMENT 'Object Right Ascension (H M S)',
    `objctdec` VARCHAR(255) NULL COMMENT 'Object Declination (D M S)',
    `imgtype` VARCHAR(50) NULL,
    `exptime` FLOAT NULL,
    `date_obs` DATETIME NULL COMMENT 'Observation time (UTC) at start of exposure',
    `date_avg` DATETIME NULL COMMENT 'Observation time (UTC) at mid-point of exposure',
    `filter` VARCHAR(50) NULL,
    `xbinning` INT NULL,
    `ybinning` INT NULL,
    `egain` FLOAT NULL,
    `offset` FLOAT NULL,
    `xpixsz` FLOAT NULL,
    `ypixsz` FLOAT NULL,
    `set_temp` FLOAT NULL,
    `ccd_temp` FLOAT NULL,
    `instrume` VARCHAR(255) NULL,
    `cameraid` VARCHAR(255) NULL,
    `usblimit` INT NULL,
    `fwheel` VARCHAR(255) NULL,
    `telescop` VARCHAR(255) NULL,
    `focallen` FLOAT NULL,
    `focratio` FLOAT NULL,
    `focname` VARCHAR(255) NULL,
    `focpos` INT NULL,
    `focussz` FLOAT NULL,
    `foctemp` FLOAT NULL,
    `ra` DOUBLE NULL COMMENT 'Telescope Right Ascension (degrees)',
    `dec` DOUBLE NULL COMMENT 'Telescope Declination (degrees)',
    `centalt` FLOAT NULL,
    `centaz` FLOAT NULL,
    `airmass` FLOAT NULL,
    `pierside` VARCHAR(50) NULL,
    `objctrot` FLOAT NULL,
    `siteelev` FLOAT NULL,
    `sitelat` DOUBLE NULL,
    `sitelong` DOUBLE NULL,
    `swcreate` VARCHAR(255) NULL,
    `roworder` VARCHAR(50) NULL,
    `equinox` FLOAT NULL,
    `thumb` MEDIUMBLOB NULL COMMENT 'Resized preview thumbnail',
    `thumb_crop` MEDIUMBLOB NULL COMMENT 'Cropped 100% preview thumbnail',
    `total_duplicate_count` INT NOT NULL DEFAULT 1,
    `visible_duplicate_count` INT NOT NULL DEFAULT 1,
    `is_hidden` BOOLEAN NOT NULL DEFAULT FALSE,
    `deleted_at` DATETIME NULL,
    `data_schema_version` INT NOT NULL DEFAULT 1 COMMENT 'Version of the data schema for this row',
    
    -- Future fields for image quality & stats
    `fwhm` FLOAT NULL COMMENT 'Full Width at Half Maximum (arcsec)',
    `hfr` FLOAT NULL COMMENT 'Half-Flux Radius (pixels)',
    `hfr_sd` FLOAT NULL COMMENT 'HFR Standard Deviation (pixels)',
    `eccentricity` FLOAT NULL COMMENT 'Average star eccentricity',
    `star_count` INT NULL COMMENT 'Number of detected stars',
    `background_mean` FLOAT NULL COMMENT 'Mean background level (ADU)',
    `min_pixel` INT UNSIGNED NULL COMMENT 'Minimum pixel value (ADU)',
    `max_pixel` INT UNSIGNED NULL COMMENT 'Maximum pixel value (ADU)',
    `mean_pixel` FLOAT NULL COMMENT 'Mean pixel value (ADU)',
    `median_pixel` FLOAT NULL COMMENT 'Median pixel value (ADU)',
    `bit_depth` TINYINT UNSIGNED NULL COMMENT 'Bit depth of the image (e.g., 16)',
    `moon_phase` FLOAT NULL COMMENT 'Moon phase illumination percentage',
    `moon_angle` FLOAT NULL COMMENT 'Moon phase angle in degrees (0=New, 180=Full)',
    
    -- Workflow & Environmental Data
    `processing_status` VARCHAR(50) NULL COMMENT 'Processing status (e.g., RAW, CALIBRATED)',
    `guide_rms_total` FLOAT NULL COMMENT 'Guiding total RMS error (arcsec)',
    `ambient_temp` FLOAT NULL COMMENT 'Ambient temperature (Celsius)',
    `observatory_name` VARCHAR(255) NULL COMMENT 'Name of the observatory or location',

    `notes` TEXT NULL COMMENT 'User notes for the image',
    `is_favorite` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Favorite flag (e.g., for picking)',
    `is_rejected` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Rejected flag',
    `rating` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Star rating (0-5)',
    `color_tag` VARCHAR(20) NULL COMMENT 'Color tag/label (e.g., red, green, #FF0000)',
    
    INDEX `idx_path` (`path`),
    INDEX `idx_file_hash` (`file_hash`),
    INDEX `idx_object` (`object`),
    INDEX `idx_date_obs` (`date_obs`),
    INDEX `idx_imgtype` (`imgtype`),
    INDEX `idx_filter` (`filter`),
    INDEX `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
        $this->execute($sql);
    }
}
