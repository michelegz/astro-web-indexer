<?php
// Verifica end-to-end — il pannello filtri che sff.js:53 mette in innerHTML non deve
// eseguire un header FITS.
//
// Il buco che questo chiude: il valore di riferimento di render_sff_filter() viene da
// files.<colonna>, cioe' dall'header FITS di un file dell'archivio. Non esiste un modo per
// renderlo controllabile dal client senza scrivere una riga in `files`, quindi fino ad ora
// la verifica era solo a harness (sff_filter_escape_check.php, che guida la funzione con
// un valore sintetico). Qui la stessa affermazione e' provata sul percorso vero:
//
//   GET /api/sff_get_filters.php?id=<id>&type=lights
//     -> SELECT * FROM files WHERE id = :id AND imgtype = 'LIGHT'
//     -> render_sff_filter($config, $referenceFile[$key])   per ogni chiave attiva
//     -> echo, con Content-Type: text/html
//     -> sff.js:53  sffFiltersPanel.innerHTML = html
//
// Il file di prova NON viene messo su disco: solo una riga in `files`, che poi viene
// cancellata per id. Nessun reindex, nessun file nell'archivio, nessun'altra riga toccata.
//
// Igiene (trappole #44, #46): una sola connessione, autocommit, lock wait basso, DELETE per
// id esatto, e controllo che il sito risponda prima e dopo.
//
// Uso:
//   docker cp tmp/sff_filter_http_escape_check.php awi-php:/tmp/
//   docker exec awi-php sh -c 'cd /tmp && php sff_filter_http_escape_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';

$base = 'http://nginx';
$failed = [];
$userTag = 'sffesc_' . bin2hex(random_bytes(3));
$plain = 'pw' . bin2hex(random_bytes(6));
$uid = null;
$rowId = null;
$jar = '/tmp/sffesc_' . bin2hex(random_bytes(4));

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-54s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FALLITO');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

/**
 * Il payload nei tre contesti che il template scrive: testo, attributo con doppie apici,
 * attributo con apici singoli.
 */
$XSS = 'XSS<img src=x onerror=alert(1)>"\'<svg onload=alert(2)>';
$NEEDLE = 'XSS';

// La colonna `filter` e' varchar(50) e sql_mode ha STRICT_TRANS_TABLES, quindi la copia
// corta DEVE stare in 50 caratteri: con una stringa piu' lunga l'INSERT muore con
// `1406 Data too long` e il test misurerebbe il vincolo del database invece
// dell'escaping. Per questo ha un marcatore proprio (Z9): senza, non si distinguerebbe
// dalle altre tre copie, che i conteggi qui sotto contano solo per $NEEDLE.
$XSS_F = 'Z9<img onerror=alert(1)>"\'<svg onload=alert(2)>';

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

function httpPost(string $url, string $jar, $payload, bool $form = false): array
{
    // login.php legge da $_POST urlencoded: con un array e CURLOPT_POSTFIELDS verrebbe
    // multipart e il login fallirebbe in silenzio (trappola #7 e #16).
    $body = $form ? http_build_query((array)$payload) : json_encode($payload);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . ($form
            ? 'application/x-www-form-urlencoded' : 'application/json')],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
    $b = (string)curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $b];
}

/**
 * Secondo parere indipendente dai regex: nel DOM parsato nessun elemento pericoloso e
 * nessun attributo on*. Qui il template scrive solo <div>, <label>, <span>, <input>, quindi
 * l'elenco di divieto basta; e unlike su file_cells, qui nessun <img> e' legittimo.
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

$conn = connectDB();
// PDO su MySQL ha autocommit attivo per default, quindi qui non si soffre dello snapshot
// REPEATABLE READ che morde mysql.connector (trappola #44): quello e' un problema del
// driver Python, non di questo. Il lock wait basso resta, pero': se questa sonda toccasse
// righe contese, il default di 50 secondi bloccherebbe anche il sito invece di far fallire
// la prova.
$conn->exec("SET SESSION innodb_lock_wait_timeout = 5");

$filesBefore = (int)$conn->query('SELECT COUNT(*) FROM files')->fetchColumn();
[$sHome0, $home0] = httpGet("$base/projects.php", $jar);
echo "=== prima ===\n";
echo "  files in tabella: $filesBefore\n";
// Una risposta che non sia 5xx: il sito e' su, e non e' bloccato dal database.
check('il sito risponde prima di iniziare', !str_starts_with((string)$sHome0, '5'),
    "HTTP $sHome0");

try {
    // ---------------------------------------------------------------
    // Riga di prova. `path` e `name` sono NOT NULL; `imgtype` deve essere esattamente
    // 'LIGHT', altrimenti la WHERE dell'endpoint non la trova e il test misurerebbe un
    // 404 invece dell'escaping (stessa lezione della trappola #31: verificare la colonna
    // e il valore reali, non desumerli).
    // ---------------------------------------------------------------
    check('il payload breve sta in filter varchar(50)', strlen($XSS_F) <= 50,
        strlen($XSS_F) . ' caratteri');
    $marker = 'sffesc_' . bin2hex(random_bytes(4));
    $st = $conn->prepare(
        'INSERT INTO files (path, name, imgtype, object, `filter`, instrume, cameraid, '
        . 'exptime, ccd_temp, xbinning, ybinning, date_obs, file_hash, mtime, file_size) '
        . 'VALUES (:path, :name, :imgtype, :object, :filter, :instrume, :cameraid, '
        . ':exptime, :ccd_temp, :xb, :yb, :date_obs, :hash, :mtime, :size)'
    );
    $st->execute([
        ':path' => $marker . '/LIGHT/probe.fits',
        ':name' => 'probe.fits',
        ':imgtype' => 'LIGHT',
        ':object' => $XSS,
        ':filter' => $XSS_F,
        ':instrume' => $XSS,
        ':cameraid' => $XSS,
        ':exptime' => 60.0,
        ':ccd_temp' => -10.0,
        ':xb' => 1,
        ':yb' => 1,
        ':date_obs' => '2026-03-04 05:06:07',
        ':hash' => str_repeat('a', 16),
        ':mtime' => time(),
        ':size' => 1024,
    ]);
    $rowId = (int)$conn->lastInsertId();
    echo "\n=== riga di prova inserita ===\n";
    check('riga inserita con imgtype=LIGHT', $rowId > 0, "id=$rowId");

    // ---------------------------------------------------------------
    // Sessione reale: login con utente di prova e token CSRF.
    // ---------------------------------------------------------------
    $uid = (int)createUser($conn, $userTag, $plain, false, true, ['/']);
    [$s, $loginHtml] = httpGet("$base/login.php", $jar);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $loginHtml, $m);
    [$sLogin,] = httpPost("$base/login.php", $jar, ['username' => $userTag,
        'password' => $plain, 'csrf_token' => $m[1] ?? ''], true);

    // Controllo di sessione (trappola #3): projects.php risponde 302 anche senza
    // sessione, quindi il segnale e' il form di login dentro il corpo, non lo status.
    [$sHome, $home] = httpGet("$base/projects.php", $jar);
    check('sessione stabilita', !str_contains($home, 'name="password"'),
        "login HTTP $sLogin, projects.php HTTP $sHome");

    // ---------------------------------------------------------------
    // Controllo di positività: lo stesso endpoint, sul PRIMO file LIGHT vero dell'archivio,
    // deve rispondere 200 con HTML non vuoto. Senza questo, «il payload non è iniettato»
    // sarebbe vero anche se l'endpoint non avesse restituito niente.
    // ---------------------------------------------------------------
    $realId = (int)$conn->query("SELECT id FROM files WHERE imgtype = 'LIGHT'
        AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    [$sOk, $okBody] = httpGet("$base/api/sff_get_filters.php?id=$realId&type=lights", $jar);
    check('un LIGHT vero dà 200 con HTML non vuoto (controllo di positività)',
        $sOk === 200 && strlen($okBody) > 0,
        "HTTP $sOk, " . strlen($okBody) . ' byte');
    check('  e il suo pannello non contiene il payload',
        !str_contains($okBody, $NEEDLE), '');

    // ---------------------------------------------------------------
    // Il caso vero.
    // ---------------------------------------------------------------
    echo "\n=== richiesta ostile ===\n";
    [$sHostile, $body] = httpGet("$base/api/sff_get_filters.php?id=$rowId&type=lights", $jar);
    printf("  HTTP %d, %d byte\n", $sHostile, strlen($body));
    check('la riga di prova dà 200', $sHostile === 200, "HTTP $sHostile");

    // Occorrenze del payload: devono essere tutte entita'. Qui non c'e' nessun href con
    // rawurlencode, quindi le forme sono due: grezza ed entita'.
    $raw = substr_count($body, $NEEDLE . '<');
    $ent = substr_count($body, $NEEDLE . '&lt;');
    $total = substr_count($body, $NEEDLE);
    printf("  occorrenze di '%s': %d totali = %d escaped + %d grezze\n",
        $NEEDLE, $total, $ent, $raw);
    check('il payload e\' arrivato nell\'HTML', $total > 0, "$total occorrenze");
    check('ogni occorrenza e\' escaped come entita\'', $ent === $total && $raw === 0,
        "entita={$ent} grezze={$raw}");

    $liveTag = preg_match('#' . preg_quote($NEEDLE, '#') . '\s*<(img|svg|script)#i', $body, $m1);
    check('nessun tag vivo dopo il payload', !$liveTag, $liveTag ? '>>> ' . $m1[0] : '');

    $bad = injectedMarkup($body);
    check('nessun elemento o handler iniettato (parser)', $bad === [],
        $bad ? implode('; ', $bad) : '');

    // Verifica posizionale dei due contesti, non solo globale (trappola #34/#50).
    check('il testo del riferimento e\' escaped (riga 37 del template)',
        str_contains($body, 'text-gray-300">' . $NEEDLE . '&lt;'), '');
    check('il value= nascosto e\' escaped (riga 38 del template)',
        str_contains($body, 'class="sff-reference-value" value="' . $NEEDLE . '&lt;'), '');
    // Il ramo slider deve essere stato renderizzato, altrimenti le due verifiche sopra
    // coprirebbero solo toggle e il test passerebbe anche senza il resto del template.
    check('il ramo slider e\' stato renderizzato',
        substr_count($body, 'sff-filter-slider') > 0,
        substr_count($body, 'sff-filter-slider') . ' slider');

    // La riga short, quella che sta in filter varchar(50): ha un marcatore proprio (Z9),
    // quindi si controlla per intero e non tramite $NEEDLE, che non la conterrebbe.
    $rawF = substr_count($body, 'Z9<');
    $entF = substr_count($body, 'Z9&lt;');
    printf("  occorrenze di 'Z9' (colonna filter): %d totali = %d escaped + %d grezze\n",
        $rawF + $entF, $entF, $rawF);
    check('il payload breve (colonna filter) e\' arrivato nell\'HTML', ($rawF + $entF) > 0,
        ($rawF + $entF) . ' occorrenze');
    check('  ed e\' escaped come entita\'', $entF === $rawF + $entF && $rawF === 0,
        "entita={$entF} grezze={$rawF}");

    echo "\n--- byte grezzi dei due contesti ---\n";
    if (preg_match('#text-gray-300">[^<]{0,90}#', $body, $m)) {
        echo '  testo     : ' . $m[0] . "\n";
    }
    if (preg_match('#class="sff-reference-value" value="[^"]{0,90}#', $body, $m)) {
        echo '  attributo : ' . $m[0] . "\n";
    }

} catch (Throwable $e) {
    printf("  ECCEZIONE %s: %s\n", get_class($e), $e->getMessage());
    $failed[] = 'eccezione';
} finally {
    echo "\n=== pulizia ===\n";
    // Per id esatto, non con un LIKE larghissimo: il WHERE dell'endpoint usa l'id, quindi
    // la stessa identita' del record che ho creato.
    if ($rowId !== null) {
        $conn->prepare('DELETE FROM files WHERE id = :id')->execute([':id' => $rowId]);
        printf("  rimossa la riga files id=%d (%d righe)\n", $rowId, $conn->query('SELECT ROW_COUNT()')->fetchColumn());
    }
    if ($uid !== null) {
        $conn->prepare('DELETE FROM user_permissions WHERE user_id = :id')->execute([':id' => $uid]);
        $conn->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $uid]);
        echo "  rimosso l'utente di prova\n";
    }
    @unlink($jar);

    $filesAfter = (int)$conn->query('SELECT COUNT(*) FROM files')->fetchColumn();
    check('files tornato al conteggio iniziale', $filesAfter === $filesBefore,
        $filesAfter === $filesBefore ? '' : "prima $filesBefore, ora $filesAfter");
    $left = (int)$conn->query("SELECT COUNT(*) FROM files WHERE object LIKE '%onerror=%'
        OR instrume LIKE '%onerror=%'")->fetchColumn();
    check('nessun residuo con il payload', $left === 0, "residui: $left");

    [$sHome2, ] = httpGet("$base/projects.php", $jar);
    check('il sito risponde anche dopo', !str_starts_with((string)$sHome2, '5'),
        "HTTP $sHome2");
}

echo "\nRISULTATO: " . ($failed
    ? 'FALLITI: ' . implode(', ', $failed)
    : 'un header FITS non può eseguire codice nel pannello filtri, su HTTP reale') . "\n";
exit($failed ? 1 : 0);
