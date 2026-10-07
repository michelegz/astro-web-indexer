<?php
// Verifica 13.9, 13.11, 13.13, 13.18 — riscontro all'utente e markup.
//
//  13.9  gli errori di save/rename erano mostrati con $e->getMessage() su un
//        catch(Exception): un TypeError sfuggiva come fatal e un PDOException
//        mostrava all'utente la query che era fallita.
//  13.11 il troncamento a 2000 file era invisibile: una preview dei primi 2000
//        sembrava una risposta completa.
//  13.13 con la colonna nascosta non veniva renderizzato alcun <td>, quindi il
//        chart leggeva tutti null e saltava il disegno, lasciando un riquadro
//        vuoto che sembrava rotto.
//  13.18 il template i18n finiva con ": " e il JS aggiungeva " (nome1, nome2)":
//        due spazi.
//
// Uso:  docker cp tmp/ux_feedback_check.php awi-php:/tmp/
//       docker exec awi-php sh -c 'cd /tmp && php ux_feedback_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/http_json.php';
require_once '/var/www/html/includes/projects_functions.php';

if (!function_exists('__')) {
    function __(string $key, array $replace = []): string
    {
        $out = $key;
        foreach ($replace as $k => $v) {
            $out = str_replace('{' . $k . '}', (string)$v, $out);
        }
        return $out;
    }
}

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
    $redir = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$st, $b, $redir];
}

function httpPost(string $url, string $jar, array $payload, bool $form = false): array
{
    $body = $form ? http_build_query($payload) : json_encode($payload);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . ($form
            ? 'application/x-www-form-urlencoded' : 'application/json')],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redir = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$st, $b, $redir];
}

// =====================================================================
// 13.18 — niente doppio spazio
// =====================================================================
echo "\n=== 13.18: il template non deve finire con uno spazio ===\n";

$doubleSpace = [];
foreach (glob('/var/www/html/languages/*.php') as $lf) {
    $arr = require $lf;
    $v = (string)($arr['filter_mapping_unmapped'] ?? '');
    // Il JS aggiunge " (" + nomi + ")": uno spazio finale nel template ne fa due.
    if ($v !== '' && str_ends_with($v, ' ')) {
        $doubleSpace[] = basename($lf, '.php');
    }
}
check('nessuna lingua fa finire il template con uno spazio',
    $doubleSpace === [],
    $doubleSpace === [] ? '5 lingue' : 'con spazio: ' . implode(', ', $doubleSpace));

$itArr = require '/var/www/html/languages/it.php';
$tmpl = (string)$itArr['filter_mapping_unmapped'];
$rendered = str_replace('{count}', 2, $tmpl) . ' (Ha, OIII)';
check('la riga finale non ha due spazi', !str_contains($rendered, ':  ('),
    '"' . $rendered . '"');

// =====================================================================
// 13.13 — chart e colonne nascoste
// =====================================================================
echo "\n=== 13.13: il chart dice quando la sua colonna e' spenta ===\n";

$pid = (int)$conn->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();
check('progetto di prova', $pid > 0, "id=$pid");

$src = (string)file_get_contents('/var/www/html/projects.php');
check('il render del chart conosce le colonne nascoste',
    str_contains($src, 'in_array($mk, $hiddenColsProjects, true)'), '');
check('  e non emette il canvas per una metrica nascosta',
    (bool)preg_match(
        '/in_array\(\$mk, \$hiddenColsProjects, true\).*?continue;.*?<canvas/s', $src), '');
check('  e spiega il motivo all\'utente',
    str_contains($src, "__('projects_chart_column_hidden')"), '');

// La chiave deve esistere in tutte le lingue, altrimenti nel 200 vuota.
$bad = [];
foreach (glob('/var/www/html/languages/*.php') as $lf) {
    $arr = require $lf;
    if (trim((string)($arr['projects_chart_column_hidden'] ?? '')) === '') {
        $bad[] = basename($lf, '.php');
    }
}
check('projects_chart_column_hidden in tutte le lingue', $bad === [],
    $bad === [] ? '5 lingue' : 'manca in: ' . implode(', ', $bad));

// E la pagina deve rendersi senza warning e mostrare il grafico quando la colonna
// e' visibile (il caso normale), senza che il fix lo abbia spento del tutto.
$tag = 'uxfb_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $plain, false, true, ['/']);
$jar = '/tmp/ux_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');
[$s, $html] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
httpPost("$base/login.php", $jar, ['username' => $tag, 'password' => $plain,
    'csrf_token' => $m[1] ?? ''], true);

[$st, $page] = httpGet("$base/projects.php?id=$pid", $jar);
if ($st === 302) {
    [$st, $page] = httpGet("$base/projects.php", $jar);
}
$noise = [];
foreach (['Warning', 'Notice', 'Deprecated', 'Fatal error'] as $lvl) {
    if (stripos($page, "<b>$lvl</b>") !== false) {
        $noise[] = $lvl;
    }
}
check('la pagina projects si rende senza warning PHP', $noise === [],
    'HTTP ' . $st . ', ' . strlen($page) . ' byte' . ($noise ? ': ' . implode('/', $noise) : ''));

// =====================================================================
// 13.11 — il troncamento a 2000 è dichiarato
// =====================================================================
echo "\n=== 13.11: il troncamento a 2000 file viene dichiarato ===\n";

$prevSrc = (string)file_get_contents('/var/www/html/api/project_preview.php');
check('project_preview conta gli id scartati',
    str_contains($prevSrc, '$truncated = count($selectedIds) - count($ids);'), '');
check('  e lo espone nella risposta',
    str_contains($prevSrc, "'truncated' => \$truncated"), '');
check('  e aggiunge una riga agli skipped',
    str_contains($prevSrc, "__('projects_truncated'"), '');

$bad = [];
foreach (glob('/var/www/html/languages/*.php') as $lf) {
    $arr = require $lf;
    if (trim((string)($arr['projects_truncated'] ?? '')) === '') {
        $bad[] = basename($lf, '.php');
    }
}
check('projects_truncated in tutte le lingue', $bad === [],
    $bad === [] ? '5 lingue' : 'manca in: ' . implode(', ', $bad));

// Il conteggio deve essere reale: 2001 id di cui 2000 analizzati.
$fakeIds = range(1, 2001);
[$st, $b] = httpPost("$base/api/project_preview.php", $jar,
    ['ids' => $fakeIds, 'project_id' => $pid, 'overrides' => []]);
$j = json_decode($b, true);
check('la risposta dichiara il troncamento',
    $st === 200 && is_array($j) && ($j['truncated'] ?? null) === 1,
    'HTTP ' . $st . ', truncated=' . var_export($j['truncated'] ?? null, true));
$mentioned = false;
foreach ((array)($j['skipped'] ?? []) as $s) {
    if (str_contains((string)($s['message'] ?? ''), '2000')) {
        $mentioned = true;
    }
}
check('  e lo dice anche fra gli skipped', $mentioned, '');

// Sotto il limite non deve inventare un troncamento.
[$st, $b] = httpPost("$base/api/project_preview.php", $jar,
    ['ids' => [1, 2, 3], 'project_id' => $pid, 'overrides' => []]);
$j = json_decode($b, true);
check('sotto il limite -> truncated 0', $st === 200 && ($j['truncated'] ?? null) === 0,
    'truncated=' . var_export($j['truncated'] ?? null, true));

// =====================================================================
// 13.9 — gli errori sono visibili senza perdere dettagli
// =====================================================================
echo "\n=== 13.9: gli errori di azione non sono invisibili ne' pericolosi ===\n";

check('projects.php cattura Throwable, non Exception',
    (bool)preg_match('/\}\s*catch\s*\(\s*Throwable\s+\$e\s*\)\s*\{/', $src), '');
check('un InvalidArgumentException mostra il suo messaggio',
    str_contains($src, '$e instanceof InvalidArgumentException'), '');
check('un errore generico non mostra la query',
    str_contains($src, "__('projects_error_generic')") && str_contains($src, 'error_log('), '');

$bad = [];
foreach (glob('/var/www/html/languages/*.php') as $lf) {
    $arr = require $lf;
    if (trim((string)($arr['projects_error_generic'] ?? '')) === '') {
        $bad[] = basename($lf, '.php');
    }
}
check('projects_error_generic in tutte le lingue', $bad === [],
    $bad === [] ? '5 lingue' : 'manca in: ' . implode(', ', $bad));

// Un'azione che fallisce davvero deve mostrare qualcosa all'utente. rename_setup
// con un id inesistente solleva InvalidArgumentException, quindi il messaggio
// specifico deve arrivare a pagina.
$csrf = null;
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page, $cm);
$csrf = $cm[1] ?? ($m[1] ?? '');
[$st, $out, $redir] = httpPost("$base/projects.php", $jar, [
    'action' => 'rename_setup',
    'project_id' => (string)$pid,
    'setup_id' => '99999999',
    'name' => 'x',
    'csrf_token' => $csrf,
], true);
// L'errore deve arrivare all'utente come testo reso, non come chiave: cercare la
// chiave sarebbe un falso pass perche' la chiave non finisce mai nell'HTML.
$enArr = require '/var/www/html/languages/en.php';
$errText = (string)($enArr['projects_error_name'] ?? '');
$hasErr = $errText !== '' && str_contains($out, $errText);
check('un\'azione fallita mostra il messaggio all\'utente',
    $hasErr, 'HTTP ' . $st . ($redir !== '' ? ' -> ' . $redir : '') . ', '
    . strlen($out) . ' byte, cercato "' . $errText . '"');

$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(prove e utente di prova rimossi)\n";
echo 'RISULTATO: ' . ($failed ? 'FALLITI: ' . implode(', ', $failed)
    : 'riscontro all\'utente e markup corretti') . "\n";