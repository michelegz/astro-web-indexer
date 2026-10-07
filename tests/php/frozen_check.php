<?php
// Verifica §6 — la modalita' "frozen" deve bloccare le scritture.
//
// Esercita projectAddPrepare() + projectAddFiles() esattamente come fa
// src/api/project_add.php (transazione compresa) e confronta lo stato del
// progetto prima/dopo. Non passa da HTTP: $_SESSION e' popolata in-process,
// perche' da CLI /tmp non e' scrivibile e session_start() fallirebbe.
//
// Copre il caso del piano: progetto frozen + override "new:<nome>", che prima
// scriveva project_setups e setup_overrides e poi rispondeva "added 0".
//
// Uso:  docker cp tmp/frozen_check.php awi-php:/var/www/html/
//       docker exec awi-php php /var/www/html/frozen_check.php
//
// Crea e poi rimuove due progetti di prova.

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
require_once '/var/www/html/includes/projects_functions.php';

// isLoggedIn()/canDownload() leggono $_SESSION: basta popolarla.
$_SESSION = ['user_id' => 1, 'username' => 'admin', 'is_admin' => 1,
    'can_download' => 1, 'allowed_dirs' => ['/']];

$conn = connectDB();

$fid = (int)$conn->query("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype = 'LIGHT' "
    . 'AND date_obs IS NOT NULL ORDER BY id LIMIT 1')->fetchColumn();
if (!$fid) {
    echo "nessun file LIGHT con data: test non eseguibile\n";
    exit(1);
}

// project_files e' chiazzato (file_id, level, node_id): non esiste
// project_files.setup_id, e i light finiscono a livello 'filter', non 'setup'.
function snapshot(PDO $conn, int $pid): array
{
    $q = fn(string $sql) => (int)$conn->query($sql)->fetchColumn();
    return [
        'setups' => $q("SELECT COUNT(*) FROM project_setups WHERE project_id = $pid"),
        'panels' => $q("SELECT COUNT(*) FROM project_panels WHERE setup_id IN "
            . "(SELECT id FROM project_setups WHERE project_id = $pid)"),
        'links' => $q("SELECT COUNT(*) FROM project_files pf
            WHERE (pf.level='setup'  AND pf.node_id IN (SELECT id FROM project_setups WHERE project_id = $pid))
               OR (pf.level='panel'  AND pf.node_id IN (SELECT p.id FROM project_panels p
                     JOIN project_setups s ON s.id=p.setup_id WHERE s.project_id = $pid))
               OR (pf.level='filter' AND pf.node_id IN (SELECT ss.id FROM project_sessions ss
                     JOIN project_panels p ON p.id=ss.panel_id
                     JOIN project_setups s ON s.id=p.setup_id WHERE s.project_id = $pid))"),
        'overrides' => $q("SELECT COUNT(*) FROM setup_overrides WHERE setup_id IN "
            . "(SELECT id FROM project_setups WHERE project_id = $pid)"),
    ];
}

function purge(PDO $conn, int $pid): void
{
    $setups = $conn->query("SELECT id FROM project_setups WHERE project_id = $pid")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($setups as $s) {
        $panels = $conn->query("SELECT id FROM project_panels WHERE setup_id = $s")
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($panels as $p) {
            $sess = $conn->query("SELECT id FROM project_sessions WHERE panel_id = $p")
                ->fetchAll(PDO::FETCH_COLUMN);
            foreach ($sess as $ss) {
                $conn->prepare("DELETE FROM project_files WHERE level='filter' AND node_id = :n")
                    ->execute([':n' => $ss]);
            }
            $conn->prepare("DELETE FROM project_sessions WHERE panel_id = :p")->execute([':p' => $p]);
        }
        $conn->prepare("DELETE FROM project_files WHERE level='panel' AND node_id IN "
            . '(SELECT id FROM project_panels WHERE setup_id = :s)')->execute([':s' => $s]);
        $conn->prepare("DELETE FROM project_files WHERE level='setup' AND node_id = :s")
            ->execute([':s' => $s]);
        $conn->prepare("DELETE FROM setup_overrides WHERE setup_id = :s")->execute([':s' => $s]);
        $conn->prepare("DELETE FROM project_panels WHERE setup_id = :s")->execute([':s' => $s]);
    }
    $conn->prepare('DELETE FROM project_group_thresholds WHERE project_id = :p')
        ->execute([':p' => $pid]);
    $conn->prepare('DELETE FROM project_setups WHERE project_id = :p')->execute([':p' => $pid]);
    $conn->prepare('DELETE FROM projects WHERE id = :p')->execute([':p' => $pid]);
}

// replica di project_add.php. Gli override passano da parseProjectAddRequest,
// cosi' "new:<nome>" viene trasformato in customSetups come in produzione.
function doAdd(PDO $conn, int $pid, array $ids, array $overrides): array
{
    $req = parseProjectAddRequest([
        'project_id' => $pid, 'ids' => $ids, 'overrides' => $overrides,
    ]);
    $conn->beginTransaction();
    $prep = projectAddPrepare($conn, $req['project_id'], $req['ids'], $req['overrides'],
        $req['customSetups'], null);
    $pid2 = $prep['project_id'];
    if (!empty($prep['frozen'])) {
        $conn->rollBack();
        return ['outcome' => 'frozen', 'added' => 0];
    }
    $result = projectAddFiles($conn, $pid2, $prep['ids'], []);
    if ($conn->inTransaction()) {
        $conn->commit();
    }
    return ['outcome' => 'added', 'added' => $result['added']];
}

echo "file di prova: $fid\n\n";
$fail = false;

// A) frozen + add semplice
$pid = createProject($conn, 'frozentest_' . bin2hex(random_bytes(4)), '§6');
$conn->prepare("UPDATE projects SET assign_mode='frozen' WHERE id=:id")->execute([':id' => $pid]);
$before = snapshot($conn, $pid);
$r = doAdd($conn, $pid, [$fid], []);
$after = snapshot($conn, $pid);
echo "A) frozen + add semplice\n";
echo "   esito: {$r['outcome']}, added={$r['added']}\n";
echo '   prima: ' . json_encode($before) . "\n";
echo '   dopo : ' . json_encode($after) . "\n";
$okA = $before === $after;
echo '   ' . ($okA ? 'INVARIATO (corretto)' : 'MUTATO <<< BUG') . "\n\n";
$fail = $fail || !$okA;

// B) frozen + setup custom (il caso che prima scriveva)
$before = snapshot($conn, $pid);
$r = doAdd($conn, $pid, [$fid], [(string)$fid => 'new:My Rig']);
$after = snapshot($conn, $pid);
echo "B) frozen + setup custom\n";
echo "   esito: {$r['outcome']}, added={$r['added']}\n";
echo '   prima: ' . json_encode($before) . "\n";
echo '   dopo : ' . json_encode($after) . "\n";
$okB = $before === $after;
echo '   ' . ($okB ? 'INVARIATO (corretto)' : 'MUTATO <<< BUG') . "\n\n";
$fail = $fail || !$okB;

// C) controllo: stesso add su progetto NON frozen
$pid2 = createProject($conn, 'opentest_' . bin2hex(random_bytes(4)), '§6');
$before2 = snapshot($conn, $pid2);
$r = doAdd($conn, $pid2, [$fid], []);
$after2 = snapshot($conn, $pid2);
echo "C) controllo: non frozen\n";
echo "   esito: {$r['outcome']}, added={$r['added']}\n";
echo '   prima: ' . json_encode($before2) . "\n";
echo '   dopo : ' . json_encode($after2) . "\n";
$okC = $after2['links'] > $before2['links'];
echo '   ' . ($okC ? 'ha aggiunto (corretto)' : 'NON ha aggiunto <<< il guard blocca tutto') . "\n\n";
$fail = $fail || !$okC;

// D) controllo: setup custom su progetto NON frozen.
// Serve un file diverso: in C lo stesso file e' gia' linkato (dedupe).
$fid2 = (int)$conn->query("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype = 'LIGHT' "
    . "AND date_obs IS NOT NULL AND id <> $fid ORDER BY id LIMIT 1")->fetchColumn();
$before3 = snapshot($conn, $pid2);
$r = doAdd($conn, $pid2, [$fid2], [(string)$fid2 => 'new:Other Rig']);
$after3 = snapshot($conn, $pid2);
echo "D) controllo: non frozen + setup custom\n";
echo "   esito: {$r['outcome']}, added={$r['added']}\n";
echo '   prima: ' . json_encode($before3) . "\n";
echo '   dopo : ' . json_encode($after3) . "\n";
$okD = $after3['setups'] > $before3['setups'];
echo '   ' . ($okD ? 'setup creato (corretto)' : 'setup NON creato <<<') . "\n\n";
$fail = $fail || !$okD;

purge($conn, $pid);
purge($conn, $pid2);
echo "(progetti di prova rimossi)\n\n";
echo 'RISULTATO: ' . ($fail ? 'FALLITI' : 'tutti i casi corretti');