<?php
// Verifica — l'HTML di project_tree_preview.php che main.js:646 mette in innerHTML
// deve essere escaping-completo, e lo stesso per sff_get_filters.php -> sff.js:53.
//
// La catena:
//
//   richiesta -> parseProjectAddRequest -> projectAddPrepare -> projectCreateSetup
//            -> project_setups.label (+ |CUSTOM: nel fingerprint)
//            -> getProjectTree -> includes/projects_tree.php
//            -> campo JSON 'html' -> projectTreePreview.innerHTML = data.html
//
// Il nome del custom setup e' CONTROLLO DAL CLIENT e finisce in due contesti diversi
// dentro il partial: testo (riga 248, 252) e attributo con doppie apici
// (data-setup-name, riga 250). Nessuna riga sentinella: il partial gira dentro la
// transazione che project_tree_preview.php annulla, quindi il test non scrive nulla
// e la tabella resta intatta. E' anche il motivo per cui questo percorso e' il modo
// giusto di testare lo escaping senza toccare i dati di produzione.
//
// Uso:
//   docker cp tmp/tree_preview_escape_check.php awi-php:/tmp/
//   docker exec awi-php sh -c 'cd /tmp && php tree_preview_escape_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

$base = 'http://nginx';
$conn = connectDB();
$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-54s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FALLITO');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

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
    // dal tipo (trappola #6 e #16).
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

/**
 * Secondo parere indipendente dai regex: si chiede a un parser HTML reale se esiste
 * qualche elemento o attributo che il template non ha scritto. Nel controllo negativo
 * questo e' stato il controllo che ha beccato la rottura dell'attributo, che la mia
 * espressione regolare non vedeva (dopo il payload c'era '<', non '"').
 */
function injectedMarkup(string $html): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $bad = [];
    foreach ($xp->query('//*') as $el) {
        $tag = strtolower($el->nodeName);
        if (in_array($tag, ['img', 'svg', 'script', 'iframe'], true)) {
            $bad[] = "element <$tag>";
        }
        foreach ($el->attributes as $attr) {
            if (stripos($attr->nodeName, 'on') === 0) {
                $bad[] = "attribute {$attr->nodeName} su <$tag>";
            }
        }
    }
    return array_values(array_unique($bad));
}

$tag = 'treesc_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = createUser($conn, $tag, $plain, false, true, ['/']);
$jar = '/tmp/te_' . bin2hex(random_bytes(4));
@file_put_contents($jar, '');

echo "\n=== sessione ===\n";
[$s, $loginHtml] = httpGet("$base/login.php", $jar);
preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $loginHtml, $m);
[$sLogin, ] = httpPost("$base/login.php", $jar, ['username' => $tag, 'password' => $plain,
    'csrf_token' => $m[1] ?? ''], true);
// Controllo di sessione (trappola #3): senza questo, un 403 o un 401 piu' avanti
// sarebbe indistinguibile da "non inietta". Un pagina di login contiene name="password".
[$sHome, $home] = httpGet("$base/projects.php", $jar);
$sessionOk = !str_contains($home, 'name="password"');
check('sessione stabilita', $sessionOk, "login HTTP $sLogin, projects.php HTTP $sHome");

$pid = (int)$conn->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();
$fid = (int)$conn->query("SELECT id FROM files WHERE deleted_at IS NULL
                           AND imgtype='LIGHT' ORDER BY id LIMIT 1")->fetchColumn();
check('progetto e LIGHT di prova disponibili', $pid > 0 && $fid > 0, "pid=$pid fid=$fid");

// Snapshot per dimostrare che il test non scrive. project_tree_preview.php crea un
// progetto e un setup dentro la transazione che poi annulla: se il rollback mancasse,
// questi contatori aumenterebbero.
$before = [];
foreach (['projects', 'project_setups', 'project_files', 'setup_overrides'] as $t) {
    $before[$t] = (int)$conn->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}

echo "\n=== caso positivo: la preview risponde e produce HTML ===\n";
[$st, $b] = httpPost("$base/api/project_tree_preview.php", $jar,
    ['ids' => [$fid], 'project_id' => $pid, 'overrides' => []]);
$j = json_decode($b, true);
$htmlLen = isset($j['html']) ? strlen($j['html']) : 0;
check('project_tree_preview.php -> 200 con HTML non vuoto',
    $st === 200 && $htmlLen > 0, "HTTP $st, html $htmlLen byte");
// Se questo fallisce, il resto del test non prova niente: senza albero non c'e' nessun
// sink da valutare, e 'nessuna iniezione' sarebbe vero solo perche' la pagina era vuota.
check('  l HTML contiene la struttura dell\'albero',
    $htmlLen > 0 && (str_contains($j['html'] ?? '', 'cal-group')
        || str_contains($j['html'] ?? '', 'tnode')), '');

echo "\n=== il nome del custom setup deve arrivare escaped in ogni contesto ===\n";

// parseProjectAddRequest tronca a 64 caratteri e sostituisce '|' con uno spazio, quindi
// il payload deve stare in 64 caratteri: < > " ' sopravvivono, | no.
$XSS = 'XSS<img src=x onerror=alert(1)>"\'<svg onload=alert(2)>';
$NEEDLE = 'XSS';
check('payload entro il limite di 64 caratteri', strlen($XSS) <= 64, strlen($XSS) . ' caratteri');

[$st2, $b2] = httpPost("$base/api/project_tree_preview.php", $jar,
    ['ids' => [$fid], 'project_id' => $pid,
     'overrides' => [(string)$fid => 'new:' . $XSS]]);
$j2 = json_decode($b2, true);
$html2 = (string)($j2['html'] ?? '');
printf("  richiesta ostile -> HTTP %d, html %d byte\n", $st2, strlen($html2));

// Controllo di positivita': il payload deve essere PRESENTE nella risposta. Se non lo
// fosse, tutte le verifiche sotto passerebbero triviale.
$occ = substr_count($html2, $NEEDLE);
$esc = substr_count($html2, $NEEDLE . '&lt;');
check('il payload e\' arrivato nell HTML', $occ > 0, "$occ occorrenze");
printf("  occorrenze: totali=%d  con '<' come entita=%d\n", $occ, $esc);

// Le due firme: testo e attributo.
$liveTag = preg_match('#' . preg_quote($NEEDLE, '#') . '\s*<(img|svg|script)#i', $html2, $m1);
$liveAttr = preg_match('#data-setup-name="[^"]*' . preg_quote($NEEDLE, '#') . '[^"]*"[^>]*>#i', $html2, $m2);
check('nessun tag vivo dopo il payload', !$liveTag,
    $liveTag ? '>>> ' . $m1[0] : '');
check('l\'attributo data-setup-name resta chiuso', !$liveAttr,
    $liveAttr ? '>>> ' . $m2[0] : '');

$bad = injectedMarkup($html2);
check('nessun elemento o handler iniettato (parser)', $bad === [],
    $bad ? implode('; ', $bad) : '');

// Il nome deve comparire nel testo del riepilogo setup, altrimenti la copertura e'
// illusoria. Nota il ": " fra '>' e il nome: projects_tree.php:248 stampa
// "Setup S1: <label>", quindi il payload NON segue immediatamente un '>'.
check('il nome compare nel testo del riepilogo setup (riga 248)',
    (bool)preg_match('#S\d+:\s*' . preg_quote($NEEDLE, '#') . '&lt;#', $html2), '');

// data-setup-name (riga 250) e' dentro il ramo `if (!$hypoMode)`, e la preview e'
// SEMPRE in hypoMode, quindi quel ramo non viene renderizzato qui: la sua assenza
// non e' una falla di escaping. Lo si afferma esplicitamente perche' il test non
// deve passare per silenzio su un contesto che non ha visitato.
$attrHere = str_contains($html2, 'data-setup-name=');
check('  il ramo hypoMode non renderizza il pulsante di rinomina', !$attrHere,
    $attrHere ? '>>> presente: il ramo non-hypo sarebbe stato visitato'
              : 'l\'attributo e\' coperto dal test a harness, che esercita anche hypoMode=false');

// Byte grezzi, cosi' il risultato si giudica a occhio e non dal solo esito del check.
echo "\n--- ogni occorrenza del payload, con 90 caratteri di contesto ---\n";
if (preg_match_all('#.{90}' . preg_quote($NEEDLE, '#') . '.{60}#s', $html2, $m)) {
    foreach ($m[0] as $ctx) {
        echo '  ...' . str_replace(["\n", "\r", '  '], [' ', ' ', ' '], $ctx) . "...\n";
    }
}

echo "\n=== il test non deve scrivere nulla ===\n";
$after = [];
foreach (['projects', 'project_setups', 'project_files', 'setup_overrides'] as $t) {
    $after[$t] = (int)$conn->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}
foreach ($before as $t => $n0) {
    check("  $t invariato ($n0)", $after[$t] === $n0,
        $after[$t] === $n0 ? '' : "ora $after[$t], delta " . ($after[$t] - $n0));
}

echo "\n=== sff_get_filters.php -> sff.js:53 ===\n";
// Qui il valore di riferimento viene da files.<colonna>, cioe' dall'header FITS, e NON dalla
// richiesta: questo test, che non scrive in `files`, puo' solo dimostrare che il percorso
// del sink e' raggiungibile e risponde. La prova che l'header non possa eseguire codice
// richiede una riga in `files` e sta in sff_filter_http_escape_check.php, che scrive e
// cancella una riga di prova per id. Questo archivio comunque non contiene valori con
// '<', '>' o '"', quindi qui non c'e' nemmeno un payload naturale da provare.
[$stS, $bS] = httpGet("$base/api/sff_get_filters.php?id=$fid&type=lights", $jar);
check('sff_get_filters.php -> 200 (percorso del sink dimostrato raggiungibile)',
    $stS === 200 && strlen($bS) > 0, 'HTTP ' . $stS . ', ' . strlen($bS) . ' byte');
$liveTagS = preg_match('#<(img|svg|script)#i', $bS, $mS);
check('  nessun tag iniettato nel pannello filtri', !$liveTagS,
    $liveTagS ? '>>> ' . $mS[0] : 'il markup statico del template contiene solo <div>/<input>');
$badS = injectedMarkup($bS);
check('  nessun handler iniettato (parser)', $badS === [],
    $badS ? implode('; ', $badS) : '');

$conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
$conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
@unlink($jar);
echo "\n(prova e utente di prova rimossi)\n";
echo 'RISULTATO: ' . ($failed ? 'FALLITI: ' . implode(', ', $failed)
    : 'la catena server -> innerHTML e\' escaping-completa') . "\n";
exit($failed ? 1 : 0);
