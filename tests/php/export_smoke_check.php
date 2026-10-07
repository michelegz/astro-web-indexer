<?php
// Smoke test §11 sul percorso reale: buildProjectExportMap su un progetto vero,
// per verificare che i basename sanificati non rompano la mappa e che il
// validateExportTokens accetti ancora tutto.
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
    echo "nessun progetto: skip\n";
    exit(0);
}
$name = $conn->query("SELECT name FROM projects WHERE id = $pid")->fetchColumn();
echo "progetto $pid ($name)\n";

$map = buildProjectExportMap($conn, $pid);
$entries = $map['entries'];
printf("  voci emesse: %d, cartelle distinte: %d, saltate: %d, duplicati: %d\n",
    count($entries),
    count(array_unique(array_map(fn($e) => dirname($e['zip_path']), $entries))),
    count($map['skipped']), count($map['duplicated_files']));

// il validatore deve accettare la mappa
validateExportTokens($entries);
echo "  validateExportTokens: OK\n";

// nessun basename pericoloso
$bad = [];
foreach ($entries as $e) {
    $b = basename($e['zip_path']);
    if (preg_match('/[\x00-\x1F<>:"|?*]/', $b) || preg_match('/[ .]$/', $b)
        || str_contains($b, '/') || str_contains($b, '\\')) {
        $bad[] = $b;
    }
}
printf("  basename non sicuri: %d%s\n", count($bad),
    $bad ? ' (' . implode(', ', array_slice($bad, 0, 3)) . ')' : '');

// nessuna sovrascrittura all'estrazione: i path devono essere unici case-folded
$folded = array_map(fn($p) => mb_strtolower($p), array_column($entries, 'zip_path'));
$dupes = array_filter(array_count_values($folded), fn($c) => $c > 1);
printf("  path duplicati (case-folded): %d\n", count($dupes));

// un estratto dei nomi, per controllo visivo
echo "  estratto:\n";
foreach (array_slice($entries, 0, 5) as $e) {
    echo '    ' . $e['zip_path'] . "\n";
}

$ok = count($bad) === 0 && count($dupes) === 0;
echo "\nRISULTATO: " . ($ok ? 'percorso reale intatto' : 'CI SONO PROBLEMI');