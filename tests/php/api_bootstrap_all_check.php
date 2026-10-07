<?php
// Verifica i quattro endpoint non-projects migrati da init.php ad api_bootstrap.php.
//
// init.php e' il bootstrap della home: oltre a auth e include condivisi esegue
// l'intera pipeline della tabella file (albero cartelle, tre aggregate, trend stelle
// fino a 10.000 righe, conteggio LIGHT, query file paginata) e assegna variabili che
// nessun endpoint JSON legge. Questi quattro lo includevano per intero.
//
// Nessuno dei quattro usa una sola di quelle variabili: il controllo e' sui simboli che
// init.phpassegna ($files, $folders, $totalRecords, $toggleableKeys, $hiddenCols,
// $columnGroups, $visibleAdvKeys, $showStarMetrics, $tableColspan).
//
// Uso:  docker cp tmp/api_bootstrap_all_check.php awi-php:/tmp/
//       docker exec awi-php php /tmp/api_bootstrap_all_check.php

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

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

function httpGet(string $url, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

function httpPost(string $url, string $jar, $payload, bool $form = true): array
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

// ---------------------------------------------------------------- prova statica
echo "\n=== i quattro non caricano piu' il blocco dati della home ===\n";

$targets = ['get_duplicates.php', 'update_visibility.php',
    'sff_get_filters.php', 'find_calibration_files.php'];

// Simboli che init.php assegna e che nessun endpoint JSON dovrebbe leggere.
$initVars = ['$files', '$folders', '$totalRecords', '$toggleableKeys', '$hiddenCols',
    '$columnGroups', '$visibleAdvKeys', '$visibleStarKeys', '$visibleFrameKeys',
    '$visibleBaseKeys', '$advKeys', '$starKeys', '$frameKeys', '$showStarMetrics',
    '$tableColspan', '$hiddenColsProjects'];

foreach ($targets as $t) {
    $src = (string)file_get_contents('/var/www/html/api/' . $t);
    check("$t: usa api_bootstrap.php",
        str_contains($src, 'api_bootstrap.php'), '');
    check("  e non init.php", !str_contains($src, "includes/init.php"), '');
    $used = [];
    foreach ($initVars as $v) {
        // Solo letture: un'assegnazione tipo $conn = connectDB() non conta.
        if (preg_match('/(?<![A-Za-z0-9_$])' . preg_quote($v, '/') . '\b/', $src)
            && !preg_match('/^\s*' . preg_quote($v, '/') . '\s*=[^=]/m', $src)) {
            $used[] = $v;
        }
    }
    check("  non legge variabili di init.php", $used === [],
        $used === [] ? '' : implode(', ', $used));
}

// init.php deve restare usato dalle pagine: la home non e' un endpoint.
$home = (string)file_get_contents('/var/www/html/index.php');
check('index.php continua a usare init.php',
    str_contains($home, 'init.php'), '');
check('projects.php anche', str_contains((string)file_get_contents('/var/www/html/projects.php'), 'init.php'), '');

// ---------------------------------------------------------------- prova funzionale
echo "\n=== i quattro rispondono sul percorso felice ===\n";

$tag = 'apiboot4_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $plain, false, true, ['/']);
$jar = '/tmp/ab4_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
[$s, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
httpPost("$base/login.php", $jar, ['username' => $tag, 'password' => $plain,
    'csrf_token' => $m[1] ?? '']);
[$sHome, $home2] = httpGet("$base/?panel=projects", $jar);
check('sessione valida', $sHome === 200 && !str_contains($home2, 'name="password"'), 'HTTP ' . $sHome);

$row = $conn->query("SELECT id, file_hash FROM files
                     WHERE file_hash IS NOT NULL AND file_hash <> '' ORDER BY id LIMIT 1")
    ->fetch(PDO::FETCH_ASSOC);
$fid = (int)($row['id'] ?? 0);
// Il file di prova parte nascosto, cosi' la chiamata a update_visibility ha un
// cambiamento reale da verificare. La riga resta com'era: "show" rimette is_hidden a 0.
if ($fid > 0) {
    $conn->prepare('UPDATE files SET is_hidden = 1 WHERE id = :id')->execute([':id' => $fid]);
}
$hash = (string)($row['file_hash'] ?? '');
check('file di prova', $fid > 0 && $hash !== '', "id=$fid hash=" . substr($hash, 0, 12));

// get_duplicates: JSON con l'elenco dei duplicati
[$st, $b] = httpGet("$base/api/get_duplicates.php?hash=" . rawurlencode($hash), $jar);
$j = json_decode($b, true);
check('get_duplicates.php -> 200 con JSON',
    $st === 200 && is_array($j),
    'HTTP ' . $st . ', ' . strlen($b) . ' byte, chiavi: '
    . (is_array($j) ? implode(',', array_slice(array_keys($j), 0, 4)) : substr($b, 0, 50)));

// update_visibility: ids come come array. Vale la pena chiamarlo davvero, e poi
// rimettere a posto: usa show, che e' idempotente.
// update_visibility: ids come array. Vale la pena chiamarlo davvero. "show" mette
// is_hidden a 0, quindi e' idempotente e non lascia traccia: niente da ripristinare.
// (La colonna e' is_hidden, non visibility: avevo indovinato il nome e il test e' morto
// su un Column not found, il che e' il modo piu' rapido per imparare lo schema.)
$beforeHidden = (int)$conn->query("SELECT COALESCE(is_hidden, 0) FROM files WHERE id = $fid")
    ->fetchColumn();
[$st, $b] = httpPost("$base/api/update_visibility.php", $jar,
    ['ids' => [$fid], 'action' => 'show', 'hash' => $hash], false);
$j = json_decode($b, true);
$afterHidden = (int)$conn->query("SELECT COALESCE(is_hidden, 0) FROM files WHERE id = $fid")
    ->fetchColumn();
check('update_visibility.php -> 200 con JSON',
    $st === 200 && is_array($j) && !isset($j['error']),
    'HTTP ' . $st . ', ' . substr(trim($b), 0, 70));
check('  e ha applicato il cambiamento',
    $beforeHidden === 1 && $afterHidden === 0,
    "is_hidden $beforeHidden -> $afterHidden");

// sff_get_filters vuole id e type (non file_id/search_type), e il file deve essere
// un LIGHT o risponde 404.
$lightId = (int)$conn->query("SELECT id FROM files WHERE imgtype LIKE 'LIGHT%'
                               AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
check('un LIGHT di prova', $lightId > 0, "id=$lightId");
[$st, $b] = httpGet("$base/api/sff_get_filters.php?id=$lightId&type=light", $jar);
check('sff_get_filters.php -> 200',
    $st === 200,
    'HTTP ' . $st . ', ' . strlen($b) . ' byte, '
    . substr(preg_replace('/\s+/', ' ', trim($b)), 0, 60));

// find_calibration_files: search_type non valido deve essere un 400 pulito, non un
// warning che finisce nel corpo e rompe il JSON. E' il difetto che questa migrazione
// ha fatto emergere.
[$st, $b] = httpPost("$base/api/find_calibration_files.php", $jar,
    ['file_id' => $fid, 'search_type' => 'light'], false);
$jBad = json_decode($b, true);
check('search_type inesistente -> 400 con JSON valido',
    $st === 400 && is_array($jBad) && isset($jBad['valid']),
    'HTTP ' . $st . ', ' . substr(preg_replace('/\s+/', ' ', trim($b)), 0, 70));
check('  e nessun warning nel corpo',
    stripos($b, 'Warning') === false && !str_contains(trim($b), '<br'),
    str_contains($b, 'Warning') ? 'ANCORA UN WARNING' : '');

// find_calibration_files: payload JSON con file_id e search_type
[$st, $b] = httpPost("$base/api/find_calibration_files.php", $jar,
    ['file_id' => $fid, 'search_type' => 'lights'], false);
$j = json_decode($b, true);
check('find_calibration_files.php -> 200 con JSON',
    $st === 200 && is_array($j),
    'HTTP ' . $st . ', ' . strlen($b) . ' byte, ' . substr(trim($b), 0, 60));

// Nessuno dei quattro deve emettere markup o warning davanti al JSON.
echo "\n=== nessuno emette HTML spazzatura ===\n";
foreach ([
    'get_duplicates.php' => "$base/api/get_duplicates.php?hash=" . rawurlencode($hash),
] as $ep => $url) {
    [$st, $b] = httpGet($url, $jar);
    $noise = [];
    foreach (['Warning', 'Notice', 'Deprecated', 'Fatal error'] as $lvl) {
        if (stripos($b, "<b>$lvl</b>") !== false || stripos($b, "$lvl:") !== false) {
            $noise[] = $lvl;
        }
    }
    check("$ep: nessun warning", $noise === [], $noise === [] ? '' : implode('/', $noise));
}
[$st, $b] = httpGet("$base/api/sff_get_filters.php", $jar);
check('sff_get_filters.php: nessun warning',
    stripos($b, 'Warning') === false && stripos($b, 'Notice') === false,
    '');

$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(prova e utente di prova rimossi)\n";
echo 'RISULTATO: ' . ($failed ? 'FALLITI: ' . implode(', ', $failed)
    : 'i quattro endpoint non pagano piu\' il blocco della home') . "\n";