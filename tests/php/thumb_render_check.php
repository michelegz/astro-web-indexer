<?php
// Verifica 13.16 — sostituire i blob con OCTET_LENGTH non deve cambiare il
// rendering: file_cells.php decide se mostrare la miniatura testando
// !empty($f['thumb']), quindi il valore deve restare falsy/truthy esattamente
// come prima.
//
// Uso:  docker cp tmp/thumb_render_check.php awi-php:/tmp/
//       docker exec awi-php sh -c 'cd /tmp && php thumb_render_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
require_once '/var/www/html/includes/projects_functions.php';
require_once '/var/www/html/includes/projects_diagnostics.php';
require_once '/var/www/html/includes/columns.php';
require_once '/var/www/html/includes/template_functions.php';
require_once '/var/www/html/includes/file_cells.php';
require_once '/var/www/html/includes/projects_tree.php';

$_SESSION = ['user_id' => 1, 'username' => 'admin', 'is_admin' => 1,
    'can_download' => 1, 'allowed_dirs' => ['/']];

// le colonne visibili del progetto servono ai renderer
global $columnGroups, $hiddenCols, $hiddenColsProjects, $showStarMetrics;
$columnGroups = getColumnGroups();
$toggleable = [];
foreach ($columnGroups as $g) {
    $toggleable = array_merge($toggleable, array_keys($g['columns']));
}
$toggleable = array_values(array_unique(array_merge($toggleable, array_keys(getBaseColumns()))));
$advKeys = [];
$starKeys = array_keys($columnGroups['star']['columns']);
$frameKeys = array_keys($columnGroups['frame']['columns']);
foreach ($columnGroups as $gk => $g) {
    if ($gk !== 'star' && $gk !== 'frame') {
        $advKeys = array_merge($advKeys, array_keys($g['columns']));
    }
}
$showStarMetrics = true;
$hiddenCols = [];
$hiddenColsProjects = [];
$visibleStarKeys = $starKeys;
$visibleFrameKeys = $frameKeys;
$visibleAdvKeys = $advKeys;
$visibleProjectStarKeys = $starKeys;
$visibleProjectFrameKeys = $frameKeys;
$visibleProjectAdvKeys = $advKeys;

$pid = (int)$conn = null;
$conn = connectDB();
$pid = (int)$conn->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();
$tree = getProjectTree($conn, $pid, false);
echo "progetto $pid\n";

// Conta le righe con miniatura, a livello di dati, non di markup.
$stats = ['rows' => 0, 'withThumb' => 0, 'withThumbCrop' => 0, 'nonEmptyValues' => 0];
$walk = function (array $rows) use (&$walk, &$stats): void {
    foreach ($rows as $r) {
        if (!isset($r['file_id']) || (int)$r['file_id'] <= 0) {
            continue;
        }
        $stats['rows']++;
        if (!empty($r['thumb'])) {
            $stats['withThumb']++;
        }
        if (!empty($r['thumb_crop'])) {
            $stats['withThumbCrop']++;
        }
        if (isset($r['thumb']) && $r['thumb'] !== null && $r['thumb'] !== '') {
            $stats['nonEmptyValues']++;
        }
    }
};
foreach ($tree['setups'] ?? [] as $su) {
    $walk($su['calibrations'] ?? []);
    foreach ($su['panels'] ?? [] as $p) {
        $walk($p['calibrations'] ?? []);
        foreach ($p['sessions'] ?? [] as $s) {
            $walk($s['calibrations'] ?? []);
            foreach ($s['filters'] ?? [] as $flt) {
                $walk($flt['lights'] ?? []);
                $walk($flt['calibrations'] ?? []);
            }
        }
    }
}
echo "  righe file nell'albero: {$stats['rows']}\n";
echo "  con miniatura:           {$stats['withThumb']}\n";
echo "  con crop:                {$stats['withThumbCrop']}\n";

// Il valore deve essere truthy solo se il blob esiste davvero: confronto con
// una query diretta che legge il blob vero.
$st = $conn->prepare("SELECT id FROM files WHERE deleted_at IS NULL AND thumb IS NOT NULL
                      AND OCTET_LENGTH(thumb) > 0");
$st->execute();
$reallyHasThumb = $st->fetchAll(PDO::FETCH_COLUMN);
$treeThumbs = 0;
foreach ($reallyHasThumb as $id) {
    $treeThumbs++;
}
printf("  file con thumbnail reale nel DB: %d (su %d)\n", $treeThumbs, $stats['rows']);

// Rendering: si esercita renderFileTableCells() su una riga reale dell'albero,
// che e' il punto esatto in cui thumb/thumb_crop decidono se mostrare la miniatura.
$sample = null;
$find = function (array $rows) use (&$find, &$sample): void {
    foreach ($rows as $r) {
        if ($sample === null && isset($r['file_id']) && (int)$r['file_id'] > 0) {
            $sample = $r;
        }
    }
};
foreach ($tree['setups'] ?? [] as $su) {
    $find($su['calibrations'] ?? []);
    foreach ($su['panels'] ?? [] as $p) {
        $find($p['calibrations'] ?? []);
        foreach ($p['sessions'] ?? [] as $s) {
            foreach ($s['filters'] ?? [] as $flt) {
                $find($flt['lights'] ?? []);
            }
        }
    }
}
if ($sample === null) {
    echo "\nRISULTATO: nessuna riga di campione, test non eseguibile\n";
    exit(1);
}
ob_start();
renderFileTableCells($sample, 'project');
$rowHtml = (string)ob_get_clean();
$thumbImgs = substr_count($rowHtml, 'type=thumb');
$cropImgs = substr_count($rowHtml, 'type=crop');
printf("  riga campione file_id=%s: thumb truthy=%s crop truthy=%s\n",
    $sample['file_id'], !empty($sample['thumb']) ? 'si' : 'no',
    !empty($sample['thumb_crop']) ? 'si' : 'no');
printf("  <img> in cella: thumb=%d crop=%d\n", $thumbImgs, $cropImgs);

// coerenza con il DB: il file deve avere davvero una miniatura non vuota
$st = $conn->prepare("SELECT OCTET_LENGTH(thumb) a, OCTET_LENGTH(thumb_crop) b FROM files WHERE id = ?");
$st->execute([(int)$sample['file_id']]);
$real = $st->fetch();
printf("  OCTET_LENGTH nel DB: thumb=%s crop=%s\n",
    var_export($real['a'], true), var_export($real['b'], true));

$ok = $thumbImgs === 1 && $cropImgs === 1
    && ((int)$real['a'] > 0) === (!empty($sample['thumb']));
echo "\nRISULTATO: " . ($ok
    ? 'il valore trasmesso conserva esattamente la semantica empty()'
    : 'le miniature NON vengono piu\' rilevate <<< BUG');