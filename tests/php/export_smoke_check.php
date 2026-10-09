<?php
// Smoke test §11 on the real path: buildProjectExportMap on a real project,
// to verify that the sanitized basenames do not break the map and that
// validateExportTokens still accepts everything.
require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
require_once '/var/www/html/includes/projects_functions.php';
require_once '/var/www/html/includes/projects_diagnostics.php';
require_once '/var/www/html/includes/project_export.php';

$_SESSION = ['user_id' => 1, 'username' => 'admin', 'is_admin' => 1,
    'can_download' => 1, 'allowed_dirs' => ['/']];

$conn = connectDB();
$pid = (int)$conn->query("SELECT id FROM projects ORDER BY id LIMIT 1")->fetchColumn();
if (!$pid) {
    echo "no project: skipped\n";
    exit(0);
}
$name = $conn->query("SELECT name FROM projects WHERE id = $pid")->fetchColumn();
echo "project $pid ($name)\n";

$map = buildProjectExportMap($conn, $pid);
$entries = $map['entries'];
printf("  emitted entries: %d, distinct folders: %d, skipped: %d, duplicates: %d\n",
    count($entries),
    count(array_unique(array_map(fn($e) => dirname($e['zip_path']), $entries))),
    count($map['skipped']), count($map['duplicated_files']));

// the validator must accept the map
validateExportTokens($entries);
echo "  validateExportTokens: OK\n";

// no dangerous basename
$bad = [];
foreach ($entries as $e) {
    $b = basename($e['zip_path']);
    if (preg_match('/[\x00-\x1F<>:"|?*]/', $b) || preg_match('/[ .]$/', $b)
        || str_contains($b, '/') || str_contains($b, '\\')) {
        $bad[] = $b;
    }
}
printf("  unsafe basenames: %d%s\n", count($bad),
    $bad ? ' (' . implode(', ', array_slice($bad, 0, 3)) . ')' : '');

// no overwrite at extraction: the paths must be unique case-folded
$folded = array_map(fn($p) => mb_strtolower($p), array_column($entries, 'zip_path'));
$dupes = array_filter(array_count_values($folded), fn($c) => $c > 1);
printf("  duplicate paths (case-folded): %d\n", count($dupes));

// a sample of the names, for visual inspection
echo "  sample:\n";
foreach (array_slice($entries, 0, 5) as $e) {
    echo '    ' . $e['zip_path'] . "\n";
}

$ok = count($bad) === 0 && count($dupes) === 0;
echo "\nRESULT: " . ($ok ? 'real path intact' : 'THERE ARE PROBLEMS') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed the failure verdict.
exit($ok ? 0 : 1);