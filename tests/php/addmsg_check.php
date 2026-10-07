<?php
// Verifica §12 — il messaggio d'errore dell'add-to-project deve essere visibile
// nello step in cui viene scritto.
//
// Prima del fix #projectAddMsg stava dentro #projectStep1, che showProjectStep()
// nasconde: gli errori arrivano dal fetch di conferma (step 3), quindi il box
// rosso finiva in un contenitore display:none e l'utente non vedeva nulla.
//
// Il test fa login reale via login.php e poi analizza il markup della home, che
// e' la pagina che contiene il modale.
//
// Uso:  docker cp tmp/addmsg_check.php awi-php:/var/www/html/
//       docker exec awi-php php /var/www/html/addmsg_check.php

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

$base = 'http://nginx';
$conn = connectDB();
$failed = [];

// utente di prova, rimosso alla fine
$tag = 'addmsg_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $plain, false, true, ['/']);

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-48s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FALLITO');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

function httpGet(string $url, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30]);
    $body = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $body];
}

function httpPost(string $url, string $jar, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30]);
    curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st];
}

$jar = '/tmp/am_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
[$s, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
[$sLogin] = httpPost("$base/login.php", $jar,
    ['username' => $tag, 'password' => $plain, 'csrf_token' => $m[1] ?? '']);
[$sHome, $home] = httpGet("$base/", $jar);
echo "  login GET $s / POST $sLogin, home HTTP $sHome (" . strlen($home) . " byte)\n";

check('home autenticata', !str_contains($home, 'name="password"'),
    str_contains($home, 'name="password"') ? 'servita la pagina di login' : '');

// isola il modale: dal suo inizio fino a projectStep4 piu' i suoi figli
$start = strpos($home, '<div id="projectModal"');
check('modale presente', $start !== false);
$modal = $start !== false ? substr($home, $start, 12000) : '';

if ($modal !== '') {
    // 1. il messaggio non deve stare dentro nessuno step
    foreach ([1, 2, 3, 4] as $s) {
        $needle = "id=\"projectStep{$s}\"";
        $i = strpos($modal, $needle);
        if ($i === false) {
            check("projectStep{$s} presente", false);
            continue;
        }
        // finestra dello step: dal suo inizio fino alla fine del prossimo fratello noto
        $next = [1 => 'id="projectStep2"', 2 => 'id="projectStep3"',
                 3 => 'id="projectStep4"', 4 => '</div>'][$s];
        $j = strpos($modal, $next, $i + 1);
        $chunk = $j !== false ? substr($modal, $i, $j - $i) : '';
        check("projectAddMsg NON dentro projectStep{$s}",
            !str_contains($chunk, 'id="projectAddMsg"'));
    }

    // 2. posizione: deve precedere projectStep1, altrimenti lo showProjectStep
    //    che lo precede nasconderebbe anche il messaggio
    $iMsg = strpos($modal, 'id="projectAddMsg"');
    $iStep1 = strpos($modal, 'id="projectStep1"');
    check('projectAddMsg prima di projectStep1',
        $iMsg !== false && $iStep1 !== false && $iMsg < $iStep1,
        "msg@{$iMsg} step1@{$iStep1}");

    // 3. i quattro step ci sono ancora
    foreach ([1, 2, 3, 4] as $s) {
        check("projectStep{$s} presente", strpos($modal, "id=\"projectStep{$s}\"") !== false);
    }
    // 4. e i controlli che il JS usa per lo step di conferma
    check('projectModalConfirm presente', strpos($modal, 'id="projectModalConfirm"') !== false);
    check('projectTreePreview presente', strpos($modal, 'id="projectTreePreview"') !== false);
}

// pulizia
$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(utente di prova rimosso)\n";
echo 'RISULTATO: ' . ($failed ? 'FALLITI: ' . implode(', ', $failed)
    : "il messaggio e' visibile in ogni step");