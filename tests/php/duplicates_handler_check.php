<?php
// Verifica 13.44 / 13.45 — un solo handler per i duplicati.
//
// table.php conteneva due blocchi <script> completi della stessa funzione. Il primo
// legava il click direttamente a ogni .duplicate-badge; il secondo usa la delegazione
// sul body, che e' quella giusta perche' intercetta anche i badge aggiunti dopo il
// DOMContentLoaded. Entrambi erano attivi, quindi un click su un badge esistente
// eseguiva i due gestori: doppio fetch e doppio render della modale.
//
// Il blocco vecchio era anche la versione meno recente: mancavano l'ordinamento che
// mette il file di riferimento in testa, il disabilitare dei pulsanti durante l'azione,
// l'aggiornamento dei badge senza reload, e soprattutto escapeHTML(), che nel blocco
// vecchio era uno stub vuoto con il commento "same as before". Quel renderer senza
// escaping era ancora raggiungibile.
//
// Uso:  docker cp tmp/duplicates_handler_check.php awi-php:/tmp/
//       docker exec awi-php sh -c 'cd /tmp && php duplicates_handler_check.php'

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

function httpPost(string $url, string $jar, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

echo "\n=== 13.44 / 13.45: un solo blocco script per i duplicati ===\n";

$src = (string)file_get_contents('/var/www/html/includes/table.php');
check("un solo blocco <script> in table.php",
    substr_count($src, '<script>') === 1,
    substr_count($src, '<script>') . ' blocchi');
check('nessun escapeHTML a stub',
    !str_contains($src, 'same as before'),
    str_contains($src, 'same as before') ? 'ANCORA lo stub' : '');
check('  escapeHTML e\' implementato',
    (bool)preg_match('/function escapeHTML\(str\)\s*\{\s*if \(!str\)/', $src), '');
check('un solo gestore: la delegazione sul body',
    substr_count($src, "document.body.addEventListener('click'") === 1,
    substr_count($src, "document.body.addEventListener('click'") . ' deleghe');
check('  nessun legame diretto per ogni badge',
    !str_contains($src, "querySelectorAll('.duplicate-badge').forEach"),
    '');
check('l\'ordinamento col file di riferimento in testa c\'e\'',
    str_contains($src, 'a.path === referencePath'), '');
check('i pulsanti vengono disabilitati durante l\'azione',
    str_contains($src, 'hideBtn.disabled = true'), '');

echo "\n=== la pagina reale non contiene piu\' i due handler ===\n";

$tag = 'dupchk_' . bin2hex(random_bytes(3));
$pw = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $pw, false, true, ['/']);
$jar = '/tmp/dc_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
[$s, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
httpPost("$base/login.php", $jar, ['username' => $tag, 'password' => $pw,
    'csrf_token' => $m[1] ?? '']);

[$s, $home] = httpGet("$base/", $jar);
$noise = [];
foreach (['Warning', 'Notice', 'Deprecated', 'Fatal error'] as $lvl) {
    if (stripos($home, "<b>$lvl</b>") !== false) {
        $noise[] = $lvl;
    }
}
check('la home si rende senza warning PHP',
    $s === 200 && $noise === [],
    'HTTP ' . $s . ', ' . strlen($home) . ' byte' . ($noise ? ': ' . implode('/', $noise) : ''));

// I riferimenti al badge devono comparire una volta sola per gestore, non due.
$badgeRefs = substr_count($home, "'.duplicate-badge'") + substr_count($home, '.duplicate-badge');
printf("  riferimenti a .duplicate-badge nella pagina: %d\n", $badgeRefs);
check('lo stub "same as before" non e\' nella pagina',
    !str_contains($home, 'same as before'), '');
check('escapeHTML compare una volta sola',
    substr_count($home, 'function escapeHTML') === 1,
    substr_count($home, 'function escapeHTML') . ' definizioni');

// E la modale deve esistere una volta sola.
check('un solo elemento duplicatesModal',
    substr_count($home, 'id="duplicatesModal"') === 1,
    substr_count($home, 'id="duplicatesModal"') . ' occorrenze');

$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(prove e utente di prova rimossi)\n";
echo 'RISULTATO: ' . ($failed ? 'FALLITI: ' . implode(', ', $failed)
    : 'un solo handler per i duplicati') . "\n";