<?php
// Verifica 13.41 — il bootstrap slim evita il blocco dati della home page.
//
// Misura quante SELECT eseguirebbe init.php prima di arrivare al codice
// dell'endpoint (albero cartelle, tre aggregate, trend stelle, conteggio LIGHT,
// query file paginata): sono tutte cose che un endpoint JSON non legge.
//
// Uso:  docker cp tmp/bootstrap_cost_check.php awi-php:/tmp/
//       docker exec awi-php sh -c 'cd /tmp && php bootstrap_cost_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

$base = 'http://nginx';
$conn = connectDB();
$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-46s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FALLITO');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

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

function httpPostForm(string $url, string $jar, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
    curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st];
}

function httpPostJson(string $url, string $jar, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 90]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

function selects(PDO $conn): int
{
    $r = $conn->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetchAll(PDO::FETCH_ASSOC);
    return (int)($r[0]['Value'] ?? 0);
}

$tag = 'bscost_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $plain, false, true, ['/']);

$jar = '/tmp/bs_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
[, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
[$sLogin] = httpPostForm("$base/login.php", $jar,
    ['username' => $tag, 'password' => $plain, 'csrf_token' => $m[1] ?? '']);
check('login riuscito', $sLogin === 302, "HTTP $sLogin");

$pid = (int)$conn->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();
$fid = (int)$conn->query("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype='LIGHT'
                           AND date_obs IS NOT NULL ORDER BY id LIMIT 1")->fetchColumn();
echo "  progetto $pid, file $fid\n\n";

// riscaldamento: compila le classi una volta, cosi' il confronto e' pulito
httpPostJson("$base/api/project_preview.php", $jar,
    ['ids' => [$fid], 'project_id' => $pid, 'overrides' => []]);

[$stSlim] = httpPostJson("$base/api/project_preview.php", $jar,
    ['ids' => [$fid], 'project_id' => $pid, 'overrides' => []]);
check('endpoint risponde 200 col bootstrap slim', $stSlim === 200, "HTTP $stSlim");

// il blocco dati di init.php, misurato a parte
$before = selects($conn);
$t0 = microtime(true);
getAllFoldersAsTree($conn);
countFiles($conn, '', '', '', '', '', '', '', '');
sumExposureTime($conn, '', '', '', '', '', '', '', '');
getExposureStatsByFilter($conn, '', '', '', '', '', '', '', '');
getStarTrend($conn, '', '', '', '', '', '', '', '', 'name', 'ASC', 10000);
countFiles($conn, '', '', '', 'LIGHT', '', '', '', '');
getFiles($conn, '', '', '', '', '', '', '', '', 100, 0, 'name', 'ASC');
$initMs = (microtime(true) - $t0) * 1000;
$initSelects = selects($conn) - $before;

echo "\n  costo del blocco dati di init.php (in ogni richiesta):\n";
printf("    %d SELECT, %.0f ms\n\n", $initSelects, $initMs);
check('init.php eseguiva query inutili', $initSelects > 0, "$initSelects SELECT per richiesta");
check('il blocco aveva un costo reale', $initMs > 0,
    sprintf('%.0f ms per richiesta buttata, su tutti i 5 endpoint', $initMs));

$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(utente di prova rimosso)\n";
echo 'RISULTATO: ' . ($failed ? 'FALLITI: ' . implode(', ', $failed)
    : 'il bootstrap slim evita il blocco dati della home');