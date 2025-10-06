<?php
function connectDB(): PDO
{
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $conn = new PDO($dsn, DB_USER, DB_PASSWORD, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
        return $conn;
    } catch (PDOException $e) {
        die(str_replace('{0}', $e->getMessage(), __('db_connection_error')));
    }
}

function getFolders(PDO $conn, string $currentDir): array
{
    $folders = [];
    $dirPattern = $currentDir === '' ? '%' : $currentDir . '/%';
    $dirPrefix = $currentDir === '' ? '' : $currentDir . '/';

    $stmt = $conn->prepare("
        SELECT DISTINCT
            CASE
                WHEN :dir_param = '' THEN 
                    CASE 
                        WHEN LOCATE('/', path) > 0 THEN SUBSTRING_INDEX(path, '/', 1)
                        ELSE NULL
                    END
                ELSE SUBSTRING_INDEX(SUBSTRING(path, LENGTH(:dir_prefix) + 1), '/', 1)
            END as folder
        FROM files
        WHERE path LIKE :like_pattern AND deleted_at IS NULL AND SUBSTRING(path, LENGTH(:dir_prefix2) + 1) LIKE '%/%'
        HAVING folder IS NOT NULL AND folder != ''
        ORDER BY folder
    ");
    $stmt->bindValue(':dir_param', $currentDir, PDO::PARAM_STR);
    $stmt->bindValue(':dir_prefix', $dirPrefix, PDO::PARAM_STR);
    $stmt->bindValue(':dir_prefix2', $dirPrefix, PDO::PARAM_STR);
    $stmt->bindValue(':like_pattern', $dirPattern, PDO::PARAM_STR);
    $stmt->execute();
    
    while ($row = $stmt->fetch()) {
        if (!empty($row['folder'])) {
            $folders[] = $row['folder'];
        }
    }
    return $folders;
}

function countFiles(PDO $conn, string $dir, string $object, string $filter, string $imgtype, string $dateObsFrom, string $dateObsTo): int
{
    list($sql, $params) = buildQueryParts($dir, $object, $filter, $imgtype, $dateObsFrom, $dateObsTo);
    
    $countSql = "SELECT COUNT(*) as cnt FROM files WHERE " . implode(' AND ', $sql);

    $stmt = $conn->prepare($countSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $result = $stmt->fetch();
    return (int)($result['cnt'] ?? 0);
}

function getFiles(PDO $conn, string $dir, string $object, string $filter, string $imgtype, string $dateObsFrom, string $dateObsTo, int $perPage, int $offset, string $sortBy, string $sortOrder): array
{
        // Validazione e sanitizzazione di sortBy e sortOrder
    $allowedSortBy = [
        'name', 'path', 'object', 'date_obs', 'exptime', 'filter', 'imgtype', 
        'xbinning', 'ybinning', 'egain', 'offset', 'xpixsz', 'ypixsz', 'instrume', 
        'set_temp', 'ccd_temp', 'telescop', 'focallen', 'focratio', 'ra', 'dec', 
        'centalt', 'centaz', 'airmass', 'pierside', 'siteelev', 'sitelat', 'sitelong', 
                'focpos', 'visible_duplicate_count', 'mtime', 'file_hash', 'file_size',
        // New sortable columns
        'date_avg', 'swcreate', 'objctra', 'objctdec', 'cameraid', 'usblimit', 
        'fwheel', 'focname', 'focussz', 'foctemp', 'objctrot', 'roworder', 'equinox'
    ];
    $allowedSortOrder = ['ASC', 'DESC'];

    $sortBy = in_array($sortBy, $allowedSortBy) ? $sortBy : 'name';
    $sortOrder = in_array(strtoupper($sortOrder), $allowedSortOrder) ? strtoupper($sortOrder) : 'ASC';

    list($sqlConditions, $params) = buildQueryParts($dir, $object, $filter, $imgtype, $dateObsFrom, $dateObsTo);
    
    $sql = "SELECT * FROM files WHERE " . implode(' AND ', $sqlConditions) . " ORDER BY " . $sortBy . " " . $sortOrder . " LIMIT :per_page OFFSET :offset";

    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':per_page', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Get distinct values for a column, applying already selected filters.
 * This makes filter dropdowns dynamic and interdependent.
 */
function getDistinctValues(PDO $conn, string $column, string $dir, string $currentObject, string $currentFilter, string $currentImgtype): array
{
    $values = [];
    $dirPattern = $dir === '' ? '%' : $dir . '/%';
    
    // Validazione della colonna per prevenire SQL injection
    $allowedColumns = ['object', 'filter', 'imgtype'];
    if (!in_array($column, $allowedColumns)) {
        throw new InvalidArgumentException('Invalid column name');
    }
    
    // Costruisci la query base con i filtri già attivi
    $sql = "SELECT DISTINCT " . $column . " FROM files WHERE path LIKE :dir_pattern AND deleted_at IS NULL";

    // Aggiungi gli altri filtri, ma escludi la colonna che stiamo filtrando ora
    if ($column !== 'object' && $currentObject !== '') $sql .= " AND object = :object";
    if ($column !== 'filter' && $currentFilter !== '') $sql .= " AND filter = :filter";
    if ($column !== 'imgtype' && $currentImgtype !== '') $sql .= " AND imgtype = :imgtype";
    
    $sql .= " AND " . $column . " IS NOT NULL AND " . $column . " != '' ORDER BY " . $column;
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':dir_pattern', $dirPattern, PDO::PARAM_STR);
    
    if ($column !== 'object' && $currentObject !== '') $stmt->bindValue(':object', $currentObject, PDO::PARAM_STR);
    if ($column !== 'filter' && $currentFilter !== '') $stmt->bindValue(':filter', $currentFilter, PDO::PARAM_STR);
    if ($column !== 'imgtype' && $currentImgtype !== '') $stmt->bindValue(':imgtype', $currentImgtype, PDO::PARAM_STR);

    $stmt->execute();
    while ($r = $stmt->fetch()) {
        $values[] = $r[$column];
    }
    return $values;
}

function sumExposureTime(PDO $conn, string $dir, string $object, string $filter, string $imgtype, string $dateObsFrom, string $dateObsTo): float
{
    list($sql, $params) = buildQueryParts($dir, $object, $filter, $imgtype, $dateObsFrom, $dateObsTo);
    
    $sumSql = "SELECT SUM(exptime) as total_exposure FROM files WHERE " . implode(' AND ', $sql);

    $stmt = $conn->prepare($sumSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $result = $stmt->fetch();
    return (float)($result['total_exposure'] ?? 0);
}

function buildQueryParts(string $dir, string $object, string $filter, string $imgtype, string $dateObsFrom, string $dateObsTo): array
{
    $sql = [
        "is_hidden = 0",
        "deleted_at IS NULL",
        "path LIKE :dir_pattern"
    ];
    $params = [
        ':dir_pattern' => ($dir === '' ? '%' : $dir . '/%')
    ];

    if ($object !== '') {
        $sql[] = "object = :object";
        $params[':object'] = $object;
    }
    if ($filter !== '') {
        $sql[] = "filter = :filter";
        $params[':filter'] = $filter;
    }
    if ($imgtype !== '') {
        $sql[] = "imgtype = :imgtype";
        $params[':imgtype'] = $imgtype;
    }
    if ($dateObsFrom !== '') {
        $sql[] = "DATE(date_obs) >= :date_obs_from";
        $params[':date_obs_from'] = $dateObsFrom;
    }
    if ($dateObsTo !== '') {
        $sql[] = "DATE(date_obs) <= :date_obs_to";
        $params[':date_obs_to'] = $dateObsTo;
    }

    return [$sql, $params];
}

function getDuplicatesByHash(PDO $conn, string $hash): array
{
    $stmt = $conn->prepare("SELECT id, path, name, file_hash, mtime, is_hidden FROM files WHERE file_hash = :hash AND deleted_at IS NULL ORDER BY path");
    $stmt->bindValue(':hash', $hash, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchAll();
}

