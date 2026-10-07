<?php
// Verifica 13.8 — un progetto non deve sparire per via di una suggestion che
// l'utente non puo' vedere.
//
// Prima: canAccessProject() vietava il progetto se QUALSIASI suggestion pending
// o accepted puntava a un file fuori dalle directory consentite. L'indexer gira
// senza contesto utente, quindi puo' legittimamente avere proposals su file che
// questo utente non vede: il suo progetto spariva senza alcun messaggio.
//
// Dopo: le suggestion fuori scope non vietano il progetto e vengono filtrate
// dalla coda di revisione. I link restano un veto.
//
// Uso:  docker cp tmp/project_visibility_check.php awi-php:/tmp/
//       docker exec awi-php sh -c 'cd /tmp && php project_visibility_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
require_once '/var/www/html/includes/projects_functions.php';
require_once '/var/www/html/includes/projects_diagnostics.php';

$conn = connectDB();
$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-50s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FALLITO');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

/** Due cartelle top-level, cosi' lo scope e' distinguibile. */
$roots = $conn->query("SELECT DISTINCT SUBSTRING_INDEX(path, '/', 1) r FROM files
                       WHERE deleted_at IS NULL AND path LIKE '%/%' LIMIT 2")
    ->fetchAll(PDO::FETCH_COLUMN);
if (count($roots) < 2) {
    echo "servono almeno due cartelle top-level: trovate " . count($roots) . "\n";
    exit(1);
}
[$visibleRoot, $hiddenRoot] = $roots;
echo "  root consentito: $visibleRoot   root nascosto: $hiddenRoot\n\n";

// file: uno visibile, uno no
// file: uno visibile, uno no (prefisso preparato, niente quoting inline)
$st = $conn->prepare("SELECT id FROM files WHERE deleted_at IS NULL AND path LIKE ?
                     AND imgtype = 'LIGHT' AND date_obs IS NOT NULL ORDER BY id LIMIT 1");
$st->execute([$visibleRoot . '/%']);
$visFile = (int)$st->fetchColumn();
$st->execute([$hiddenRoot . '/%']);
$hidFile = (int)$st->fetchColumn();
if (!$visFile || !$hidFile) {
    echo "servono file LIGHT in entrambe le cartelle (vis=$visFile hid=$hidFile)\n";
    exit(1);
}
echo "  file visibile $visFile, file nascosto $hidFile\n\n";

// progetto di prova: un setup, e suggestion pendenti verso entrambi i file
$conn->prepare("INSERT INTO projects (name, notes, tolerances, assign_mode)
             VALUES (:n, '', '{}', 'suggest')")
    ->execute([':n' => 'vis_' . bin2hex(random_bytes(3))]);
$pid = (int)$conn->lastInsertId();
$conn->prepare("INSERT INTO project_setups (project_id, fingerprint, setup_no, label)
             VALUES (:p, 'VISTEST|1|CAM|1X1|1|2|3', 1, 'vis')")->execute([':p' => $pid]);
$sid = (int)$conn->lastInsertId();
foreach ([[$visFile, 'in-scope'], [$hidFile, 'out-of-scope']] as [$fid, $tag]) {
    $conn->prepare("INSERT INTO project_suggestions
        (project_id, file_id, level, node_id, role, status, reason)
        VALUES (:p, :f, 'setup', :n, 'sub', 'pending', :r)")
        ->execute([':p' => $pid, ':f' => $fid, ':n' => $sid, ':r' => $tag]);
}

function asUser(array $session, callable $fn)
{
    $_SESSION = $session;
    return $fn();
}

$admin = ['user_id' => 1, 'username' => 'admin', 'is_admin' => 1,
    'can_download' => 1, 'allowed_dirs' => ['/']];
// utente ristretto: solo la cartella visibile
$restricted = ['user_id' => 99, 'username' => 'restricted', 'is_admin' => 0,
    'can_download' => 1, 'allowed_dirs' => [$visibleRoot]];

echo "=== il progetto resta visibile all'utente ristretto? ===\n";
$adminSees = asUser($admin, fn() => canAccessProject($conn, $pid));
$restrictedSees = asUser($restricted, fn() => canAccessProject($conn, $pid));
check('admin vede il progetto', $adminSees === true);
check('utente ristretto vede il progetto', $restrictedSees === true,
    $restrictedSees ? 'visibile' : 'SPARITO <<< il bug');

echo "\n=== la coda di revisione filtra la suggestion fuori scope? ===\n";
$treeRestricted = asUser($restricted, fn() => getProjectTree($conn, $pid, true));
$treeAdmin = asUser($admin, fn() => getProjectTree($conn, $pid, true));

// Si raccolgono i path direttamente dall'albero (le pending a livello setup
// stanno in setups[].calibrations[]) invece di passare da json_encode: su dati
// reali la serializzazione puo' fallire o troncarsi e mascherare il conteggio.
$collect = function (array $tree): array {
    $out = [];
    foreach (($tree['setups'] ?? []) as $su) {
        foreach (($su['calibrations'] ?? []) as $c) {
            if (!empty($c['pending'])) {
                $out[] = (string)($c['path'] ?? '');
            }
        }
    }
    return $out;
};
$adminPending = $collect($treeAdmin);
$restrictedPending = $collect($treeRestricted);
echo '  admin: ' . count($adminPending) . " pending -> " . implode(', ', $adminPending) . "\n";
echo '  ristretto: ' . count($restrictedPending) . " pending -> "
    . implode(', ', $restrictedPending) . "\n";

check('l admin vede entrambe le suggestion', count($adminPending) === 2,
    count($adminPending) . ' su 2');
$leak = array_values(array_filter($restrictedPending,
    fn($p) => str_starts_with($p, $hiddenRoot . '/')));
check('nessun path fuori scope per il ristretto', empty($leak),
    empty($leak) ? 'filtrata' : 'FUGA: ' . implode(', ', $leak));
check('il ristretto vede comunque la sua suggestion',
    count($restrictedPending) === 1, count($restrictedPending) . ' su 1 attesa');

// pulizia
$conn->prepare('DELETE FROM project_suggestions WHERE project_id = :p')->execute([':p' => $pid]);
$conn->prepare('DELETE FROM project_files WHERE node_id = :n AND level = :l')
    ->execute([':n' => $sid, ':l' => 'setup']);
$conn->prepare('DELETE FROM project_setups WHERE project_id = :p')->execute([':p' => $pid]);
$conn->prepare('DELETE FROM projects WHERE id = :p')->execute([':p' => $pid]);
echo "\n(progetto di prova rimosso)\n";
echo 'RISULTATO: ' . ($failed ? 'FALLITI: ' . implode(', ', $failed)
    : 'il progetto resta visibile e la coda e\' filtrata');