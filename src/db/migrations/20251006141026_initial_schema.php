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
    `thumb` MEDIUMBLOB NULL,
    `total_duplicate_count` INT NOT NULL DEFAULT 1,
    `visible_duplicate_count` INT NOT NULL DEFAULT 1,
    `is_hidden` BOOLEAN NOT NULL DEFAULT FALSE,
    `deleted_at` DATETIME NULL,
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
