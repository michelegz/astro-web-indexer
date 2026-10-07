<?php
// Verifica 13.3, 13.4, 13.32 — atomicita' delle scritture e gestione degli Error.
//
//  13.3 saveProjectFilterAliases scriveva coppia per coppia eValidava/rilasciava in
//       piu' statement: un ciclo scoperto alla quarta coppia lasciava le prime tre
//       committate, e il messaggio d'errore diceva all'utente che era andato tutto
//       bene. Piu' la finestra in cui un alias rimosso non era ancora reinserito.
//  13.4 enqueueSuggestRequest era un read-then-write: due scrittori potevano passare
//       entrambi il SELECT e accodare due righe pending, quindi due backfill Python.
//  13.32 in project_tree_preview il buffer aperto intorno all'include del tree non
//       veniva chiuso in caso di errore, e i catch erano su Exception: in PHP 8
//       TypeError e ParseError sono Error e non venivano presi, lasciando anche la
//       transazione aperta.
//
// Uso:  docker cp tmp/write_atomicity_check.php awi-php:/tmp/
//       docker exec awi-php sh -c 'cd /tmp && php write_atomicity_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/projects_functions.php';

// saveProjectFilterAliases usa __() per il messaggio del ciclo. language.php vuole
// una sessione (che da CLI non parte), e qui si verifica solo che il ramo lanci:
// basta la chiave, non il testo tradotto.
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

$pid = (int)$conn->query('SELECT MIN(id) FROM projects')->fetchColumn();
check('progetto di prova trovato', $pid > 0, "id=$pid");

// =====================================================================
// 13.3 — alias filtro: nessuna applicazione parziale
// =====================================================================
echo "\n=== 13.3: un ciclo non deve lasciare indietro le coppie precedenti ===\n";

// Stato di partenza noto: nessun alias per questo progetto.
$conn->beginTransaction();
try {
    foreach (getProjectFilterAliases($conn, $pid) as $a => $c) {
        $conn->prepare('DELETE FROM project_filter_aliases WHERE project_id = ? AND alias = ?')
            ->execute([$pid, $c === null ? '' : $a]);
    }

    // Prima coppia valida, seconda che crea un ciclo con la prima (il canonico
    // 'Ha' e' gia' un alias). La validazione deve rifiutare TUTTO.
    $threw = null;
    try {
        saveProjectFilterAliases($conn, $pid, [
            'H-ALPHA' => 'Ha',   // valida
            'OIII-3nm' => 'H-ALPHA', // ciclo: 'H-ALPHA' e' gia' un alias
        ]);
    } catch (InvalidArgumentException $e) {
        $threw = $e->getMessage();
    }
    check('il ciclo viene rifiutato', $threw !== null, $threw === null ? 'NON rifiutato' : '');

    $left = getProjectFilterAliases($conn, $pid);
    check('nessuna coppia applicata parzialmente', $left === [],
        $left === [] ? '' : 'residui: ' . implode(', ', array_map(
            fn($a, $c) => "$a=>$c", array_keys($left), $left)));
} finally {
    $conn->rollBack();
}

echo "\n=== 13.3: un insieme valido si applica per intero ===\n";
$conn->beginTransaction();
try {
    saveProjectFilterAliases($conn, $pid, ['H-ALPHA' => 'Ha', 'OIII-3nm' => 'OIII']);
    $got = getProjectFilterAliases($conn, $pid);
    check('entrambe le coppie scritte',
        ($got['h-alpha'] ?? null) === 'Ha' && ($got['oiii-3nm'] ?? null) === 'OIII',
        json_encode($got));

    // Sovrascrivere non deve creare una seconda riga per lo stesso alias.
    saveProjectFilterAliases($conn, $pid, ['H-ALPHA' => 'H-alpha2']);
    $n = (int)$conn->query("SELECT COUNT(*) FROM project_filter_aliases WHERE project_id = " . $pid
        . " AND LOWER(alias) = 'h-alpha'")->fetchColumn();
    check('la riscrittura non duplica la riga', $n === 1, 'righe=' . $n);

    // La cancellazione con canonical vuota.
    saveProjectFilterAliases($conn, $pid, ['OIII-3nm' => '']);
    $got = getProjectFilterAliases($conn, $pid);
    check('canonical vuota rimuove la coppia', !isset($got['oiii-3nm']), json_encode($got));
} finally {
    $conn->rollBack();
}

echo "\n=== 13.3: scritture dentro una transazione piu' esterna ===\n";
// Se il chiamante ha gia' una transazione, la funzione non deve committare: il
// rollback del chiamante deve annullare tutto. E' il caso di project_add.php.
// PDO non ha transazioni annidate, quindi qui si simula aprendo una sola
// transazione e chiamando la funzione dentro.
$conn->beginTransaction();
check('transazione del chiamante aperta', $conn->inTransaction());
saveProjectFilterAliases($conn, $pid, ['L' => 'L-Prova']);
$stillInside = (int)$conn->query('SELECT COUNT(*) FROM project_filter_aliases WHERE project_id = '
    . $pid . " AND alias = 'L'")->fetchColumn();
$conn->rollBack();
$afterRollback = (int)$conn->query('SELECT COUNT(*) FROM project_filter_aliases WHERE project_id = '
    . $pid . " AND alias = 'L'")->fetchColumn();
check('la funzione non ha committato da sola (rollback annulla tutto)',
    $stillInside === 1 && $afterRollback === 0,
    "dentro=$stillInside dopo rollback=$afterRollback");

// =====================================================================
// 13.4 — una sola richiesta pending per progetto
// =====================================================================
echo "\n=== 13.4: il vincolo respinge il secondo pending ===\n";

$conn->beginTransaction();
try {
    // Pulizia: nessuna richiesta pendente per questo progetto.
    $conn->exec("UPDATE suggest_requests SET status = 'done' WHERE project_id = {$pid}"
        . " AND status = 'pending'");

    // Due insert diretti, come farebbero due scrittori concorrenti.
    $first = $conn->exec("INSERT INTO suggest_requests (project_id, reason, status) "
        . "VALUES ({$pid}, 'manual', 'pending')");
    $secondRejected = false;
    try {
        $conn->exec("INSERT INTO suggest_requests (project_id, reason, status) "
            . "VALUES ({$pid}, 'panel_created', 'pending')");
    } catch (PDOException $e) {
        $secondRejected = ($e->getCode() === '23000') || str_contains($e->getMessage(), '1062');
    }
    check('il secondo pending e\' respinto dal DB',
        $first === 1 && $secondRejected,
        $secondRejected ? '1062' : 'ACCETTATO: due pending per lo stesso progetto');

    // Lo stato non-pending resta libero: il vincolo copre solo la coda.
    $conn->exec("UPDATE suggest_requests SET status = 'done' WHERE project_id = {$pid}"
        . " AND status = 'pending'");
    $ok = 0;
    for ($i = 0; $i < 3; $i++) {
        $conn->exec("INSERT INTO suggest_requests (project_id, reason, status) "
            . "VALUES ({$pid}, 'manual', 'done')");
        $ok++;
    }
    check('le righe chiuse non sono limitate a una', $ok === 3, "inserite=$ok");

    // enqueueSuggestRequest deve coalescere: due chiamate, una sola riga pending.
    $conn->exec("UPDATE suggest_requests SET status = 'done' WHERE project_id = {$pid}"
        . " AND status = 'pending'");
    $r1 = enqueueSuggestRequest($conn, $pid, 'manual');
    $r2 = enqueueSuggestRequest($conn, $pid, 'manual');
    $pend = (int)$conn->query("SELECT COUNT(*) FROM suggest_requests WHERE project_id = {$pid}"
        . " AND status = 'pending'")->fetchColumn();
    check('enqueueSuggestRequest coalesce due chiamate',
        $r1 === true && $r2 === true && $pend === 1,
        "prima={$r1} seconda={$r2} pending={$pend}");
} finally {
    $conn->rollBack();
}

echo "\n=== 13.4: l'errore vero non viene più nascosto ===\n";
// Il catch finale deve registrare l'errore invece di restituire false in silenzio:
// altrimenti la coda smette di accodare e nessuno lo vede.
$src = (string)file_get_contents('/var/www/html/includes/projects_functions.php');
$fn = preg_match('/function enqueueSuggestRequest\b[^{]*\{(.*)\n\}/s', $src, $fm) ? $fm[1] : '';
check('enqueueSuggestRequest registra gli errori non attesi',
    str_contains($fn, "error_log(") && str_contains($fn, '1062'),
    str_contains($fn, "error_log(") ? '' : 'nessun error_log');

// =====================================================================
// 13.32 — il tree preview non puo' emettere HTML prima del JSON
// =====================================================================
echo "\n=== 13.32: buffer e catch nei quattro endpoint JSON ===\n";

foreach (['project_add.php', 'project_preview.php', 'project_tree_preview.php',
    'project_export_preview.php'] as $ep) {
    $srcEp = (string)file_get_contents('/var/www/html/api/' . $ep);
    // Nessun catch su Exception: in PHP 8 non copre Error, quindi un TypeError
    // sfuggirebbe come fatal e lascerebbe la transazione aperta.
    $bareException = (bool)preg_match('/catch\s*\(\s*Exception\b/', $srcEp);
    check("$ep: nessun catch (Exception)", !$bareException,
        $bareException ? 'ANCORA su Exception' : '');
}

// Il punto delicato: il buffer intorno all'include deve chiudersi anche in caso di
// errore, altrimenti l'HTML parziale precede il JSON e nessuno dei due e' parsabile.
$tree = (string)file_get_contents('/var/www/html/api/project_tree_preview.php');
check('il buffer del tree si chiude in finally',
    str_contains($tree, '} finally {') && str_contains($tree, 'ob_get_level()'),
    '');
check('il rollback e\' in finally, non solo nel catch',
    (bool)preg_match('/} finally \{.*?rollBack.*?\}/s', $tree), '');

// E l'endpoint deve ancora rispondere bene sul percorso felice.
$base = 'http://nginx';
function httpGet(string $url, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}
/**
 * $form = true invia urlencoded (login.php e i form), altrimenti JSON come fanno i
 * quattro endpoint. Non si deduce dal tipo: gli endpoint hanno payload array, che da
 * soli farebbero passare per un form.
 */
function httpPost(string $url, string $jar, $payload, bool $form = false): array
{
    $body = $form ? http_build_query((array)$payload) : json_encode($payload);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . ($form
            ? 'application/x-www-form-urlencoded' : 'application/json')],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

$tag = 'watomic_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $plain, false, true, ['/']);
$fid = (int)$conn->query("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype='LIGHT'
                           AND date_obs IS NOT NULL ORDER BY id LIMIT 1")->fetchColumn();
$jar = '/tmp/wa_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
[$s, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
httpPost("$base/login.php", $jar, ['username' => $tag, 'password' => $plain,
    'csrf_token' => $m[1] ?? ''], true);

[$st, $b] = httpPost("$base/api/project_tree_preview.php", $jar,
    ['ids' => [$fid], 'project_id' => $pid, 'overrides' => []]);
$j = json_decode($b, true);
check('tree preview: 200 con JSON pulito',
    $st === 200 && is_array($j) && isset($j['html']) && strlen($j['html']) > 0,
    'HTTP ' . $st . ', html ' . strlen($j['html'] ?? '') . ' byte');
check('tree preview: nessun HTML prima del JSON',
    !str_contains(substr($b, 0, 40), '<div'), substr(trim($b), 0, 40));

// E il rollback del tree preview non deve lasciare tracce: il progetto deve avere
// esattamente le righe che aveva prima.
$before = (int)$conn->query('SELECT COUNT(*) FROM project_files WHERE node_id = '
    . $pid . " AND level = 'project'")->fetchColumn();
[$st2, $b2] = httpPost("$base/api/project_tree_preview.php", $jar,
    ['ids' => [$fid], 'project_id' => $pid, 'overrides' => []]);
$after = (int)$conn->query('SELECT COUNT(*) FROM project_files WHERE node_id = '
    . $pid . " AND level = 'project'")->fetchColumn();
check('due preview non lasciano link ipotetici', $before === $after,
    "prima={$before} dopo={$after}");

$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(prove e utente di prova rimossi)\n";
echo 'RISULTATO: ' . ($failed ? 'FALLITI: ' . implode(', ', $failed)
    : 'scritture atomiche e gestione degli Error') . "\n";
