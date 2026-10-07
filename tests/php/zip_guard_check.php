<?php
// Verifica §1 — l'export ZIP deve rispettare can_download come fa download.php.
//
// Crea un utente di prova senza download, fa login davvero via login.php e poi
// chiama l'endpoint. L'utente viene rimosso alla fine.
//
// Uso:  docker cp tmp/zip_guard_check.php awi-php:/var/www/html/
//       docker exec awi-php php /var/www/html/zip_guard_check.php

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

$base = 'http://nginx';
$conn = connectDB();

$tag = 'zipguard_' . bin2hex(random_bytes(4));
$plain = bin2hex(random_bytes(12));
$uid = createUser($conn, $tag, $plain, false, false, ['/']);   // can_download = 0

function req(string $url, string $jar, ?array $fields = null): array
{
    $ch = curl_init($url);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30, CURLOPT_HEADER => true];
    if ($fields !== null) {
        $o[CURLOPT_POST] = true;
        $o[CURLOPT_POSTFIELDS] = http_build_query($fields);
        $o[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
    }
    curl_setopt_array($ch, $o);
    $raw = curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$st, substr((string)$raw, (int)$hs)];
}

$jar = '/tmp/zg_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');

echo "utente di prova: $tag  (is_admin=0, can_download=0)\n\n";

// login reale
[$sG, $html] = req("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
[$sL] = req("$base/login.php", $jar,
    ['username' => $tag, 'password' => $plain, 'csrf_token' => $m[1] ?? '']);
echo "login.php: GET HTTP $sG, POST HTTP $sL\n";

// Attenzione: projects.php risponde 302 -> /?panel=projects anche con sessione
// valida, quindi "non autenticato" NON si deduce dal 302. Il segnale e' un body
// che contiene il form di login.
[$sP, $pBody] = req("$base/projects.php", $jar);
$authed = !str_contains($pBody, 'name="password"');
echo 'projects.php dopo login: HTTP ' . $sP . ' -> '
    . ($authed ? 'SESSIONE VALIDA' : 'SESSIONE PERSA') . "\n\n";

// export diretto
[$sZip, $body] = req("$base/api/export_project_zip.php", $jar, ['project_id' => '1']);
echo "POST api/export_project_zip.php -> HTTP $sZip\n";
echo '  body: ' . trim(preg_replace('/\s+/', ' ', strip_tags(substr($body, 0, 160)))) . "\n";
$ok = $sZip === 403;

// confronto: lo stesso utente non deve poter scaricare nemmeno via download.php
[$sDl, $dBody] = req("$base/download.php", $jar);
echo "\nPOST download.php (riferimento) -> HTTP $sDl\n";
echo '  body: ' . trim(preg_replace('/\s+/', ' ', strip_tags(substr($dBody, 0, 100)))) . "\n";

$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(utente di prova rimosso)\n\n";
echo 'RISULTATO: ' . ($ok ? 'guard can_download presente (403)' : 'guard ASSENTE <<< BUG');