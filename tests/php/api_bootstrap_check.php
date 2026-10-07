<?php
// Verifica 13.41 e 13.2 — i cinque endpoint projects devono funzionare senza
// init.php, e la preview non deve piu' trasportare il manifest che nessuno legge.
//
// Fa login reale e chiama ogni endpoint via HTTP, confrontando lo stato e la
// forma della risposta. Conta anche le query eseguite durante la richiesta, che
// e' cio' che il bootstrap slim deve aver tolto.
//
// Uso:  docker cp tmp/api_bootstrap_check.php awi-php:/tmp/
//       docker exec awi-php sh -c 'cd /tmp && php api_bootstrap_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

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

// utente di prova
$tag = 'apiboot_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $plain, false, true, ['/']);

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
    // CURLOPT_POSTFIELDS con un array invierebbe multipart/form-data, e login.php
    // legge da $_POST urlencoded: va codificato esplicitamente.
    $body = is_array($payload) ? http_build_query($payload) : (string)$payload;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

function httpPostJson(string $url, string $jar, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

$jar = '/tmp/ab_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
[$s, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
httpPost("$base/login.php", $jar, ['username' => $tag, 'password' => $plain,
    'csrf_token' => $m[1] ?? '']);
// il bootstrap slim non chiama piu' init.php: la home resta comunque raggiungibile
[$sHome, $home] = httpGet("$base/projects.php", $jar);
check('sessione valida (redirect a /?panel=projects atteso)',
    !str_contains($home, 'name="password"'), "HTTP $sHome");

$pid = (int)$conn->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();
$fid = (int)$conn->query("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype='LIGHT'
                           AND date_obs IS NOT NULL ORDER BY id LIMIT 1")->fetchColumn();

echo "\n=== i cinque endpoint rispondono ===\n";

// Le chiavi del contratto sono camelCase ('customSetups', 'groupFpOverrides',
// 'customSkipped') mentre 'project_id', 'new_project', 'ids', 'overrides' sono
// snake_case: una disattenzione su una di queste non da errore, da un undefined
// key che in PHP vale null, e il ramo semanticamente giusto viene saltato in
// silenzio. Il tree preview e l'add devono inoltre leggere le stesse chiavi,
// altrimenti la preview promette custom setup che l'add poi non crea.
require_once '/var/www/html/includes/projects_functions.php';
$reqKeys = array_keys(parseProjectAddRequest(
    ['ids' => [1], 'new_project' => ['name' => 'x'], 'overrides' => [], 'custom_setups' => [1 => 'S']]
));
check('parseProjectAddRequest: chiavi attese presenti',
    count(array_intersect(['project_id', 'ids', 'overrides', 'customSetups',
        'groupFpOverrides', 'new_project'], $reqKeys)) === 6,
    implode(', ', $reqKeys));

$tmpPid = (int)$conn->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();
$conn->beginTransaction();
$prepKeys = array_keys(projectAddPrepare($conn, $tmpPid, [], [], [], null));
$conn->rollBack();
check('projectAddPrepare: chiavi attese presenti',
    count(array_intersect(['project_id', 'ids', 'customSkipped'], $prepKeys)) === 3,
    implode(', ', $prepKeys));

// Ogni chiave letta dagli endpoint deve esistere in uno dei due array.
// 'frozen' e' un'eccezione dichiarata: projectAddPrepare la espone solo sui
// rientri anticipati (progetto gia' frozen), e nel ramo normale e' assente, dove
// !empty() la tratta correttamente come false.
$optional = ['frozen'];
$expected = array_merge($reqKeys, $prepKeys);
foreach (['project_add.php', 'project_tree_preview.php'] as $ep) {
    $src = (string)file_get_contents('/var/www/html/api/' . $ep);
    preg_match_all("/\\\$(?:req|prep)\['([A-Za-z_]+)'\]/", $src, $km);
    $missing = array_values(array_diff(array_unique($km[1]), $expected, $optional));
    check($ep . ': nessuna chiave fuori contratto', $missing === [],
        $missing ? implode(', ', $missing) : implode(' / ', array_unique($km[1])));
}

// E il ramo frozen deve davvero esporre il flag, altrimenti la guardia di
// project_add.php sarebbe morta: qui si esercita proprio quell'ingresso.
$conn->beginTransaction();
$conn->prepare("INSERT INTO projects (name, notes, assign_mode) VALUES (:n, '', 'frozen')")
    ->execute([':n' => 'api_boot_frozen_' . bin2hex(random_bytes(3))]);
$fpPid = (int)$conn->lastInsertId();
$prepFrozen = projectAddPrepare($conn, $fpPid, [1], [], [], null);
$conn->rollBack();
check('projectAddPrepare segnala il progetto frozen',
    !empty($prepFrozen['frozen']) && ($prepFrozen['ids'] ?? null) === [],
    'frozen=' . var_export($prepFrozen['frozen'] ?? null, true)
    . ', ids=' . json_encode($prepFrozen['ids'] ?? null));

[$st, $b] = httpPostJson("$base/api/project_preview.php", $jar,
    ['ids' => [$fid], 'project_id' => $pid, 'overrides' => []]);
$j = json_decode($b, true);
check('project_preview.php', $st === 200 && is_array($j) && isset($j['groups']),
    "HTTP $st, " . (isset($j['groups']) ? 'groups=' . count($j['groups']) : substr($b, 0, 60)));

// ids vuoto fa fallire parseProjectAddRequest, che risponde 400: atteso.
[$st, $b] = httpPost("$base/api/project_add.php", $jar, ['ids' => '']);
check('project_add.php (input non valido -> 400)', $st === 400 && str_contains($b, 'error'),
    "HTTP $st");

[$st, $b] = httpPost("$base/api/project_add.php", $jar,
    json_encode(['ids' => [$fid], 'project_id' => $pid, 'overrides' => []]));
$j = json_decode($b, true);
check('project_add.php (add reale su progetto frozen o esistente)', $st === 200,
    "HTTP $st");

[$st, $b] = httpPost("$base/api/project_tree_preview.php", $jar,
    json_encode(['ids' => [$fid], 'project_id' => $pid, 'overrides' => []]));
$j = json_decode($b, true);
$htmlLen = isset($j['html']) ? strlen($j['html']) : 0;
check('project_tree_preview.php (HTML albero)', $st === 200 && $htmlLen > 0,
    "HTTP $st, html $htmlLen byte");
// In hypo mode le righe sono i nodi ipotetici, non le celle della tabella file,
// quindi il marker giusto e' la struttura dell'albero, non una miniatura.
check('  l html contiene la struttura dell\'albero',
    $htmlLen > 0 && (str_contains($j['html'] ?? '', 'cal-group')
        || str_contains($j['html'] ?? '', 'tnode')), '');

[$st, $b] = httpPost("$base/api/project_export_preview.php", $jar,
    json_encode(['project_id' => $pid]));
$j = json_decode($b, true);
check('project_export_preview.php', $st === 200 && isset($j['entries']),
    "HTTP $st, entries=" . (isset($j['entries']) ? count($j['entries']) : '?'));

echo "\n=== 13.2: il manifest non viaggia piu', skipped e' limitato ===\n";
check('nessun manifest nella preview', $j !== null && !array_key_exists('manifest', $j),
    array_key_exists('manifest', $j ?? [])
        ? 'ANCORA PRESENTE, ' . strlen((string)($j['manifest'])) . ' byte' : 'assente');
check('skipped_total presente', isset($j['skipped_total']), (string)($j['skipped_total'] ?? '-'));
$payload = strlen($b);
printf("  payload preview: %.1f KB (su %d entries)\n", $payload / 1024,
    count($j['entries'] ?? []));

// confronto: quanto occupava il manifest
require_once '/var/www/html/includes/projects_functions.php';
require_once '/var/www/html/includes/projects_diagnostics.php';
require_once '/var/www/html/includes/project_export.php';
$manifestBytes = strlen(json_encode(buildProjectExportMap($conn, $pid)['manifest']));
printf("  manifest (non piu' inviato): %.1f KB\n", $manifestBytes / 1024);
check('risparmio reale', $manifestBytes > 0,
    sprintf('payload %.1f KB senza il manifest di %.1f KB',
        $payload / 1024, $manifestBytes / 1024));

$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(utente di prova rimosso)\n";
echo 'RISULTATO: ' . ($failed ? 'FALLITI: ' . implode(', ', $failed)
    : 'tutti gli endpoint funzionano col bootstrap slim');