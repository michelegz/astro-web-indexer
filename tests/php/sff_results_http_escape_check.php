<?php
// Verifica end-to-end — la tabella dei risultati che sff.js:152 mette in innerHTML non
// deve eseguire un nome di file o un percorso dall'archivio.
//
// Il buco che questo chiude: `sff_results_table.php` era stato solo LETTO e verificato a
// occhio, mai guidato con dati ostili. E' il secondo degli HTML che arrivano al client
// costruiti dal server (il primo e' project_tree_preview.php, coperto da
// tree_preview_escape_check.php).
//
//   sff.js:152  sffResultsPanel.innerHTML = data.html
//     <- POST /api/find_calibration_files.php  {file_id, search_type, filters}
//       -> SELECT id, name, path, date_obs, exptime, ccd_temp, xbinning, ybinning,
//                width, height, (thumb IS NOT NULL ...) AS has_thumb  FROM files ...
//       -> render_sff_results_table($results)
//       -> campo JSON 'html'
//
// I valori controllabili dall'archivio che arrivano alla tabella sono due stringhe:
// `name` (varchar 255) e `path` (varchar 768). Il resto sono numeri, e con numeri questa
// tabella non puo' essere iniettata comunque: la prova del percorso vero serve per `name`
// e `path`, che sono anche i due che finiscono in un href e in un value=.
//
// Il file di prova non va su disco: due righe in `files`, cancellate per id esatto.
// Nessun reindex, niente in /var/fits.
//
// Una delle due righe ha `date_obs` NULL di proposito. `sff_results_table.php:58` fa
// `substr($file['date_obs'], 0, 10)` senza controllare il NULL, e in PHP 8.1+ substr() su
// NULL e' deprecato. L'archivio di questa copia non ha nessun LIGHT senza date_obs, ma
// reindex.py mette date_obs = NULL quando il DATE-OBS non e' parsabile, quindi il ramo
// esiste. Qui non lo si corregge e non lo si dichiara difetto: si OSSERVA cosa arriva al
// client, e il verdetto lo dice il corpo della risposta.
//
// Igiene (trappole #44, #46): una connessione sola, lock wait basso, DELETE per id, e
// controllo che il sito risponda prima e dopo.
//
// Uso:
//   docker cp tmp/sff_results_http_escape_check.php awi-php:/tmp/
//   docker exec awi-php sh -c 'cd /tmp && php sff_results_http_escape_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

$base = 'http://nginx';
$failed = [];
$userTag = 'sffres_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = null;
$ids = [];
$jar = '/tmp/sffres_' . bin2hex(random_bytes(4));

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-54s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FALLITO');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

$XSS = 'XSS<img src=x onerror=alert(1)>"\'<svg onload=alert(2)>';
$NEEDLE = 'XSS';

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

function httpPost(string $url, string $jar, $payload, bool $form = false): array
{
    // login.php legge da $_POST urlencoded: con un array e CURLOPT_POSTFIELDS verrebbe
    // multipart e il login fallirebbe in silenzio (trappole #7 e #16).
    $body = $form ? http_build_query((array)$payload) : json_encode($payload);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . ($form
            ? 'application/x-www-form-urlencoded' : 'application/json')],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

/**
 * Secondo parere indipendente dai regex, sul DOM parsato. Qui il template scrive <img>
 * di proposito quando c'e' la miniatura, quindi la lista e' di DIVIETO come in
 * file_cells_escape_check.php, e le <img> legittime vengono controllate a parte.
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
        if (in_array($tag, ['svg', 'script', 'iframe', 'object', 'embed', 'form'], true)) {
            $bad[] = "element <$tag> non previsto dal template";
        }
        foreach ($el->attributes as $attr) {
            if (stripos($attr->nodeName, 'on') === 0) {
                $bad[] = "attributo {$attr->nodeName} su <$tag>";
            }
        }
    }
    return array_values(array_unique($bad));
}

/** Quante <img> ci sono e a cosa puntano: devono essere solo /image.php con id intero. */
function thumbAudit(string $html): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $srcs = [];
    foreach ($xp->query('//img') as $el) {
        $srcs[] = $el->getAttribute('src');
    }
    return $srcs;
}

$conn = connectDB();
// PDO su MySQL ha autocommit attivo, quindi niente snapshot REPEATABLE READ stale
// (trappola #44, che e' un problema di mysql.connector). Il lock wait basso resta.
$conn->exec("SET SESSION innodb_lock_wait_timeout = 5");

$filesBefore = (int)$conn->query('SELECT COUNT(*) FROM files')->fetchColumn();
[$sHome0] = httpGet("$base/projects.php", $jar);
echo "=== prima ===\n";
echo "  files in tabella: $filesBefore\n";
check('il sito risponde prima di iniziare', !str_starts_with((string)$sHome0, '5'),
    "HTTP $sHome0");

try {
    // ---------------------------------------------------------------
    // Sessione e CONTROLLO DI POSITIVITA' PRIMA di inserire le righe ostili.
    //
    // L'ordine e' essenziale e non e' un dettaglio. Con `filters` vuoto la ricerca ha
    // come unico WHERE `imgtype = 'LIGHT'`, quindi torna TUTTO l'archivio: la prima
    // versione di questo test inseriva le righe ostili e poi faceva il controllo su un
    // LIGHT "vero", e quel controllo restituiva anche le righe appena create. Il
    // controllo risultava quindi inquinato e segnalava come difetto che il proprio
    // payload finisse nella risposta. Il controllo va fatto su un archivio pulito.
    // ---------------------------------------------------------------
    $uid = (int)createUser($conn, $userTag, $plain, false, true, ['/']);
    [$s, $loginHtml] = httpGet("$base/login.php", $jar);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $loginHtml, $m);
    [$sLogin,] = httpPost("$base/login.php", $jar, ['username' => $userTag,
        'password' => $plain, 'csrf_token' => $m[1] ?? ''], true);
    // projects.php risponde 302 anche senza sessione: il segnale e' il form di login
    // dentro il corpo (trappola #3).
    [$sHome, $home] = httpGet("$base/projects.php", $jar);
    check('sessione stabilita', !str_contains($home, 'name="password"'),
        "login HTTP $sLogin, projects.php HTTP $sHome");

    $realId = (int)$conn->query("SELECT id FROM files WHERE imgtype = 'LIGHT'
        AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    [$sOk, $okBody] = httpPost("$base/api/find_calibration_files.php", $jar,
        ['file_id' => $realId, 'search_type' => 'lights', 'filters' => []]);
    $okJson = json_decode($okBody, true);
    $okHtml = (string)($okJson['html'] ?? '');
    check('un LIGHT vero dà 200 con JSON e html (controllo di positività)',
        $sOk === 200 && is_array($okJson) && strlen($okHtml) > 0,
        "HTTP $sOk, html " . strlen($okHtml) . ' byte');
    check('  e il suo html non contiene il payload (archivio ancora pulito)',
        !str_contains($okBody, $NEEDLE), '');
    check('  e la tabella ha le checkbox dei risultati',
        substr_count($okHtml, 'sff-file-checkbox') > 1,
        substr_count($okHtml, 'sff-file-checkbox') . ' checkbox');

    // ---------------------------------------------------------------
    // Ora le righe di prova. La prima ha la miniatura (per coprire il ramo has_thumb,
    // che scrive un <img>) e una date_obs valida. La seconda ha date_obs NULL di
    // proposito: substr(NULL, 0, 10) e' deprecato in PHP 8.1+ e lo si vuole osservare.
    //
    // `path` porta il payload quanto `name`: e' la colonna che finisce nel value= della
    // checkbox e nell'href /fits/, ed e' l'unica delle due che passa da rawurlencode
    // invece che da htmlspecialchars. Con il path pulito quei due contesti non avevano
    // nulla da controllare e i check fallivano per assenza di payload, non per difetto.
    // ---------------------------------------------------------------
    $marker = 'sffres_' . bin2hex(random_bytes(4));
    $ins = $conn->prepare(
        'INSERT INTO files (path, name, imgtype, object, `filter`, instrume, cameraid, '
        . 'exptime, ccd_temp, xbinning, ybinning, date_obs, file_hash, mtime, file_size, thumb) '
        . 'VALUES (:path, :name, :imgtype, :object, :filter, :instrume, :cameraid, '
        . ':exptime, :ccd_temp, :xb, :yb, :date_obs, :hash, :mtime, :size, :thumb)'
    );
    $rows = [
        ['path' => $marker . '/LIGHT/' . $XSS . 'a.fits', 'name' => $XSS,
         'date' => '2026-03-04 05:06:07', 'thumb' => "\x89PNG\r\n\x1a\n"],
        ['path' => $marker . '/LIGHT/' . $XSS . 'b.fits', 'name' => $XSS,
         'date' => null, 'thumb' => null],
    ];
    foreach ($rows as $r) {
        $ins->execute([
            ':path' => $r['path'], ':name' => $r['name'], ':imgtype' => 'LIGHT',
            ':object' => $r['name'], ':filter' => 'R', ':instrume' => 'PROBE',
            ':cameraid' => 'PROBE', ':exptime' => 60.0, ':ccd_temp' => -10.0,
            ':xb' => 1, ':yb' => 1, ':date_obs' => $r['date'],
            ':hash' => substr(md5($r['path']), 0, 16), ':mtime' => time(),
            ':size' => 1024, ':thumb' => $r['thumb'],
        ]);
        $ids[] = (int)$conn->lastInsertId();
    }
    $refId = $ids[0];
    echo "\n=== righe di prova inserite ===\n";
    check('due righe inserite con imgtype=LIGHT', count($ids) === 2 && min($ids) > 0,
        'id=' . implode(',', $ids));

    // ---------------------------------------------------------------
    // Il caso vero: la ricerca con la riga ostile come riferimento.
    // filters vuoto => nessun WHERE aggiuntivo, quindi tornano tutti i LIGHT, e la
    // riga di riferimento viene marcata is_reference e portata in cima.
    // ---------------------------------------------------------------
    echo "\n=== ricerca ostile ===\n";
    [$sHostile, $body] = httpPost("$base/api/find_calibration_files.php", $jar,
        ['file_id' => $refId, 'search_type' => 'lights', 'filters' => []]);
    printf("  HTTP %d, %d byte di risposta\n", $sHostile, strlen($body));
    check('la ricposta è 200', $sHostile === 200, "HTTP $sHostile");

    // ---------------------------------------------------------------
    // Regressione per il difetto di sff_results_table.php:58.
    //
    // Una delle due righe ha date_obs NULL. substr(NULL, 0, 10) e' deprecato in PHP 8.1+,
    // e la diagnostica veniva stampata DENTRO il buffer che racchiude il partial, quindi
    // finiva nel campo 'html' e il client la mostrava: l'utente leggeva
    // "Deprecated: substr(): Passing null ..." nel pannello, col percorso assoluto del
    // server e il numero di riga. Il JSON restava valido, quindi non si vedeva dal
    // fallimento della parse: serviva guardare il campo.
    //
    // Il JSON deve inoltre restare valido: se una diagnostica finisse PRIMA del JSON, il
    // client riceverebbe un SyntaxError invece di un errore actionable.
    $json = json_decode($body, true);
    check('il corpo è JSON valido', is_array($json), json_last_error_msg());
    $html = (string)($json['html'] ?? '');
    printf("  html: %d byte, count=%s\n", strlen($html), (string)($json['count'] ?? '?'));

    $diag = preg_match('#(Deprecated|Warning|Notice|Fatal error|Stack trace)#i', $body, $dm);
    check('nessuna diagnostica PHP nel corpo della risposta', !$diag,
        $diag ? '>>> ' . strip_tags(substr($dm[0], 0, 120)) : '');
    check('  e nessuna nel campo html che il client mette in innerHTML',
        !preg_match('#(Deprecated|Warning|Notice|Fatal error)#i', $html), '');
// Sul corpo grezzo questo controllo sarebbe VACUO: json_encode escapa le barre in '\/',
// quindi '/var/www/html/' non ci compare mai, nemmeno quando la diagnostica c'e'. Va
// controllato il campo html decodificato, che e' quello che il client vede davvero.
check('  e nessun percorso assoluto del server esposto',
    !str_contains($html, '/var/www/html/'), '');

    // La riga senza data_obs deve rendersi come le sue vicine, che stampano 'N/A'.
    check('la riga con date_obs NULL mostra N/A, come le celle vicine',
        substr_count($html, '>N/A<') > 1,
        substr_count($html, '>N/A<') . ' celle N/A');

    $raw = substr_count($html, $NEEDLE . '<');
    $ent = substr_count($html, $NEEDLE . '&lt;');
    $url = substr_count($html, $NEEDLE . '%3C');
    $total = substr_count($html, $NEEDLE);
    printf("  occorrenze di '%s': %d totali = %d escaped + %d percent-encoded + %d grezze\n",
        $NEEDLE, $total, $ent, $url, $raw);
    check('il payload e\' arrivato nell\'html', $total > 0, "$total occorrenze");
    check('ogni occorrenza e\' escaped o percent-encoded',
        $ent + $url === $total && $raw === 0,
        "entita={$ent} url={$url} grezze={$raw}");

    $liveTag = preg_match('#' . preg_quote($NEEDLE, '#') . '\s*<(img|svg|script)#i', $html, $m1);
    check('nessun tag vivo dopo il payload', !$liveTag, $liveTag ? '>>> ' . $m1[0] : '');

    $bad = injectedMarkup($html);
    check('nessun elemento o handler iniettato (parser)', $bad === [],
        $bad ? implode('; ', $bad) : '');

    // I due contesti specifici di questa tabella: href con rawurlencode e value= con
    // htmlspecialchars.
    check('il value= della checkbox e\' escaped',
        (bool)preg_match('#class="sff-file-checkbox" value="[^"]*' . preg_quote($NEEDLE, '#') . '&lt;#', $html), '');
    check('l\'href /fits/ e\' percent-encoded',
        (bool)preg_match('#href="/fits/[^"]*' . preg_quote($NEEDLE, '#') . '%3C#', $html), '');
    check('il nome del file e\' escaped nel testo',
        (bool)preg_match('#download>' . preg_quote($NEEDLE, '#') . '&lt;#', $html), '');

    // Il ramo has_thumb scrive <img> di proposito: non e' un'iniezione, ma il suo src
    // deve restare un id intero. La versione precedente passava il letterale '#' come
    // terzo argomento di preg_match, che vuole un riferimento: `Argument #3 ($matches)
    // could not be passed by reference`, e il check moriva invece di verificare.
    $srcs = thumbAudit($html);
    $badSrc = [];
    foreach ($srcs as $s) {
        if (!preg_match('#^/image\.php\?id=\d+&type=(thumb|crop)$#', $s)) {
            $badSrc[] = $s;
        }
    }
    check('le <img> sono solo /image.php con id intero',
        $srcs !== [] && $badSrc === [],
        $badSrc ? implode(' | ', array_slice($badSrc, 0, 3))
                : count($srcs) . ' immagini, tutte conformi');
    check('  e nessun src contiene il payload',
        !preg_match('#' . preg_quote($NEEDLE, '#') . '#', implode(' ', $srcs)), '');

    // Le due righe di prova devono essere entrambe in tabella: senza questo, «zero
    // occorrenze» potrebbe voler dire «zero righe».
    check('entrambe le righe di prova sono nella tabella',
        substr_count($html, 'sff-file-checkbox') >= 2,
        substr_count($html, 'sff-file-checkbox') . ' checkbox');

    echo "\n--- byte grezzi dei tre contesti ---\n";    if (preg_match('#class="sff-file-checkbox" value="[^"]{0,80}#', $html, $m)) {
        echo '  value=  : ' . $m[0] . "\n";
    }
    if (preg_match('#href="/fits/[^"]{0,90}#', $html, $m)) {
        echo '  href    : ' . $m[0] . "\n";
    }
    if (preg_match('#download>[^<]{0,80}#', $html, $m)) {
        echo '  nome    : ' . $m[0] . "\n";
    }

} catch (Throwable $e) {
    printf("  ECCEZIONE %s: %s\n", get_class($e), $e->getMessage());
    $failed[] = 'eccezione';
} finally {
    echo "\n=== pulizia ===\n";
    foreach ($ids as $id) {
        $conn->prepare('DELETE FROM files WHERE id = :id')->execute([':id' => $id]);
    }
    echo '  rimosse ' . count($ids) . " righe files\n";
    if ($uid !== null) {
        $conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
        $conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
        echo "  rimosso l'utente di prova\n";
    }
    @unlink($jar);

    $filesAfter = (int)$conn->query('SELECT COUNT(*) FROM files')->fetchColumn();
    check('files tornato al conteggio iniziale', $filesAfter === $filesBefore,
        $filesAfter === $filesBefore ? '' : "prima $filesBefore, ora $filesAfter");
    $left = (int)$conn->query("SELECT COUNT(*) FROM files WHERE instrume = 'PROBE'")->fetchColumn();
    check('nessun residuo con il payload', $left === 0, "residui: $left");

    [$sHome2] = httpGet("$base/projects.php", $jar);
    check('il sito risponde anche dopo', !str_starts_with((string)$sHome2, '5'),
        "HTTP $sHome2");
}

echo "\nRISULTATO: " . ($failed
    ? 'FALLITI: ' . implode(', ', $failed)
    : 'nome e percorso dell\'archivio non possono eseguire codice nei risultati SFF') . "\n";
exit($failed ? 1 : 0);
