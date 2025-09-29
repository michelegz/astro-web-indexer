CREATE TABLE IF NOT EXISTS files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    path VARCHAR(1024) NOT NULL,
    object VARCHAR(255),
    date_obs DATETIME,
    exptime FLOAT,
    filter VARCHAR(50),
    imgtype VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_path (path),
    INDEX idx_object (object),
    INDEX idx_filter (filter),
    INDEX idx_imgtype (imgtype)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;