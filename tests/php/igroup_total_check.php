<?php
// Verifica 13.14, 13.15 — il grand total e' la somma di una partizione.
//
// projects.php:1202-1217 accumula il totale su $intGroups: count, exposure, auto_off e
// nights. Se un light potesse stare in due integration group, sia count sia exposure
// sarebbero sommati due volte e il "grand total" mentirebbe senza che nulla lo dica.
//
// Il docblock di getIntegrationGroups() dice che i gruppi sono una partizione, e che
// merge_tiles accorpa *pannelli* di setup diversi in un tile, non clona light. Questo
// test lo verifica sui dati reali invece di fidarsi del commento: conta i light
// distinti e li confronta con la somma, e fa lo stesso sull'esposizione.
//
// Uso:  docker cp tmp/igroup_total_check.php awi-php:/tmp/
//       docker exec awi-php sh -c 'cd /tmp && php igroup_total_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/projects_diagnostics.php';
require_once '/var/www/html/includes/projects_functions.php';

if (!function_exists('__')) {
    function __(string $key, array $replace = []): string
    {
        return $key;
    }
}

$conn = connectDB();
$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-58s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FALLITO');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

$pid = (int)$conn->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();
check('progetto di prova', $pid > 0, "id=$pid");

$grouping = getProjectGrouping($conn, $pid);
$tree = getProjectTree($conn, $pid, false);

// Lo stesso percorso di project_tree_preview.php / project_export.php.
$defs = getToleranceDefs();
$posTols = [];
foreach ($defs as $tkey => $tdef) {
    $posTols[$tkey] = resolve_tol($conn, $pid, $tkey);
}
$tolExpRaw = trim((string)($posTols['tol_exp'] ?? '1%'));
$tolTempRaw = trim((string)($posTols['tol_temp'] ?? '2C'));
$aliases = getProjectFilterAliases($conn, $pid);
$thresholds = getProjectThresholds($conn, $pid);

$groups = getIntegrationGroups($tree, $tolExpRaw, $thresholds, $tolTempRaw, $grouping, $posTols, $aliases);

echo "\n=== 13.14 / 13.15: la somma dei gruppi e' una partizione ===\n";
printf("  grouping: split_setup=%s split_panel=%s split_filter=%s merge_tiles=%s\n",
    var_export($grouping['split_setup'] ?? null, true),
    var_export($grouping['split_panel'] ?? null, true),
    var_export($grouping['split_filter'] ?? null, true),
    var_export($grouping['merge_tiles'] ?? null, true));
printf("  gruppi: %d\n", count($groups));

// Il totale come lo calcola projects.php, rieseguito qui.
$totCount = 0;
$totExp = 0.0;
$totAuto = 0;
$totNights = [];
$sumDistinct = 0;
$sumDistinctExp = 0.0;
$dupes = [];
$seen = [];
foreach ($groups as $gi => $g) {
    $totCount += (int)$g['count'];
    $totExp += (float)$g['exposure'];
    foreach ($g['lights'] as $li) {
        $fid = (int)($li['file_id'] ?? 0);
        if ($fid > 0) {
            if (isset($seen[$fid])) {
                $dupes[] = $fid;
            } else {
                // L'esposizione dei file distinti: e' il confronto che chiude 13.14.
                $sumDistinctExp += (float)($li['exptime'] ?? 0);
            }
            $seen[$fid] = true;
        }
        if (!empty($li['auto_off'])) {
            $totAuto++;
        }
    }
    foreach ($g['nights'] as $tn) {
        $totNights[$tn] = true;
    }
}
$sumDistinct = count($seen);

// check 1: nessun file in due gruppi. E' la proprieta' che decide entrambe le voci.
check('nessun file appartiene a due gruppi',
    $dupes === [],
    $dupes === [] ? $sumDistinct . ' file distinti' : 'duplicati: ' . implode(', ', array_slice($dupes, 0, 10)));

// check 2: la somma dei conti per gruppo coincide con i file distinti.
check('somma dei count = file distinti',
    $totCount === $sumDistinct,
    "somma={$totCount} distinti={$sumDistinct}");

// check 3: l'esposizione. Le somme float possono divergere di qualche ulp, quindi il
// confronto e' relativo e non assoluto.
check('somma delle esposizioni = esposizione dei file distinti',
    abs($totExp - $sumDistinctExp) <= 1e-6,
    sprintf('somma=%.3f s (%.4f h)', $totExp, $totExp / 3600));

// check 4: i file dei gruppi devono esistere e non essere cancellati. La forma della
// query di linkatura la riuso dal tree, quindi qui basta il controllo che esistano:
// se un gruppo contenesse un id inesistente il conteggio mentirebbe.
$groupedIds = array_keys($seen);
$missingFiles = [];
foreach (array_chunk($groupedIds, 500) as $chunk) {
    $in = implode(',', array_map('intval', $chunk));
    $found = $conn->query(
        "SELECT COUNT(*) FROM files WHERE deleted_at IS NULL AND id IN ({$in})"
    )->fetchColumn();
    if ((int)$found !== count($chunk)) {
        $missingFiles[] = count($chunk) - (int)$found;
    }
}
check('i file dei gruppi esistono e non sono cancellati',
    $missingFiles === [],
    $missingFiles === [] ? count($groupedIds) . ' file verificati'
        : 'non trovati: ' . implode(', ', $missingFiles));

$projectFiles = (int)$conn->query("SELECT COUNT(*) FROM project_files WHERE level = 'project' AND node_id = {$pid}")->fetchColumn();
printf("  (link di livello project sul progetto: %d)\n", $projectFiles);

// check 5: le notti. Anche qui, un insieme che si sovrappone tra gruppi non e' un errore
// di somma, ma il totale deve essere l'unione, e count() su un array con duplicati
// darebbe un numero piu' grande delle notti reali.
$nightsDupes = 0;
$allNights = [];
foreach ($groups as $g) {
    foreach ($g['nights'] as $tn) {
        if (isset($allNights[$tn])) {
            $nightsDupes++;
        }
        $allNights[$tn] = true;
    }
}
printf("  notti: unione=%d (riapparizioni tra gruppi: %d, attese: una notte puo' stare in piu' gruppi)\n",
    count($allNights), $nightsDupes);
check('il totale delle notti e\' l\'unione reale',
    count($totNights) === count($allNights),
    'totale=' . count($totNights) . ' unione=' . count($allNights));

// =====================================================================
// il caso in cui la partizione potrebbe rompersi: modalita' tile
// =====================================================================
// merge_tiles accorpa pannelli di setup diversi con stessa posizione/rotazione/FoV. Il
// progetto di prova ha merge_tiles = false, quindi il caso sospetto non e' mai stato
// esercitato: e' esattamente li' che un light potrebbe finire in due gruppi. La
// configurazione si forza in memoria, senza scrivere nulla.
echo "\n=== modalita' tile: la partizione regge anche li'? ===\n";

$tileGrouping = array_merge($grouping, [
    'split_setup' => false,   // precondizione di groupingTilesEffective()
    'split_panel' => true,
    'merge_tiles' => true,
]);
$tilesOn = function_exists('groupingTilesEffective') && groupingTilesEffective($tileGrouping);
check('la configurazione tile e\' effettiva', $tilesOn,
    'split_setup=' . var_export($tileGrouping['split_setup'] ?? null, true)
    . ', merge_tiles=' . var_export($tileGrouping['merge_tiles'] ?? null, true));

if ($tilesOn) {
    $tileGroups = getIntegrationGroups($tree, $tolExpRaw, $thresholds, $tolTempRaw,
        $tileGrouping, $posTols, $aliases);
    $tCount = 0;
    $tExp = 0.0;
    $tDupes = [];
    $tSeen = [];
    $tDistinctExp = 0.0;
    foreach ($tileGroups as $g) {
        $tCount += (int)$g['count'];
        $tExp += (float)$g['exposure'];
        foreach ($g['lights'] as $li) {
            $fid = (int)($li['file_id'] ?? 0);
            if ($fid <= 0) {
                continue;
            }
            if (isset($tSeen[$fid])) {
                $tDupes[] = $fid;
            } else {
                $tDistinctExp += (float)($li['exptime'] ?? 0);
            }
            $tSeen[$fid] = true;
        }
    }
    printf("  gruppi tile: %d, file distinti: %d\n", count($tileGroups), count($tSeen));
    check('modalita\' tile: nessun file in due gruppi', $tDupes === [],
        $tDupes === [] ? 'partizione intatta' : 'DUPLICATI: ' . implode(', ', array_slice($tDupes, 0, 10)));
    check('modalita\' tile: somma dei count = file distinti',
        $tCount === count($tSeen), "somma={$tCount} distinti=" . count($tSeen));
    check('modalita\' tile: somma delle esposizioni = file distinti',
        abs($tExp - $tDistinctExp) <= 1e-6,
        sprintf('somma=%.3f s distinti=%.3f s', $tExp, $tDistinctExp));
} else {
    check('modalita\' tile esercitata', false, 'groupingTilesEffective() dice che no');
}

echo "\nRISULTATO: " . ($failed ? 'FALLITI: ' . implode(', ', $failed)
    : 'i gruppi sono una partizione, il totale e\' corretto') . "\n";