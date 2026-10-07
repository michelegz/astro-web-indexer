<?php
// Verifica 13.1 — le soglie di gruppo.
//
// L'indice unique era (project_id, setup_id, panel_id, filter_name, exptime) con
// filter_name ed exptime nullable. In MySQL/MariaDB due NULL non sono considerati
// uguali, quindi il vincolo non valeva per i gruppi senza ancora filtro e/o senza
// ancora esposizione: saveGroupThresholds() usava INSERT ... ON DUPLICATE KEY UPDATE,
// che non scattava mai, e ogni salvataggio appendeva una riga. Poi
// getProjectThresholds() indicizza per gruppo e assegna senza fondere, quindi la riga
// duplicata decideva in modo non deterministico quali soglie valessero.
//
// Uso:  docker cp tmp/threshold_key_check.php awi-php:/tmp/
//       docker exec awi-php sh -c 'cd /tmp && php threshold_key_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/projects_functions.php';

$conn = connectDB();
$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-56s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FALLITO');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

// Un setup e un panel reali, per esercitare projectOwnsNode() senza costruire
// un progetto di prova.
$sid = (int)$conn->query('SELECT MIN(id) FROM project_setups')->fetchColumn();
$pid = $sid > 0
    ? (int)$conn->query('SELECT project_id FROM project_setups WHERE id = ' . $sid)->fetchColumn()
    : 0;
$pan = $sid > 0
    ? (int)$conn->query('SELECT MIN(id) FROM project_panels WHERE setup_id = ' . $sid)->fetchColumn()
    : 0;
check('setup e panel reali trovati', $pid > 0 && $pan > 0, "proj=$pid setup=$sid panel=$pan");

/** Righe con una data identita di gruppo, NULL inclusi. */
function groupRows(PDO $conn, int $pid, int $sid, int $pan): int
{
    $s = $conn->prepare(
        'SELECT COUNT(*) FROM project_group_thresholds WHERE project_id = :p AND setup_id = :s '
        . 'AND panel_id = :n AND filter_name IS NULL AND exptime IS NULL'
    );
    $s->execute([':p' => $pid, ':s' => $sid, ':n' => $pan]);
    return (int)$s->fetchColumn();
}

/** Valore di una metrica per la data identita di gruppo. */
function groupValue(PDO $conn, int $pid, int $sid, int $pan, string $col)
{
    $s = $conn->prepare(
        "SELECT `{$col}` FROM project_group_thresholds WHERE project_id = :p AND setup_id = :s "
        . 'AND panel_id = :n AND filter_name IS NULL AND exptime IS NULL ORDER BY id DESC'
    );
    $s->execute([':p' => $pid, ':s' => $sid, ':n' => $pan]);
    $v = $s->fetchColumn();
    return $v === false ? null : (float)$v;
}

// Tutto dentro una transazione: saveGroupThresholds() rispetta una transazione gia'
// aperta (non ne avvia una propria e non fa commit), quindi il rollback finale
// lascia il DB com'era.
$conn->beginTransaction();
try {
    echo "\n=== salvataggi ripetuti su un gruppo senza filtro e senza esposizione ===\n";
    // Tre salvataggi in successione: il valore finale deve essere l'ultimo scritto e
    // il gruppo deve restare una sola riga.
    foreach ([2.0, 2.5, 3.0] as $hfr) {
        saveGroupThresholds($conn, $pid, $sid, $pan, null, null, ['hfr' => $hfr]);
    }
    $n = groupRows($conn, $pid, $sid, $pan);
    check('tre salvataggi -> una sola riga', $n === 1, "righe=$n" . ($n > 1 ? ' DUPLICATE' : ''));
    $v = groupValue($conn, $pid, $sid, $pan, 'hfr_max');
    check('il valore e\' quello dell\'ultimo salvataggio', $v === 3.0, 'hfr_max=' . var_export($v, true));

    // La lettura publica deve concordare, e restituire una sola entry per gruppo.
    $all = getProjectThresholds($conn, $pid);
    $key = groupThresholdKey($sid, $pan, null, null);
    check('getProjectThresholds: una entry per il gruppo',
        count($all) === 1 && isset($all[$key]),
        'entry=' . count($all));
    check('getProjectThresholds: valore coerente col DB',
        isset($all[$key]) && ($all[$key]['hfr'] ?? null) === 3.0,
        'hfr=' . var_export($all[$key]['hfr'] ?? null, true));

    // La lettura specifica l'ordine. Senza ORDER BY, con duplicati presenti, la
    // riga che vince e' quella che il motore restituisce per ultima e la lettura
    // assegna invece di fondere: il risultato dipenderebbe dall'ordine fisico. Su
    // InnoDB finora tornava quello giusto per caso, quindi questa non e' la prova di
    // un difetto osservato ma la dichiarazione di una dipendenza che non esiste piu'.
    //
    // Il controllo guarda la stringa SQL, non il corpo della funzione: nel corpo c'e'
    // anche il commento che spiega il perche', e una ricerca testuale li confonderebbe.
    $src = (string)file_get_contents('/var/www/html/includes/projects_functions.php');
    $fn = preg_match('/function getProjectThresholds\b[^{]*\{(.*?)\n\}/s', $src, $fm) ? $fm[1] : '';
    preg_match('/->prepare\(\s*"(.*?)"\s*\)/s', $fn, $sm);
    $sql = $sm[1] ?? '';
    check('getProjectThresholds ordina la SELECT per id decrescente',
        stripos($sql, 'ORDER BY id DESC') !== false,
        $sql === '' ? 'SQL non trovato' : ($sql === '' ? '' : preg_replace('/\s+/', ' ', $sql)));

    echo "\n=== la stessa cosa con il filtro presente e l'esposizione assente ===\n";
    // Basta UN NULL nella tupla: e' il caso piu' frequente in pratica, perche'
    // l'esposizione di ancoraggio non e' sempre definita.
    foreach (['Ha' => [1.5, 1.8], 'OIII' => [2.2]] as $filter => $series) {
        foreach ($series as $hfr) {
            saveGroupThresholds($conn, $pid, $sid, $pan, $filter, null, ['hfr' => $hfr]);
        }
        $s = $conn->prepare('SELECT COUNT(*) FROM project_group_thresholds WHERE project_id = :p '
            . 'AND setup_id = :s AND panel_id = :n AND filter_name = :f AND exptime IS NULL');
        $s->execute([':p' => $pid, ':s' => $sid, ':n' => $pan, ':f' => $filter]);
        $cnt = (int)$s->fetchColumn();
        check("filtro {$filter}: salvataggi ripetuti -> una sola riga", $cnt === 1,
            "righe=$cnt" . ($cnt > 1 ? ' DUPLICATE' : ''));
    }

    echo "\n=== il DB rifiuta davvero il duplicato ===\n";
    // Se il codice non dipende piu' dall'upsert, il vincolo e' l'unica difesa rimasta:
    // deve reggere sui NULL.
    $dupRejected = false;
    try {
        $conn->exec('INSERT INTO project_group_thresholds '
            . '(project_id, setup_id, panel_id, filter_name, exptime, hfr_max) '
            . "VALUES ({$pid}, {$sid}, {$pan}, NULL, NULL, 9.9)");
    } catch (PDOException $e) {
        $dupRejected = ($e->getCode() === '23000') || str_contains($e->getMessage(), '1062');
    }
    check('inserimento duplicato con NULL -> 1062', $dupRejected,
        $dupRejected ? 'rifiutato dal vincolo' : 'ACCETTATO: il vincolo non copre i NULL');

    // E con l'esposizione valorizzata, dove il vincolo reggeva gia'.
    $dupRejected2 = false;
    try {
        $conn->exec('INSERT INTO project_group_thresholds '
            . '(project_id, setup_id, panel_id, filter_name, exptime, hfr_max) '
            . "VALUES ({$pid}, {$sid}, {$pan}, 'Ha', 300.0, 9.9)");
        $conn->exec('INSERT INTO project_group_thresholds '
            . '(project_id, setup_id, panel_id, filter_name, exptime, hfr_max) '
            . "VALUES ({$pid}, {$sid}, {$pan}, 'Ha', 300.0, 8.8)");
    } catch (PDOException $e) {
        $dupRejected2 = ($e->getCode() === '23000') || str_contains($e->getMessage(), '1062');
    }
    check('inserimento duplicato senza NULL -> 1062', $dupRejected2, '');

    echo "\n=== azzerare le soglie rimuove la riga ===\n";
    saveGroupThresholds($conn, $pid, $sid, $pan, null, null, []);
    check('salvataggio tutto-NULL -> riga rimossa', groupRows($conn, $pid, $sid, $pan) === 0,
        'righe=' . groupRows($conn, $pid, $sid, $pan));

    echo "\n=== le metriche non-NULL non vengono perse ===\n";
    saveGroupThresholds($conn, $pid, $sid, $pan, null, null, ['hfr' => 2.0, 'star_count' => 50]);
    check('salvataggio parziale -> entrambe le metriche',
        groupValue($conn, $pid, $sid, $pan, 'hfr_max') === 2.0
        && groupValue($conn, $pid, $sid, $pan, 'stars_min') === 50.0,
        'hfr=' . var_export(groupValue($conn, $pid, $sid, $pan, 'hfr_max'), true)
        . ' stars=' . var_export(groupValue($conn, $pid, $sid, $pan, 'stars_min'), true));

    // Il percorso di azzeramento deve ripulire anche i duplicati preesistenti: e' un
    // DELETE senza LIMIT, quindi la riga sparisce comunque.
    $conn->exec('DELETE FROM project_group_thresholds WHERE project_id = ' . $pid
        . ' AND setup_id = ' . $sid . ' AND panel_id = ' . $pan);
} catch (Throwable $e) {
    check('nessuna eccezione inattesa', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
}

// Dopo il rollback il gruppo deve essere intatto.
$left = $conn->query('SELECT COUNT(*) FROM project_group_thresholds WHERE project_id = ' . $pid
    . ' AND setup_id = ' . $sid . ' AND panel_id = ' . $pan)->fetchColumn();
check('rollback: nessuna soglia lasciata indietro', (int)$left === 0, 'righe=' . $left);

echo "\nRISULTATO: " . ($failed ? 'FALLITI: ' . implode(', ', $failed) : 'chiave delle soglie corretta') . "\n";
