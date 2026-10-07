<?php
// Verifica 13.10 — gli id dei file non viaggiano nella request line.
//
// Gli id andavano in ?ids=1,2,3: qualche cifra ciascuno, quindi qualche migliaio di
// file mette decine di KB sulla request line, oltre gli 8 KB che il server accetta, e
// la richiesta moriva con 414 prima di arrivare a una riga di codice applicativo.
// Il test usa 4000 id, che in GET non verrebbero mai elaborati.
//
// Copre anche il permesso mancante di get_unmapped_filters.php, che chiedeva gli id
// grezzi senza alcun filtro di directory: uno stesso utente ristretto a una radice
// poteva farsi dire quali nomi filtro non mappati esistono in una directory che non
// può vedere.
//
// Uso:  docker cp tmp/export_ids_check.php awi-php:/tmp/
//       docker exec awi-php sh -c 'cd /tmp && php export_ids_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/http_json.php';

$base = 'http://nginx';
$conn = connectDB();
$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-58s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FALLITO');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

function httpPost(string $url, string $jar, array $payload, bool $form = false): array
{
    $body = $form ? http_build_query($payload) : json_encode($payload);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . ($form
            ? 'application/x-www-form-urlencoded' : 'application/json')],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 180]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

function httpGet(string $url, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 180]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

// =====================================================================
// il carico che rompeva il GET
// =====================================================================
echo "\n=== 13.10: 4000 id via POST, che in GET non arriverebbero ===\n";

$ids = range(1, 4000);
$queryString = implode(',', $ids);
// Se questa stringa sta in una request line, il server la rifiuta prima di noi.
$queryBytes = strlen($queryString);
check('la query string da sola supera il limite di 8 KB',
    $queryBytes > 8192, $queryBytes . ' byte');

// Il difetto originale: un GET con quella stringa non arriva all'applicazione.
// Non serve una sessione: il rifiuto avviene al livello del server HTTP.
$jarAnon = '/tmp/eia_' . bin2hex(random_bytes(4));
@file_put_contents($jarAnon, '');
[$st414, $b414] = httpGet("$base/api/export_astrobin_csv.php?ids=$queryString", $jarAnon);
@unlink($jarAnon);
check('GET con 18 KB di query string -> non raggiunge l\'applicazione',
    $st414 >= 400,
    'HTTP ' . $st414 . (str_contains($b414, 'Request-URI Too Large') ? ' (414)' : ''));

// Utente valido con accesso a tutte le radici.
$tag = 'expids_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $plain, false, true, ['/']);
$jar = '/tmp/ei_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
[$s, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
httpPost("$base/login.php", $jar, ['username' => $tag, 'password' => $plain,
    'csrf_token' => $m[1] ?? ''], true);
[$sHome, $home] = httpGet("$base/?panel=projects", $jar);
check('sessione valida', $sHome === 200 && !str_contains($home, 'name="password"'), 'HTTP ' . $sHome);

[$st, $b] = httpPost("$base/api/export_astrobin_csv.php", $jar, ['ids' => $ids]);
$rows = array_values(array_filter(explode("\n", trim($b))));
check('export CSV con 4000 id -> 200 e righe',
    $st === 200 && count($rows) > 1 && str_starts_with(trim($b), 'date,'),
    'HTTP ' . $st . ', righe=' . max(0, count($rows) - 1));
check('  nessun 414', $st !== 414, 'HTTP ' . $st);

[$st, $b] = httpPost("$base/api/get_unmapped_filters.php", $jar, ['ids' => $ids]);
$j = json_decode($b, true);
check('filtri non mappati con 4000 id -> 200 e JSON',
    $st === 200 && is_array($j) && isset($j['unmapped']),
    'HTTP ' . $st . ', unmapped=' . count($j['unmapped'] ?? []));

// GET piccolo resta supportato: un URL scritto a mano deve continuare a funzionare.
$oneId = (int)$conn->query("SELECT id FROM files WHERE deleted_at IS NULL
    AND imgtype LIKE 'LIGHT%' ORDER BY id LIMIT 1")->fetchColumn();
[$st, $b] = httpGet("$base/api/export_astrobin_csv.php?ids=$oneId", $jar);
check('GET con un solo id -> ancora supportato',
    $st === 200 && str_starts_with(trim($b), 'date,'), 'HTTP ' . $st);

// E il caso "nessun id" deve dare il CSV vuoto, non un errore.
[$st, $b] = httpPost("$base/api/export_astrobin_csv.php", $jar, ['ids' => []]);
check('nessun id -> CSV con solo l\'header', $st === 200 && trim($b) === 'date,filter,number,duration,binning,gain,sensorCooling,fNumber,darks,flats,flatDarks,bias',
    'HTTP ' . $st);

// Lo spazzatura non deve diventare id 0.
[$st, $b] = httpPost("$base/api/export_astrobin_csv.php", $jar, ['ids' => ['abc', '', '-3', '0', $oneId]]);
check('id non numerici scartati, non castati a 0', $st === 200 && str_starts_with(trim($b), 'date,'),
    'HTTP ' . $st);

// =====================================================================
// il permesso che mancava su get_unmapped_filters.php
// =====================================================================
echo "\n=== 13.10: anche get_unmapped_filters.php filtra per directory ===\n";

// Due radici distinte nel DB, come in security_batch_check.php.
$roots = $conn->query("SELECT DISTINCT SUBSTRING_INDEX(path, '/', 1) AS r FROM files
                       WHERE path IS NOT NULL AND path LIKE '%/%' ORDER BY r")
    ->fetchAll(PDO::FETCH_COLUMN);
$allowed = $denied = null;
foreach ($roots as $r) {
    foreach ($roots as $cand) {
        if ($cand !== $r) {
            $allowed = (string)$r;
            $denied = (string)$cand;
            break 2;
        }
    }
}

if ($allowed === null) {
    check('due radici distinte nel DB', false, 'fixture insufficiente');
} else {
    // Un file della radice negata, con un filtro esistente: se l'id passa, il nome
    // del filtro finisce nella risposta.
    $row = $conn->query("SELECT id, filter FROM files
        WHERE SUBSTRING_INDEX(path,'/',1) = " . $conn->quote($denied)
        . " AND deleted_at IS NULL AND filter IS NOT NULL AND TRIM(filter) != ''
          ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $fidNo = (int)($row['id'] ?? 0);
    $filterNo = trim((string)($row['filter'] ?? ''));

    check('file con filtro nella radice negata', $fidNo > 0 && $filterNo !== '',
        "radice negata={$denied} file=#{$fidNo} filtro=\"{$filterNo}\"");

    // Utente ristretto alla sola radice consentita.
    $utag = 'eiperm_' . bin2hex(random_bytes(3));
    $upass = 'pw' . bin2hex(random_bytes(6));
    $uuid = createUser($conn, $utag, $upass, false, true, [$allowed]);

    $jar2 = '/tmp/ep_' . bin2hex(random_bytes(4));
    @file_put_contents($jar2, '');
    [$s, $lhtml] = httpGet("$base/login.php", $jar2);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $lhtml, $m2);
    httpPost("$base/login.php", $jar2, ['username' => $utag, 'password' => $upass,
        'csrf_token' => $m2[1] ?? ''], true);

    [$st, $b] = httpPost("$base/api/get_unmapped_filters.php", $jar2, ['ids' => [$fidNo]]);
    $j = json_decode($b, true);
    $leaked = is_array($j) && in_array($filterNo, (array)($j['unmapped'] ?? []), true);
    check('filtro della radice negata NON viene rivelato',
        $st === 200 && is_array($j) && !$leaked,
        'HTTP ' . $st . ', leaked=' . var_export($leaked, true)
        . ', risposta=' . substr($b, 0, 90));

    // Un file della radice consentita deve invece passare il filtro (o non comparire
    // perche' il suo filtro e' mappato: in entrambi i casi non deve essere un errore).
    $rowOk = $conn->query("SELECT id FROM files
        WHERE SUBSTRING_INDEX(path,'/',1) = " . $conn->quote($allowed)
        . " AND deleted_at IS NULL AND filter IS NOT NULL AND TRIM(filter) != ''
          ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ((int)($rowOk['id'] ?? 0) > 0) {
        [$st, $b] = httpPost("$base/api/get_unmapped_filters.php", $jar2,
            ['ids' => [(int)$rowOk['id']]]);
        check('file della radice consentita -> 200 senza errori',
            $st === 200 && is_array(json_decode($b, true)), 'HTTP ' . $st);
    }

    $conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uuid]);
    $conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uuid]);
    @unlink($jar2);
}

$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);

// Il JS non deve piu' mettere gli id nella URL.
$js = (string)file_get_contents('/var/www/html/assets/js/astrobin_export.js');
check('il JS non costruisce piu\' una query string di id',
    !str_contains($js, 'idsQueryString'), '');
check('  e posta entrambe le richieste', substr_count($js, "postJson('/api/") === 2,
    substr_count($js, "postJson('/api/") . ' chiamate postJson');

echo "\nRISULTATO: " . ($failed ? 'FALLITI: ' . implode(', ', $failed)
    : 'gli id viaggiano nel body') . "\n";