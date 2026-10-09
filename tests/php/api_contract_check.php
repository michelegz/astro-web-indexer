<?php
// Verifica 13.33, 13.34, §13.35 — contratto HTTP degli endpoint projects.
//
//  13.33 la validazione fallita rispondeva 200 {"success":false}, senza una chiave
//        'error': main.js fa fetch().then(r => r.json()) e solleva solo su
//        data.error, quindi l'utente vedeva una preview vuota senza spiegazione
//  13.34 sessione scaduta -> 302 a /login.php, cioe' HTML dove il client vuole JSON
//  13.35 project_id inesistente -> 200 success:true e un link a un progetto che non
//        esiste
//
// Gira sia sul codice pre-fix sia su quello corretto: sul primo riporta i difetti.
//
// Uso:  docker cp tmp/api_contract_check.php awi-php:/tmp/
//       docker exec awi-php sh -c 'cd /tmp && php api_contract_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

$base = 'http://nginx';
$conn = connectDB();
$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-54s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

function httpPost(string $url, string $jar, $payload, bool $json = true): array
{
    $body = is_array($payload) ? http_build_query($payload) : (string)$payload;
    $ctype = is_array($payload) ? 'application/x-www-form-urlencoded' : 'application/json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json ? $body : $body,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . $ctype],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redir = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$st, $b, $redir];
}

function httpGet(string $url, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redir = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$st, $b, $redir];
}

// ---- dati di prova -------------------------------------------------------
$pid = (int)$conn->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();
$maxPid = (int)$conn->query('SELECT COALESCE(MAX(id), 0) FROM projects')->fetchColumn();
$ghost = $maxPid + 100000;
$hasGhost = (int)$conn->query('SELECT COUNT(*) FROM projects WHERE id = ' . $ghost)->fetchColumn() === 0;
check('id progetto inesistente scelto', $hasGhost && $pid > 0, "reale=$pid fantasma=$ghost");

$fid = (int)$conn->query("SELECT id FROM files WHERE deleted_at IS NULL AND imgtype='LIGHT'
                           AND date_obs IS NOT NULL ORDER BY id LIMIT 1")->fetchColumn();

// =====================================================================
// 13.34 — sessione scaduta
// =====================================================================
echo "\n=== 13.34: sessione assente -> 401 JSON, non 302 HTML ===\n";

// Un jar vuoto: nessun cookie di sessione, quindi isLoggedIn() e' falso.
$anon = '/tmp/ac_anon_' . bin2hex(random_bytes(4));
@file_put_contents($anon, '');

$jsonEndpoints = ['project_preview.php', 'project_add.php',
    'project_tree_preview.php', 'project_export_preview.php'];
foreach ($jsonEndpoints as $ep) {
    $payload = $ep === 'project_export_preview.php'
        ? ['project_id' => $pid]
        : ['ids' => [$fid], 'project_id' => $pid, 'overrides' => []];
    [$st, $b, $redir] = httpPost("$base/api/$ep", $anon, json_encode($payload));
    $j = json_decode($b, true);
    $isHtml = stripos(ltrim($b), '<') === 0;
    check("$ep -> 401 con JSON", $st === 401 && is_array($j) && isset($j['error']) && !$isHtml,
        'HTTP ' . $st . ($redir !== '' ? ' -> ' . $redir : '') . ', ' . substr(trim($b), 0, 50));
}

// Il download ZIP e' diverso: la risposta e' un file che il browser salva, e un
// 401 JSON arriverebbe come .zip corrotto. Li' il redirect al login e' corretto e
// deve restare.
[$st, $b, $redir] = httpPost("$base/api/export_project_zip.php", $anon, ['project_id' => $pid], false);
check('export_project_zip.php -> redirect al login (non JSON)',
    $st === 302 && str_contains($redir, 'login.php'),
    'HTTP ' . $st . ' -> ' . ($redir !== '' ? $redir : '(nessun redirect)'));

// =====================================================================
// 13.33 — validazione fallita
// =====================================================================
echo "\n=== 13.33: ids vuoti -> 400 con chiave error ===\n";

// Serve una sessione valida, altrimenti si fermerebbe al 401 di prima.
$tag = 'apicontract_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $plain, false, true, ['/']);
$jar = '/tmp/ac_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
[$s, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
[$st, $b] = httpPost("$base/login.php", $jar, ['username' => $tag, 'password' => $plain,
    'csrf_token' => $m[1] ?? '']);
[$sHome, $home] = httpGet("$base/?panel=projects", $jar);
check('sessione valida', $sHome === 200 && !str_contains($home, 'name="password"'), 'HTTP ' . $sHome);

foreach (['project_add.php', 'project_preview.php', 'project_tree_preview.php'] as $ep) {
    [$st, $b] = httpPost("$base/api/$ep", $jar, json_encode(['ids' => [], 'project_id' => $pid]));
    $j = json_decode($b, true);
    // La chiave 'error' e' quello che main.js effettivamente guarda: senza, il
    // ramo successivo costruiva una preview vuota senza alcun messaggio.
    check("$ep: ids [] -> 400 + error", $st === 400 && is_array($j) && isset($j['error']),
        'HTTP ' . $st . ', chiavi=' . (is_array($j) ? implode(',', array_keys($j)) : substr(trim($b), 0, 40)));
    check("  " . substr($ep, 8) . ': niente success:true fuorviante',
        !is_array($j) || empty($j['success']),
        is_array($j) && !empty($j['success']) ? 'ANCORA success:true' : '');
}

// Il percorso felice deve restare intatto: ids non vuoti -> 200 con gruppi.
[$st, $b] = httpPost("$base/api/project_preview.php", $jar,
    json_encode(['ids' => [$fid], 'project_id' => $pid, 'overrides' => []]));
$j = json_decode($b, true);
check('project_preview.php con ids reali -> 200 con gruppi',
    $st === 200 && is_array($j) && !empty($j['groups']) && ($j['success'] ?? false) === true,
    'HTTP ' . $st . ', groups=' . (isset($j['groups']) ? count($j['groups']) : '?'));

// =====================================================================
// 13.35 — progetto inesistente
// =====================================================================
echo "\n=== 13.35: project_id inesistente -> 404, mai success:true ===\n";

foreach ($jsonEndpoints as $ep) {
    $payload = $ep === 'project_export_preview.php'
        ? ['project_id' => $ghost]
        : ['ids' => [$fid], 'project_id' => $ghost, 'overrides' => []];
    [$st, $b] = httpPost("$base/api/$ep", $jar, json_encode($payload));
    $j = json_decode($b, true);
    $saysSuccess = is_array($j) && ($j['success'] ?? false) === true;
    check("$ep -> 404 e nessun success:true",
        $st === 404 && is_array($j) && isset($j['error']) && !$saysSuccess,
        'HTTP ' . $st . ', ' . substr(trim($b), 0, 55));
}

// Il caso che il piano segnalava: l'add rispondeva 200 success:true e il JS
// offriva "vai al progetto" verso un id inesistente. Nota che l'errore NON deve
// contenere project_id: e' proprio quel campo che il JS userebbe per il link.
[$st, $b] = httpPost("$base/api/project_add.php", $jar,
    json_encode(['ids' => [$fid], 'project_id' => $ghost, 'overrides' => []]));
$j = json_decode($b, true);
check('project_add.php non restituisce un project_id utilizzabile',
    $st === 404 && empty($j['project_id'] ?? null),
    'HTTP ' . $st . ', project_id=' . var_export($j['project_id'] ?? null, true));

// E il download ZIP, che risponde in die() e non in JSON. Serve il token CSRF,
// altrimenti si fermerebbe al 403 del batch precedente e non arriverebbe qui.
[$st, $b] = httpPost("$base/api/export_project_zip.php", $jar,
    ['project_id' => $ghost, 'csrf_token' => $m[1] ?? ''], false);
check('export_project_zip.php -> 404 col token valido', $st === 404,
    'HTTP ' . $st . ', ' . substr(trim($b), 0, 50));

// Nessuna scrittura: il progetto fantasma non deve essere stato creato.
$stillGhost = (int)$conn->query('SELECT COUNT(*) FROM projects WHERE id = ' . $ghost)->fetchColumn();
check('nessun progetto creato per un id inesistente', $stillGhost === 0,
    $stillGhost === 0 ? '' : 'CREATO');

// =====================================================================
// rendering: nessun warning PHP nella pagina, chiavi i18n risolte
// =====================================================================
echo "\n=== rendering delle pagine projects ===\n";

// 13.41 aveva fatto require_once di igroup_files_table.php dentro il bootstrap.
// Quel file e' un partial che rende markup e si aspetta $grp/$gi dal chiamante, quindi
// eseguendolo li' produceva warning HTML che finivano nel corpo JSON. Il test lo ha
// preso, ma va tenuto sotto controllo: nessun warning deve raggiungere l'output.
foreach (['/?panel=projects' => 'home projects', '/projects.php?id=' . $pid => 'dettaglio progetto'] as $path => $label) {
    [$st, $page] = httpGet("$base$path", $jar);
    $noise = [];
    foreach (['Warning', 'Notice', 'Deprecated', 'Fatal error'] as $lvl) {
        if (stripos($page, "<b>$lvl</b>") !== false || stripos($page, "$lvl:") !== false) {
            $noise[] = $lvl;
        }
    }
    check("$label: HTML pulito", $st === 200 && $noise === [],
        'HTTP ' . $st . ', ' . strlen($page) . ' byte' . ($noise ? ', rumore: ' . implode('/', $noise) : ''));
}

// La chiave di 13.35 deve esistere in tutte le lingue, altrimenti nel 404 finirebbe
// il nome del parametro invece di un messaggio. I file di lingua sono array puri, non
// serve caricare language.php (che vuole getBestLanguage() e la sessione).
$langs = glob('/var/www/html/languages/*.php');
$bad = [];
foreach ($langs as $lf) {
    $arr = require $lf;
    if (!isset($arr['projects_not_found']) || trim((string)$arr['projects_not_found']) === ''
        || str_contains((string)$arr['projects_not_found'], 'projects_not_found')) {
        $bad[] = basename($lf, '.php');
    }
}
check('projects_not_found in tutte le lingue', $bad === [],
    count($langs) . ' lingue' . ($bad ? ', manca in: ' . implode(', ', $bad) : ''));

// ---- cleanup -------------------------------------------------------------
$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
@unlink($anon);
echo "\n(prove e utente di prova rimossi)\n";
echo 'RESULT: ' . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'contratto HTTP rispettato') . "\n";
