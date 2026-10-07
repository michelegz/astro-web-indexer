<?php
// Verifica 13.36, 13.37, 13.38, 13.39 — batch sicurezza.
//
//  13.36 il form ZIP non aveva il token CSRF e l'endpoint non lo chiedeva
//  13.37 il test di contenimento del percorso accettava una directory sorella
//  13.38 json_encode senza flag e senza controllo del false
//  13.39 il CSV AstroBin non applicava i permessi di directory
//
// Fa login reale e chiama gli endpoint via HTTP, piu' un test del filesystem
// vero per il confine di percorso.
//
// Uso:  docker cp tmp/security_batch_check.php awi-php:/tmp/
//       docker exec awi-php sh -c 'cd /tmp && php security_batch_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

// Questo test gira sia sul codice pre-fix sia su quello corretto, per poter
// dimostrare che fallisce prima e passa dopo. Quindi le funzioni introdotte dai
// fix non devono essere un prerequisito: se mancano, si ricade sul comportamento
// precedente e le asserzioni corrispondenti falliscono. Serve file_exists perche'
// un require fallito e' fatale e @ non lo silenzia.
if (file_exists('/var/www/html/includes/http_json.php')) {
    require_once '/var/www/html/includes/http_json.php';
}

function preFixIsPathWithinRoot(string $fullPath, string $realRoot): bool
{
    // Il predicato di prima: prefisso senza separatore.
    return str_starts_with($fullPath, $realRoot);
}

if (!function_exists('isPathWithinRoot')) {
    function isPathWithinRoot(string $fullPath, string $realRoot): bool
    {
        return preFixIsPathWithinRoot($fullPath, $realRoot);
    }
    $GLOBALS['helperMissing'] = true;
} else {
    $GLOBALS['helperMissing'] = false;
}

if (!function_exists('awiJsonString')) {
    function awiJsonString($payload, int $flags = 0)
    {
        // La codifica di prima: nessun flag, nessun controllo.
        return json_encode($payload, $flags);
    }
    $GLOBALS['jsonHelperMissing'] = true;
} else {
    $GLOBALS['jsonHelperMissing'] = false;
}

$base = 'http://nginx';
$conn = connectDB();
$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-52s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FALLITO');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

// ---------------------------------------------------------------- 13.37
echo "\n=== 13.37: il confine di percorso richiede il separatore ===\n";

// Struttura reale su disco: una radice e una directory sorella il cui nome
// inizia con quello della radice. E' il caso che un str_starts_with() senza
// separatore lasciava passare.
$labRoot = '/tmp/awi_rootlab';
$rootDir = $labRoot . '/fits';
$sibling = $labRoot . '/fits-archive';
@mkdir($rootDir . '/sub', 0777, true);
@mkdir($sibling, 0777, true);
file_put_contents($rootDir . '/inside.fits', 'x');
file_put_contents($rootDir . '/sub/deep.fits', 'x');
file_put_contents($sibling . '/outside.fits', 'x');

if (!empty($GLOBALS['helperMissing'])) {
    echo "  (isPathWithinRoot assente: si sta provando il codice pre-fix)\n";
}

$realRoot = realpath($rootDir);
check('radice e sorella create in /tmp', $realRoot !== false && is_dir($sibling),
    $realRoot . ' / ' . basename($sibling));

// Il predicato di produzione, applicato ai tre casi reali.
$inside = (string)realpath($rootDir . '/inside.fits');
$deep = (string)realpath($rootDir . '/sub/deep.fits');
$outside = (string)realpath($sibling . '/outside.fits');

check('file dentro la radice accettato', isPathWithinRoot($inside, (string)$realRoot));
check('file annidato accettato', isPathWithinRoot($deep, (string)$realRoot));
check('file nella sorella RESPINTO (il difetto)', !isPathWithinRoot($outside, (string)$realRoot),
    $outside);
check('la radice stessa accettata', isPathWithinRoot((string)$realRoot, (string)$realRoot));

// Il vecchio predicato, per mostrare che il caso esiste davvero e non e' un test
// che passa per costruzione.
$oldAccepts = preFixIsPathWithinRoot($outside, (string)$realRoot);
check('il predicato precedente accettava la sorella (motivo del fix)',
    $oldAccepts, $oldAccepts ? 'confermato' : 'NON riprodotto');

// E le due call site devono usare il predicato nuovo.
foreach (['/var/www/html/download.php', '/var/www/html/api/export_project_zip.php'] as $srcFile) {
    $src = (string)file_get_contents($srcFile);
    $usesNew = str_contains($src, 'isPathWithinRoot(');
    $usesBare = (bool)preg_match('/str_starts_with\(\s*\$(fullPath|filePath)\s*,\s*(realpath\(\$fitsRoot\)|\$realRoot\s*\)\s*)/', $src);
    check(basename(dirname($srcFile)) . '/' . basename($srcFile) . ' usa il predicato',
        $usesNew && !$usesBare, $usesBare ? 'ANCORA il confronto nudo' : '');
}

// ---------------------------------------------------------------- 13.36
echo "\n=== 13.36: il download ZIP chiede il token CSRF ===\n";

$tag = 'secbat_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
// can_download true: senza, l'endpoint risponderebbe 403 per il permesso e il
// test passerebbe senza mai arrivare al controllo CSRF.
$uid = createUser($conn, $tag, $plain, false, true, ['/']);
$pid = (int)$conn->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();

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

function httpPost(string $url, string $jar, $payload): array
{
    // http_build_query() esplicito: con un array curl invierebbe multipart.
    $body = is_array($payload) ? http_build_query($payload) : (string)$payload;
    $ctype = is_array($payload) ? 'application/x-www-form-urlencoded' : 'application/json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . $ctype],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

/**
 * POST che si ferma dopo i primi $want byte.
 * L'archivio di un progetto reale supera abbondantemente i 512M di memory_limit e
 * curl lo bufferizzerebbe tutto: qui serve solo la firma iniziale.
 */
function httpPostHead(string $url, string $jar, array $payload, int $want = 8): array
{
    $head = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => false, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
        CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$head, $want): int {
            $head .= substr($chunk, 0, max(0, $want - strlen($head)));
            return strlen($chunk);
        },
    ]);
    curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return [$st, $head, $ctype];
}

$jar = '/tmp/sb_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
[$s, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
httpPost("$base/login.php", $jar, ['username' => $tag, 'password' => $plain,
    'csrf_token' => $m[1] ?? '']);
// projects.php reindirizza alla home: e' li' che stanno i form col token.
[$sHome, $home] = httpGet("$base/?panel=projects", $jar);
check('sessione valida (home projects renderizzata)',
    $sHome === 200 && str_contains($home, 'csrf_token') && !str_contains($home, 'name="password"'),
    'HTTP ' . $sHome . ', ' . strlen($home) . ' byte');

// Il pannello di dettaglio arriva via AJAX, quindi il form ZIP non sta nell'HTML
// iniziale: il token lo prendo da un qualsiasi form della pagina, e il campo nel
// form ZIP lo verifico sul sorgente.
[$sPage, $page] = httpGet("$base/?panel=projects", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page, $fm);
check('token CSRF disponibile nella pagina', isset($fm[1]),
    isset($fm[1]) ? substr($fm[1], 0, 12) . '...' : 'ASSENTE');

$src = (string)file_get_contents('/var/www/html/projects.php');
// Il blocco del form va isolato prima: con un .*? lazy fino a "csrf_token" il
// confronto attraverserebbe </form> e aggancerebbe il token di un form successivo,
// facendo passare l'asserzione per il motivo sbagliato.
$zipBlock = preg_match('/<form[^>]*id="zipDownloadForm"[^>]*>(.*?)<\/form>/s', $src, $zm)
    ? $zm[1] : '';
$hasZipToken = $zipBlock !== '' && str_contains($zipBlock, 'name="csrf_token"');
check('il form ZIP contiene il campo csrf_token', $hasZipToken,
    $hasZipToken ? 'sorgente aggiornata' : 'ASSENTE: il form non porta il token');

// POST senza token: deve essere respinto. Prima partiva l'archivio, quindi qui si
// legge solo la firma: pre-fix la risposta sarebbe un ZIP intero e il test
// esaurirebbe la memoria invece di riportare il difetto.
[$st, $head] = httpPostHead("$base/api/export_project_zip.php", $jar, ['project_id' => $pid], 4);
check('senza token -> 403', $st === 403, 'HTTP ' . $st . ', firma=' . bin2hex($head));

// POST con token sbagliato.
[$st, $head] = httpPostHead("$base/api/export_project_zip.php", $jar,
    ['project_id' => $pid, 'csrf_token' => str_repeat('0', 64)], 4);
check('con token falso -> 403', $st === 403, 'HTTP ' . $st . ', firma=' . bin2hex($head));

// project_id non valido senza token: il 400 arriva prima, e va bene cosi'.
[$st, $b] = httpPost("$base/api/export_project_zip.php", $jar, ['project_id' => 0]);
check('project_id assente -> 400 (validazione prima del CSRF)', $st === 400, "HTTP $st");

// Con il token giusto l'archivio parte davvero: il fix non deve bloccare l'uso.
if (isset($fm[1])) {
    [$st, $head, $ctype] = httpPostHead("$base/api/export_project_zip.php", $jar,
        ['project_id' => $pid, 'csrf_token' => $fm[1]], 4);
    check('con token valido -> archivio ZIP', $st === 200 && strncmp($head, "PK\x03\x04", 4) === 0,
        'HTTP ' . $st . ', firma="' . bin2hex($head) . '", ' . $ctype);
}

// ---------------------------------------------------------------- 13.38
echo "\n=== 13.38: la codifica JSON non deve vuotare la risposta ===\n";

// Byte non UTF-8: files.name arriva dal filesystem ed e' un VARCHAR su
// connessione utf8mb4, quindi puo' contenere byte che MySQL non ricodifica.
$bad = "light \xB1\x31 sub";

// Il modo pre-fix: nessun flag, nessun controllo del false.
$preFix = json_encode(['name' => $bad]);
check('senza flag json_encode restituisce false', $preFix === false,
    $preFix === false ? 'confermato, echo false = corpo vuoto' : 'non riprodotto');

// Con il flag: la risposta resta utilizzabile.
$withFlag = json_encode(['name' => $bad], JSON_INVALID_UTF8_SUBSTITUTE);
check('con JSON_INVALID_UTF8_SUBSTITUTE si codifica', is_string($withFlag) && $withFlag !== '',
    (string)$withFlag);

$round = json_decode((string)$withFlag, true);
check('il risultato e\' decodificabile e conserva la struttura',
    is_array($round) && array_key_exists('name', $round));

// Un valore che resta non codificabile: il fallback deve restituire JSON valido,
// non false, altrimenti addFileFromPath fallirebbe e abortirebbe l'archivio.
$manifest = awiJsonString(['blob' => "\xB1\x31"]);
check('awiJsonString non restituisce mai false', is_string($manifest) && $manifest !== ''
    && json_decode($manifest, true) !== null, substr((string)$manifest, 0, 60));

// I quattro endpoint projects devono essere passati ad awiJson, non a
// echo json_encode: altrimenti il false resta scoperto.
foreach (['project_add.php', 'project_preview.php', 'project_tree_preview.php',
    'project_export_preview.php'] as $ep) {
    $src = (string)file_get_contents('/var/www/html/api/' . $ep);
    $bare = substr_count($src, 'echo json_encode');
    check($ep . ' senza echo json_encode nudo', $bare === 0, $bare ? $bare . ' rimasti' : '');
}

// E devono ancora rispondere bene.
[$st, $b] = httpPost("$base/api/project_export_preview.php", $jar, json_encode(['project_id' => $pid]));
$j = json_decode($b, true);
check('project_export_preview.php -> JSON valido',
    $st === 200 && is_array($j) && isset($j['entries']),
    'HTTP ' . $st . ', entries=' . (isset($j['entries']) ? count($j['entries']) : '?'));

// ---------------------------------------------------------------- 13.39
echo "\n=== 13.39: il CSV AstroBin applica i permessi di directory ===\n";

// Utente con accesso solo a una radice. Serve una radice che non sia '/' perche'
// l'amministratore non ha restrizioni.
$roots = $conn->query("SELECT DISTINCT SUBSTRING_INDEX(path, '/', 1) AS r FROM files
                       WHERE path IS NOT NULL AND path LIKE '%/%' ORDER BY r")
    ->fetchAll(PDO::FETCH_COLUMN);
$allowed = null;
$denied = null;
foreach ($roots as $r) {
    $other = null;
    foreach ($roots as $cand) {
        if ($cand !== $r) {
            $other = $cand;
            break;
        }
    }
    if ($other !== null) {
        $allowed = (string)$r;
        $denied = (string)$other;
        break;
    }
}

if ($allowed === null || $denied === null) {
    check('due radici distinte nel DB', false, 'fixture insufficiente');
} else {
    $fidOk = (int)$conn->query("SELECT id FROM files WHERE SUBSTRING_INDEX(path,'/',1) = "
        . $conn->quote($allowed) . " AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    $fidNo = (int)$conn->query("SELECT id FROM files WHERE SUBSTRING_INDEX(path,'/',1) = "
        . $conn->quote($denied) . " AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();

    check('file di prova nelle due radici', $fidOk > 0 && $fidNo > 0,
        "consentito={$allowed}/#{$fidOk} negato={$denied}/#{$fidNo}");

    // Utente ristretto alla sola radice consentita.
    $utag = 'secperm_' . bin2hex(random_bytes(3));
    $upass = 'pw' . bin2hex(random_bytes(6));
    $uuid = createUser($conn, $utag, $upass, false, true, [$allowed]);

    $jar2 = '/tmp/sp_' . bin2hex(random_bytes(4));
    @file_put_contents($jar2, '');
    [$s, $lhtml] = httpGet("$base/login.php", $jar2);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $lhtml, $m2);
    httpPost("$base/login.php", $jar2, ['username' => $utag, 'password' => $upass,
        'csrf_token' => $m2[1] ?? '']);

    // Solo il file consentito: deve arrivare.
    [$st, $b] = httpGet("$base/api/export_astrobin_csv.php?ids=$fidOk", $jar2);
    $rows = array_filter(explode("\n", $b));
    check('file autorizzato -> righe nel CSV', $st === 200 && count($rows) > 1,
        'HTTP ' . $st . ', ' . count($rows) . ' righe');

    // Solo il file negato: prima tornava comunque una riga piena di metadati.
    [$st, $b] = httpGet("$base/api/export_astrobin_csv.php?ids=$fidNo", $jar2);
    $rows = array_values(array_filter(explode("\n", $b)));
    $dataRow = null;
    foreach ($rows as $r) {
        if (!str_starts_with($r, 'date,')) {
            $dataRow = $r;
            break;
        }
    }
    // Sola riga ammessa: quella di sole calibrazioni, con date in futuro e i
    // contatori a zero. Qualunque dato del file negato la riempirebbe.
    $isEmptyCalRow = $dataRow !== null
        && str_starts_with($dataRow, date('Y-m-d', strtotime('12 hours ago')) . ',')
        && str_contains($dataRow, ',,0,') ;
    check('file NON autorizzato -> nessun metadato', $st === 200 && $isEmptyCalRow,
        'HTTP ' . $st . ', riga="' . substr((string)$dataRow, 0, 90) . '"');

    // Entrambi insieme: solo l'autorizzato.
    [$st, $b] = httpGet("$base/api/export_astrobin_csv.php?ids=$fidOk,$fidNo", $jar2);
    check('elenco misto -> solo la riga del file autorizzato', $st === 200
        && substr_count(trim($b), "\n") === 1,
        'righe dati=' . max(0, substr_count(trim($b), "\n")));

    $conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uuid]);
    $conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uuid]);
    @unlink($jar2);
}

// ---------------------------------------------------------------- cleanup
$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
exec('rm -rf ' . escapeshellarg($labRoot));
echo "\n(prove e utenti di prova rimossi)\n";
echo 'RISULTATO: ' . ($failed ? 'FALLITI: ' . implode(', ', $failed)
    : 'batch sicurezza ok');
echo "\n";
