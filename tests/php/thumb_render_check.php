<?php
// Check 13.16 — replacing the blobs with OCTET_LENGTH must not change the
// rendering: file_cells.php decides whether to show the thumbnail by testing
// !empty($f['thumb']), so the value must stay falsy/truthy exactly
// as before.
//
// Usage:  docker cp tmp/thumb_render_check.php awi-php:/tmp/
//         docker exec awi-php sh -c 'cd /tmp && php thumb_render_check.php'

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

// the project's visible columns are needed by the renderers
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
echo "project $pid\n";

// Count the rows with a thumbnail, at the data level, not the markup level.
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
echo "  file rows in the tree: {$stats['rows']}\n";
echo "  with thumbnail:         {$stats['withThumb']}\n";
echo "  with crop:              {$stats['withThumbCrop']}\n";

// The value must be truthy only if the blob really exists: compare with
// a direct query that reads the real blob.
$st = $conn->prepare("SELECT id FROM files WHERE deleted_at IS NULL AND thumb IS NOT NULL
                      AND OCTET_LENGTH(thumb) > 0");
$st->execute();
$reallyHasThumb = $st->fetchAll(PDO::FETCH_COLUMN);
$treeThumbs = 0;
foreach ($reallyHasThumb as $id) {
    $treeThumbs++;
}
printf("  files with a real thumbnail in the DB: %d (over %d)\n", $treeThumbs, $stats['rows']);

// Rendering: renderFileTableCells() is exercised on a real row of the tree,
// which is the exact point where thumb/thumb_crop decide whether to show the thumbnail.
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
    echo "\nRESULT: no sample row, the test cannot run\n";
    exit(1);
}
ob_start();
renderFileTableCells($sample, 'project');
$rowHtml = (string)ob_get_clean();
$thumbImgs = substr_count($rowHtml, 'type=thumb');
$cropImgs = substr_count($rowHtml, 'type=crop');
printf("  sample row file_id=%s: thumb truthy=%s crop truthy=%s\n",
    $sample['file_id'], !empty($sample['thumb']) ? 'yes' : 'no',
    !empty($sample['thumb_crop']) ? 'yes' : 'no');
printf("  <img> in the cell: thumb=%d crop=%d\n", $thumbImgs, $cropImgs);

// consistency with the DB: the file must really have a non-empty thumbnail
$st = $conn->prepare("SELECT OCTET_LENGTH(thumb) a, OCTET_LENGTH(thumb_crop) b FROM files WHERE id = ?");
$st->execute([(int)$sample['file_id']]);
$real = $st->fetch();
printf("  OCTET_LENGTH in the DB: thumb=%s crop=%s\n",
    var_export($real['a'], true), var_export($real['b'], true));

$ok = $thumbImgs === 1 && $cropImgs === 1
    && ((int)$real['a'] > 0) === (!empty($sample['thumb']));
echo "\nRESULT: " . ($ok
    ? 'the transmitted value keeps exactly the empty() semantics'
    : 'thumbnails are NO LONGER detected <<< BUG') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed the failure verdict.
exit($ok ? 0 : 1);