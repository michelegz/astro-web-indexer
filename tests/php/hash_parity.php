<?php
declare(strict_types=1);

// Parity gate for Fix 3: the PHP and Python suggestion hashes must agree byte
// for byte, otherwise every dismissal goes stale on every suggest pass and the
// feature is worse than useless. Emits the same shape as tmp/hash_parity.py.

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/language_functions.php';
require_once __DIR__ . '/includes/language.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/projects_functions.php';

while (ob_get_level() > 0) {
    ob_end_clean();
}

$out = ['tricky_tokens' => [], 'projects' => [], 'files' => []];

// Same tricky spellings as the Python side.
foreach (['5', '5.0', ' 5.00 ', '05', '0.5', '.5', '-.5', '10%', '3deg', '2C', 'auto', '', '-', '.', '-.', '0', '0.0'] as $t) {
    $out['tricky_tokens'][$t] = projectSuggestTolToken($t);
}

$conn = connectDB();
$files = $conn->query(
    "SELECT id, path FROM files WHERE deleted_at IS NULL "
    . "AND imgtype IN ('LIGHT','DARK','FLAT','BIAS') ORDER BY id LIMIT 5"
)->fetchAll();

foreach ($conn->query("SELECT id, name FROM projects ORDER BY id")->fetchAll() as $p) {
    $entry = ['id' => (int)$p['id'], 'config_hash' => getProjectSuggestConfigHash($conn, (int)$p['id'])];
    if ($files) {
        $entry['match_inputs'] = getProjectSuggestMatchInputs($conn, (int)$files[0]['id']);
    }
    $out['projects'][] = $entry;
}
foreach ($files as $f) {
    $out['files'][] = [
        'id' => (int)$f['id'],
        'path' => (string)$f['path'],
        'match_inputs' => getProjectSuggestMatchInputs($conn, (int)$f['id']),
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
