<?php
// Sweep di esercizio dei quattro endpoint migrati ad api_bootstrap.php nel commit
// a42b36c, sui percorsi felici E su quelli di errore.
//
// find_calibration_files.php aveva un riferimento penduto a $allFilters e moriva con un
// TypeError su ogni ricerca con filtri. L'analisi statica non lo aveva visto, e il mio
// test precedente passava perche' verificava la forma del codice con una regex. Quindi
// qui niente regex: ogni risposta viene eseguita davvero e il corpo viene letto alla
// ricerca di tracce di PHP, che e' il sintomo che l'utente vede come
// "Unexpected token <".
//
// Uso:  docker cp tmp/api_bootstrap_sweep.php awi-php:/tmp/
//       docker exec awi-php php /tmp/api_bootstrap_sweep.php

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

$failed = [];
function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-52s %s\n", $label, $detail);
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

// Qualsiasi traccia di PHP nel corpo e' un difetto: e' esattamente cio' che rompe il
// JSON.parse() del client, e su sff_get_filters.php finisce nella modale.
function phpTraces(string $body): array
{
    $out = [];
    foreach (['Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:',
        'Uncaught', 'Undefined variable', 'Undefined array key',
        'must be of type array, null given', 'Stack trace'] as $m) {
        if (stripos($body, $m) !== false) {
            $out[] = $m;
        }
    }
    return $out;
}

function call(string $url, string $jar, ?array $payload, string $ctype): array
{
    $ch = curl_init($url);
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 300];
    if ($payload !== null) {
        $opt[CURLOPT_POST] = true;
        $opt[CURLOPT_POSTFIELDS] = $ctype === 'form'
            ? http_build_query($payload) : json_encode($payload);
        $opt[CURLOPT_HTTPHEADER] = ['Content-Type: ' . ($ctype === 'form'
            ? 'application/x-www-form-urlencoded' : 'application/json')];
    }
    curl_setopt_array($ch, $opt);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return [$st, $b, $ct];
}

// sff_get_filters.php legge id e type da INPUT_GET e get_duplicates.php vuole hash da
// $_GET: non sono endpoint JSON e non accettano POST. La prima versione di questo sweep
// li interrogava in POST e riceveva 400 su tutte le righe, comprese quelle etichettate
// "happy path": stavo misurando il percorso di rifiuto e lo avevo chiamato successo.
function callGet(string $url, string $jar, array $query): array
{
    return call($url . '?' . http_build_query($query), $jar, null, 'form');
}

$conn = connectDB();
$tag = 'sweep_' . bin2hex(random_bytes(3));
$pw = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $pw, false, true, ['/']);
$jar = '/tmp/sw_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
call('http://nginx/login.php', $jar, null, 'form');
[, $h] = call('http://nginx/login.php', $jar, null, 'form');
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $h, $m);
call('http://nginx/login.php', $jar,
    ['username' => $tag, 'password' => $pw, 'csrf_token' => $m[1] ?? ''], 'form');

$light = (int)$conn->query("SELECT id FROM files WHERE imgtype LIKE 'LIGHT%'
    AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
// Il percorso di successo di get_duplicates usa la colonna file_hash: non 'hash', che
// non esiste nella tabella files (la mia prima query lo sbagliava e il test moriva con
// "Unknown column 'hash'",che avrei potuto scambiare per un difetto dell'endpoint).
$fileHash = (string)$conn->query("SELECT file_hash FROM files
    WHERE file_hash IS NOT NULL AND LENGTH(file_hash) > 8 AND deleted_at IS NULL
    ORDER BY id LIMIT 1")->fetchColumn();

// [etichetta, endpoint, payload, tipo atteso, metodo]
$cases = [
    ['get_duplicates: file_hash reale', 'get_duplicates.php', ['hash' => $fileHash], 'json', 'get'],
    ['get_duplicates: hash inesistente', 'get_duplicates.php', ['hash' => 'deadbeef'], 'json', 'get'],
    ['get_duplicates: hash vuoto', 'get_duplicates.php', ['hash' => ''], 'json', 'get'],
    ['get_duplicates: parametro assente', 'get_duplicates.php', [], 'json', 'get'],
    ['update_visibility: azione ignota', 'update_visibility.php',
        ['action' => 'frobnicate', 'id' => 1, 'is_hidden' => 1], 'json', 'post'],
    ['update_visibility: id non numerico', 'update_visibility.php',
        ['action' => 'toggle_visibility', 'id' => 'abc'], 'json', 'post'],
    ['update_visibility: payload vuoto', 'update_visibility.php', [], 'json', 'post'],
    ['sff_get_filters: id e type reali', 'sff_get_filters.php',
        ['id' => $light, 'type' => 'lights', 'lang' => 'en'], 'html', 'get'],
    ['sff_get_filters: bias', 'sff_get_filters.php',
        ['id' => $light, 'type' => 'bias', 'lang' => 'en'], 'html', 'get'],
    ['sff_get_filters: search_type invalido', 'sff_get_filters.php',
        ['id' => $light, 'type' => 'plasma', 'lang' => 'en'], 'html', 'get'],
    ['sff_get_filters: id mancante', 'sff_get_filters.php',
        ['type' => 'lights', 'lang' => 'en'], 'html', 'get'],
    ['sff_get_filters: id non numerico', 'sff_get_filters.php',
        ['id' => 'abc', 'type' => 'lights', 'lang' => 'en'], 'html', 'get'],
    ['find_calibration: happy path con filtro', 'find_calibration_files.php',
        ['file_id' => $light, 'search_type' => 'lights',
            'filters' => [['id' => 'xbinning', 'type' => '=', 'ref_value' => '1', 'tolerance' => '1']]],
        'json', 'post'],
    ['find_calibration: payload vuoto', 'find_calibration_files.php', [], 'json', 'post'],
    ['find_calibration: file_id inesistente', 'find_calibration_files.php',
        ['file_id' => 999999999, 'search_type' => 'lights'], 'json', 'post'],
];

foreach ($cases as [$label, $ep, $payload, $expect, $method]) {
    echo "\n$label\n";
    $url = "http://nginx/api/$ep";
    [$st, $b, $ct] = $method === 'get'
        ? callGet($url, $jar, $payload) : call($url, $jar, $payload, 'json');
    $traces = phpTraces($b);
    check('  nessuna traccia di PHP nel corpo', $traces === [],
        $traces ? implode(', ', $traces) : '');
    check('  e nessun <br /> di errore', !preg_match('#<br\s*/?>\s*<b>|<b>(Warning|Fatal|Notice)#i', $b), '');
    if ($expect === 'json' && $traces === []) {
        $j = json_decode($b, true);
        check('  risposta JSON o corpo vuoto', $j !== null || trim($b) === '',
            $j === null ? 'non JSON: ' . substr(preg_replace('/\s+/', ' ', $b), 0, 60) : 'JSON ok');
    }
    if ($expect === 'html') {
        check('  e produce markup, non un errore', strlen($b) > 0 && $traces === [],
            strlen($b) . ' byte, HTTP ' . $st);
    }
    printf("  (HTTP %d, %s byte, %s)\n", $st, number_format(strlen($b)), $ct ?: 'no content-type');
}

$conn->prepare('DELETE FROM user_permissions WHERE user_id=:id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id=:id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(utente di prova rimosso)\n";
echo 'RISULTATO: ' . ($failed ? 'FALLITI: ' . implode(', ', $failed)
    : 'nessun endpoint moriva con un filtro') . "\n";