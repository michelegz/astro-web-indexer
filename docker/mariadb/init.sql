CREATE TABLE IF NOT EXISTS `files` (
    -- Primary Identifiers
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `path` VARCHAR(768) NOT NULL UNIQUE,
    `file_hash` VARCHAR(16) NULL,

    -- File Information
    `name` VARCHAR(255) NOT NULL,
    `mtime` BIGINT NULL,
    `file_size` BIGINT NULL,

    -- Object Data
    `object` VARCHAR(255) NULL,
    `objctra` VARCHAR(255) NULL COMMENT 'Object Right Ascension (H M S)',
    `objctdec` VARCHAR(255) NULL COMMENT 'Object Declination (D M S)',

    -- Exposure Data
    `imgtype` VARCHAR(50) NULL,
    `exptime` FLOAT NULL,
    `date_obs` DATETIME NULL COMMENT 'Observation time (UTC) at start of exposure',
    `date_avg` DATETIME NULL COMMENT 'Observation time (UTC) at mid-point of exposure',
    `filter` VARCHAR(50) NULL,

    -- Sensor Data
    `xbinning` INT NULL,
    `ybinning` INT NULL,
    `egain` FLOAT NULL,
    `offset` FLOAT NULL,
    `xpixsz` FLOAT NULL,
    `ypixsz` FLOAT NULL,
    `set_temp` FLOAT NULL,
    `ccd_temp` FLOAT NULL,

    -- Equipment Data
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

    -- Pointing & Position Data
    `ra` DOUBLE NULL COMMENT 'Telescope Right Ascension (degrees)',
    `dec` DOUBLE NULL COMMENT 'Telescope Declination (degrees)',
    `centalt` FLOAT NULL,
    `centaz` FLOAT NULL,
    `airmass` FLOAT NULL,
    `pierside` VARCHAR(50) NULL,
    `objctrot` FLOAT NULL,

    -- Observatory Site Data
    `siteelev` FLOAT NULL,
    `sitelat` DOUBLE NULL,
    `sitelong` DOUBLE NULL,

    -- File Metadata
    `swcreate` VARCHAR(255) NULL,
    `roworder` VARCHAR(50) NULL,
    `equinox` FLOAT NULL,

    -- App Internal Data
    `thumb` MEDIUMBLOB NULL,
    `total_duplicate_count` INT NOT NULL DEFAULT 1,
    `visible_duplicate_count` INT NOT NULL DEFAULT 1,
    `is_hidden` BOOLEAN NOT NULL DEFAULT FALSE,
    `deleted_at` DATETIME NULL,

    -- Indexes for performance
    INDEX `idx_path` (`path`),
    INDEX `idx_file_hash` (`file_hash`),
    INDEX `idx_object` (`object`),
    INDEX `idx_date_obs` (`date_obs`),
    INDEX `idx_imgtype` (`imgtype`),
    INDEX `idx_filter` (`filter`),
    INDEX `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;