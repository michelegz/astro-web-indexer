<?php
function connectDB(): PDO
{
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $conn = new PDO($dsn, DB_USER, DB_PASS, [
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
        WHERE path LIKE :like_pattern
        HAVING folder IS NOT NULL AND folder != ''
        ORDER BY folder
    ");
    $stmt->bindValue(':dir_param', $currentDir, PDO::PARAM_STR);
    $stmt->bindValue(':dir_prefix', $currentDir === '' ? '' : $currentDir . '/', PDO::PARAM_STR);
    $stmt->bindValue(':like_pattern', $dirPattern, PDO::PARAM_STR);
    $stmt->execute();
    
    while ($row = $stmt->fetch()) {
        if (!empty($row['folder'])) {
            $folders[] = $row['folder'];
        }
    }
    return $folders;
}

function countFiles(PDO $conn, string $dir, string $object, string $filter, string $imgtype): int
{
    $dirPattern = $dir === '' ? '%' : $dir . '/%';
    $countSql = "SELECT COUNT(*) as cnt FROM files WHERE path LIKE :dir_pattern";
    if ($object !== '') $countSql .= " AND object = :object";
    if ($filter !== '') $countSql .= " AND filter = :filter";
    if ($imgtype !== '') $countSql .= " AND imgtype = :imgtype";

    $stmt = $conn->prepare($countSql);
    $stmt->bindValue(':dir_pattern', $dirPattern, PDO::PARAM_STR);
    if ($object !== '') $stmt->bindValue(':object', $object, PDO::PARAM_STR);
    if ($filter !== '') $stmt->bindValue(':filter', $filter, PDO::PARAM_STR);
    if ($imgtype !== '') $stmt->bindValue(':imgtype', $imgtype, PDO::PARAM_STR);

    $stmt->execute();
    $result = $stmt->fetch();
    return (int)($result['cnt'] ?? 0);
}

function getFiles(PDO $conn, string $dir, string $object, string $filter, string $imgtype, int $perPage, int $offset, string $sortBy, string $sortOrder): array
{
    $files = [];
    $dirPattern = $dir === '' ? '%' : $dir . '/%';
    
    // Validazione e sanitizzazione di sortBy e sortOrder
    $allowedSortBy = ['name', 'path', 'object', 'date_obs', 'exptime', 'filter', 'imgtype'];
    $allowedSortOrder = ['ASC', 'DESC'];

    $sortBy = in_array($sortBy, $allowedSortBy) ? $sortBy : 'name';
    $sortOrder = in_array(strtoupper($sortOrder), $allowedSortOrder) ? strtoupper($sortOrder) : 'ASC';

    $sql = "SELECT * FROM files WHERE path LIKE :dir_pattern";
    if ($object !== '') $sql .= " AND object = :object";
    if ($filter !== '') $sql .= " AND filter = :filter";
    if ($imgtype !== '') $sql .= " AND imgtype = :imgtype";
    $sql .= " ORDER BY " . $sortBy . " " . $sortOrder . " LIMIT :per_page OFFSET :offset";

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':dir_pattern', $dirPattern, PDO::PARAM_STR);
    if ($object !== '') $stmt->bindValue(':object', $object, PDO::PARAM_STR);
    if ($filter !== '') $stmt->bindValue(':filter', $filter, PDO::PARAM_STR);
    if ($imgtype !== '') $stmt->bindValue(':imgtype', $imgtype, PDO::PARAM_STR);
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
    $sql = "SELECT DISTINCT " . $column . " FROM files WHERE path LIKE :dir_pattern";

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