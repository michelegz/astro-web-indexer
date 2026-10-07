<?php
// Verifica: find_calibration_files.php non porta piu' i blob dentro la risposta.
//
// La ricerca calibrazioni selezionava il campo thumb (MEDIUMBLOB) per ogni riga e lo
// inlineava come data: URI dentro l'HTML, che a sua volta finiva dentro una stringa
// JSON. Misurato su questo archivio:
//
//   236 file LIGHT con miniatura, 8.3 MB di blob
//   base64 aggiunge il 33%: 11.4 MB
//   risposta: 12,2 MB
//
// Le righe di testo sono qualche decina di KB: il resto era bitmap. Ora la tabella
// punta a /image.php, che serve gli stessi byte controllando canAccessPath() e li
// scarica il browser solo per le miniature che disegna.
//
// Uso:  docker cp tmp/sff_payload_check.php awi-php:/tmp/
//       docker exec awi-php php /tmp/sff_payload_check.php

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-56s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FALLITO');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

$base = 'http://nginx';
$conn = connectDB();

echo "\n=== il blob non deve piu' attraversare la query ne' la risposta ===\n";

$api = (string)file_get_contents('/var/www/html/api/find_calibration_files.php');
$tpl = (string)file_get_contents('/var/www/html/includes/sff_results_table.php');

// Nessun base64 di thumb: era il costo dominante.
check('nessun base64_encode($file[thumb]) nella tabella',
    !str_contains($tpl, 'base64_encode($file[\'thumb\'])')
    && !str_contains($tpl, 'base64_encode($file["thumb"])'), '');
check('nessun data:image inline',
    !str_contains($tpl, 'data:image'), '');

// Il riferimento all'endpoint che serve i byte.
check('la miniatura punta a /image.php',
    (bool)preg_match('#<img src="/image\.php\?id=#', $tpl), '');

// La query non deve piu' selezionare il blob, solo un flag.
check('la query seleziona has_thumb, non thumb',
    str_contains($api, 'AS has_thumb'), '');
// Ispeziono la lista delle colonne vera e propria, non l'intero file: il flag
// (thumb IS NOT NULL ...) contiene legittimamente la parola 'thumb', e un confronto
// testuale grossolano lo prenderebbe per il blob (falso positivo, gia' successo con
// l'espressione regolare che ho scritto per prima).
preg_match('/\$sql\s*=\s*"(.*?)"\s*\.\s*implode/s', $api, $sm);
$selectList = $sm[1] ?? '';
$columns = array_map('trim', explode(',', $selectList));
$bare = array_values(array_filter($columns,
    fn($c) => strcasecmp($c, 'thumb') === 0 || stripos($c, 'thumb ') === 0));
check('  e il campo thumb non e\' piu\' fra le colonne', $bare === [],
    $bare ? 'ANCORA SELEZIONATO: ' . implode(' | ', $bare) : 'colonne: ' . implode(', ', $columns));

// La verifica di esistenza continua a funzionare: il flag, non il blob.
check('la tabella controlla has_thumb',
    str_contains($tpl, "!empty(\$file['has_thumb'])"), '');
check('il ramo N/A resta',
    str_contains($tpl, "text-gray-500 text-xs\">N/A"), '');

echo "\n=== la risposta reale ===\n";

$tag = 'sffpay_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $plain, false, true, ['/']);
$jar = '/tmp/sp_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');

function httpGet(string $url, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 300]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}
function httpPost(string $url, string $jar, $payload, bool $form = false): array
{
    // login.php legge da $_POST urlencoded: con un array e CURLOPT_POSTFIELDS verrebbe
    // multipart e il login fallirebbe in silenzio. Il formato e' un flag, non dedotto
    // dal tipo (trappola #7 e #16).
    $body = $form ? http_build_query((array)$payload) : json_encode($payload);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . ($form
            ? 'application/x-www-form-urlencoded' : 'application/json')],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 300]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

[$s, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
httpPost("$base/login.php", $jar, ['username' => $tag, 'password' => $plain,
    'csrf_token' => $m[1] ?? ''], true);

$lightId = (int)$conn->query("SELECT id FROM files WHERE imgtype LIKE 'LIGHT%'
                               AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
check('LIGHT di prova', $lightId > 0, "id=$lightId");

// La ricerca piu' ampia possibile: un solo filtro, cosi' torna tutto.
[$st, $b] = httpPost("$base/api/find_calibration_files.php", $jar,
    ['file_id' => $lightId, 'search_type' => 'lights', 'filters' => []]);
$j = json_decode($b, true);
$bytes = strlen($b);
printf("  risposta: HTTP %d, %s byte (%.1f KB)\n", $st, number_format($bytes), $bytes / 1024);
check('la ricerca risponde 200', $st === 200, 'HTTP ' . $st);
check('  e resta sotto 1 MB', $bytes < 1048576,
    number_format($bytes) . ' byte, ' . ($bytes / 1048576) . ' MB');

// Il confronto con la baseline misurata: 12,2 MB prima.
$baseline = 12200000;
$ratio = $bytes > 0 ? $baseline / $bytes : 0;
printf("  prima: ~12,2 MB  ->  ora: %.1f KB  (riduzione %.0f volte)\n",
    $bytes / 1024, $ratio);
check('riduzione almeno 20 volte', $ratio > 20, sprintf('%.0fx', $ratio));

// E la tabella deve comunque referenziare le miniature, non averle perse.
$hasImg = str_contains($b, '/image.php?id=');
check('la tabella contiene ancora i <img> verso image.php', $hasImg,
    substr_count($b, '/image.php?id=') . ' riferimenti');
check('  e nessun data: URI residuo', !str_contains($b, 'data:image'), '');

$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);

// Il cambio ha senso solo se /image.php serve davvero i byte con l'URL che ora mettiamo
// nella tabella: altrimenti l'immagine si rompe e le verifiche sopra passerebbero lo
// stesso. Stesso parametro usato da file_cells.php:207, ma verificato.
echo "\n=== /image.php serve la miniatura referenziata ===\n";
$thumbId = (int)$conn->query("SELECT id FROM files
    WHERE deleted_at IS NULL AND LENGTH(thumb) > 0 ORDER BY id LIMIT 1")->fetchColumn();
[$s2, $img] = httpGet("$base/image.php?id=$thumbId&type=thumb", $jar);
printf("  GET /image.php?id=%d&type=thumb -> HTTP %d, %d byte\n", $thumbId, $s2, strlen($img));
check('image.php risponde 200', $s2 === 200, 'HTTP ' . $s2);
check('  e restituisce un PNG', substr($img, 1, 3) === 'PNG',
    $s2 === 200 ? substr($img, 1, 3) : 'nessun corpo');
check('  e i byte sono la miniatura, non un segnaposto',
    $s2 === 200 && strlen($img) > 200, strlen($img) . ' byte');

$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(prova e utente di prova rimossi)\n";
echo 'RISULTATO: ' . ($failed ? 'FALLITI: ' . implode(', ', $failed)
    : 'il payload non contiene piu\' i blob') . "\n";