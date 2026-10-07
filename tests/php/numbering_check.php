<?php
// Verifica §9 e §8 — numerazione di setup, panel e session.
//
// Controlla tre cose:
//  1. due writer concorrenti sullo stesso progetto non producono setup_no
//     duplicati (prima del fix: due righe con lo stesso S);
//  2. session_no segue l'ordine cronologico delle notti, anche inserendo una
//     notte piu' vecchia dopo (prima del fix: MAX+1 per ordine di creazione);
//  3. l'export non mette due setup diversi nella stessa cartella.
//
// Uso:  docker cp tmp/numbering_check.php awi-php:/var/www/html/
//       docker exec awi-php php /var/www/html/numbering_check.php
//
// Crea e rimuove un progetto di prova. Richiede che le migration 20261019 e
// 20261020 siano state applicate: senza gli indici unici la corsa non e'
// riproducibile.

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
require_once '/var/www/html/includes/projects_functions.php';

$_SESSION = ['user_id' => 1, 'username' => 'admin', 'is_admin' => 1,
    'can_download' => 1, 'allowed_dirs' => ['/']];

$failed = [];


function check(string $label, bool $cond, string $detail): void
{
    printf("  %-34s %-46s %s\n", $label, $detail, $cond ? 'OK' : '<<< FALLITO');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

$conn = connectDB();

// indici unici presenti?
$hasSetupIdx = (int)$conn->query("SELECT COUNT(*) c FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_setups'
      AND INDEX_NAME = 'uq_project_setups_no'")->fetchColumn();
$hasPanelIdx = (int)$conn->query("SELECT COUNT(*) c FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_panels'
      AND INDEX_NAME = 'uq_project_panels_no'")->fetchColumn();
echo "migration applicata: setup_no unico=" . ($hasSetupIdx ? 'si' : 'NO')
    . ", panel_no unico=" . ($hasPanelIdx ? 'si' : 'NO') . "\n\n";
if (!$hasSetupIdx || !$hasPanelIdx) {
    echo "attenzione: senza indici unici il test di concorrenza non dimostra nulla.\n";
}

// --------------------------------------------------------------- progetto prova
$conn->exec("INSERT INTO projects (name, notes, tolerances, assign_mode) VALUES "
    . "('numtest_" . bin2hex(random_bytes(3)) . "', 'verifica 9', '{}', 'manual')");
$pid = (int)$conn->lastInsertId();

// --------------------------------------------------------------- 1. concorrenza
// Due connessioni indipendenti che leggono lo stesso MAX e inseriscono.
// projectCreateSetup ritenta su 1062, quindi i due setup devono avere numeri diversi.
$c1 = connectDB();
$c2 = connectDB();
$fpA = 'FP|A|CAM|1X1|1|2|3';
$fpB = 'FP|B|CAM|1X1|1|2|3';
$sidA = projectCreateSetup($c1, $pid, $fpA, 'A');
$sidB = projectCreateSetup($c2, $pid, $fpB, 'B');
$noA = (int)$c1->query("SELECT setup_no FROM project_setups WHERE id = $sidA")->fetchColumn();
$noB = (int)$c2->query("SELECT setup_no FROM project_setups WHERE id = $sidB")->fetchColumn();
check('setup_no distinti (2 writer)', $noA !== $noB, "A=$noA B=$noB");
$dups = (int)$conn->query("SELECT COUNT(*) c FROM (SELECT setup_id, panel_no FROM project_panels
    GROUP BY setup_id, panel_no HAVING COUNT(*)>1) x")->fetchColumn();
check('nessun panel_no duplicato (DB)', $dups === 0, "duplicati=$dups");

// Lo stesso fingerprint NON deve creare un secondo setup, e il conflitto deve
// arrivare subito al chiamante (che riutilizza il setup esistente): il retry
// sulla numerazione non deve mascherarlo.
$threw = false;
try {
    projectCreateSetup($c1, $pid, $fpA, 'A-dup');
} catch (PDOException $e) {
    $threw = str_contains($e->getMessage(), '1062');
}
check('fingerprint duplicato -> 1062 subito', $threw, 'il chiamante riusa il setup esistente');

// --------------------------------------------------------------- 2. ordine notti
$row = ['objctrot' => 0, 'object' => 'Q99', 'fov_w' => 60, 'fov_h' => 40];
$panelA = projectCreatePanel($c1, $sidA, $row, 'Q99', 10.0, 20.0);
$panelB = projectCreatePanel($c2, $sidA, $row, 'Q99', 30.0, 40.0);
$panelNos = [
    'A' => (int)$conn->query("SELECT panel_no FROM project_panels WHERE id = $panelA")->fetchColumn(),
    'B' => (int)$conn->query("SELECT panel_no FROM project_panels WHERE id = $panelB")->fetchColumn(),
];
check('panel_no distinti (stesso setup)', $panelNos['A'] !== $panelNos['B'],
    "A={$panelNos['A']} B={$panelNos['B']}");

// notte nuova, poi notte piu' vecchia: la numerazione deve seguire la data
$nights = ['2026-03-10', '2026-03-20', '2026-02-01', '2026-03-15'];
foreach ($nights as $n) {
    projectFindOrCreateSession($c1, $panelA, $n);
}
$rows = $conn->query("SELECT astro_night, session_no FROM project_sessions
                      WHERE panel_id = $panelA ORDER BY astro_night")->fetchAll();
$byNight = [];
foreach ($rows as $r) {
    $byNight[$r['astro_night']] = (int)$r['session_no'];
}
echo "  notte -> session_no (in ordine di data): ";
foreach ($byNight as $night => $no) {
    echo "$night=N$no ";
}
echo "\n";
$expected = 1;
$ordered = true;
foreach ($byNight as $night => $no) {
    if ($no !== $expected) {
        $ordered = false;
    }
    $expected++;
}
check('session_no segue la notte', $ordered, 'atteso 1,2,3,4 in ordine di data');

// richiamare la stessa notte non deve crearne un'altra
$before = (int)$conn->query("SELECT COUNT(*) c FROM project_sessions WHERE panel_id = $panelA")
    ->fetchColumn();
$again2 = projectFindOrCreateSession($c1, $panelA, '2026-03-10');
$after = (int)$conn->query("SELECT COUNT(*) c FROM project_sessions WHERE panel_id = $panelA")
    ->fetchColumn();
check('notte ripetuta -> riusata', $before === $after && $again2 > 0,
    "sessioni $before->$after id=$again2");

// due pannelli, stessa notte: numeri distinti
$other = projectFindOrCreateSession($c2, $panelB, '2026-03-10');
$nOther = (int)$conn->query("SELECT session_no FROM project_sessions WHERE id = $other")
    ->fetchColumn();
check('stessa notte, pannello diverso', $nOther !== ($byNight['2026-03-10'] ?? -1),
    "A=N" . ($byNight['2026-03-10'] ?? '?') . " B=N$nOther");

// --------------------------------------------------------------- 3. cartelle export
$folders = [];
foreach ($conn->query("SELECT setup_no FROM project_setups WHERE project_id = $pid") as $r) {
    $folders[] = 'SETUP_S' . (int)$r['setup_no'];
}
check('una cartella per setup', count($folders) === count(array_unique($folders)),
    implode(', ', $folders));

// --------------------------------------------------------------- pulizia
$setupIds = $conn->query("SELECT id FROM project_setups WHERE project_id = $pid")
    ->fetchAll(PDO::FETCH_COLUMN);
foreach ($setupIds as $s) {
    $panels = $conn->query("SELECT id FROM project_panels WHERE setup_id = $s")
        ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($panels as $p) {
        foreach ($conn->query("SELECT id FROM project_sessions WHERE panel_id = $p")
            ->fetchAll(PDO::FETCH_COLUMN) as $ss) {
            $conn->prepare("DELETE FROM project_files WHERE level='filter' AND node_id = :n")
                ->execute([':n' => $ss]);
        }
        $conn->prepare("DELETE FROM project_sessions WHERE panel_id = :p")->execute([':p' => $p]);
        $conn->prepare("DELETE FROM project_files WHERE level='panel' AND node_id = :n")
            ->execute([':n' => $p]);
    }
    $conn->prepare("DELETE FROM project_files WHERE level='setup' AND node_id = :n")
        ->execute([':n' => $s]);
    $conn->prepare("DELETE FROM setup_overrides WHERE setup_id = :n")->execute([':n' => $s]);
    $conn->prepare("DELETE FROM project_panels WHERE setup_id = :n")->execute([':n' => $s]);
}
$conn->prepare("DELETE FROM project_setups WHERE project_id = :p")->execute([':p' => $pid]);
$conn->prepare("DELETE FROM projects WHERE id = :p")->execute([':p' => $pid]);

echo "\n(progetto di prova rimosso)\n";
echo 'RISULTATO: ' . ($failed ? 'FALLITI: ' . implode(', ', $failed) : 'tutti i controlli superati');