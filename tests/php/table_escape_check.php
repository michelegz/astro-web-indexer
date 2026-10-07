<?php
// Verifica — includes/table.php deve scrivere con htmlspecialchars i valori dell'archivio
// in ENTRAMBE le viste.
//
// table.php e' il guscio della tabella principale e contiene un secondo rendering dei
// stessi dati: oltre alla vista a elenco (che delega le celle a renderFileTableCells, gia'
// coperta da file_cells_escape_check.php) c'e' la vista a schede, che riscrive da capo
// nome, path, object, filter, exptime, imgtype e date_obs. E' un percorso distinto: una
// correzione alla vista a elenco non toccherebbe quello.
//
//   index.php -> includes/table.php   (vista elenco + vista schede, via cookie viewMode)
//
// Nessuna scrittura: le righe sono sintetiche. Il progetto in $projectList resta vuoto,
// perche' richiederebbe un inserimento: i nomi dei progetti sono coperti dal
// htmlspecialchars di riga 36, verificabile a occhio ma non provato qui.
//
// I dati sono controllati dall'archivio esattamente come in file_cells.php: name e path
// sono stringhe, il resto sono numeri.
//
// Uso:
//   docker cp tmp/table_escape_check.php awi-php:/tmp/
//   docker exec awi-php sh -c 'cd /tmp && php table_escape_check.php'

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once '/var/www/html/includes/config.php';

$lang = DEFAULT_LANGUAGE;
$strings = include '/var/www/html/languages/' . $lang . '.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/columns.php';
require_once '/var/www/html/includes/template_functions.php';
require_once '/var/www/html/includes/file_cells.php';

$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-54s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FALLITO');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

/**
 * Secondo parere indipendente dai regex, sul DOM parsato.
 *
 * Qui, a differenza degli altri test, il partial scrive di proposito cose che la lista di
 * divieto degli altri test vieta: un blocco <script> (il gestore dei duplicati) e
 * `onclick="sortTable(...)"` sulle intestazioni che ordinano (template_functions.php:55).
 * Vietarli produrrebbe due falsi positivi su codice corretto, che e' il modo peggiore di
 * far fallire un test: il segnale si perde e nessuno guarda piu' sotto.
 *
 * Quindi l'invariante non e' «nessun handler», che sarebbe falso qui, ma «nessun handler
 * che il template non scrive di suo»: si tollera `onclick` su <th> e si vieta tutto il
 * resto. E si controlla che il payload non sia finito dentro lo <script>, che e' l'unico
 * posto dove questa forma di controllo non proteggerebbe nulla: uno <script> iniettato
 * esegue anche con tutto il resto escapato.
 */
function injectedMarkup(string $html, string $needle = ''): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $bad = [];
    foreach ($xp->query('//*') as $el) {
        $tag = strtolower($el->nodeName);
        // <img> e' legittimo (le miniature), <script> e' il gestore dei duplicati.
        if (in_array($tag, ['svg', 'iframe', 'object', 'embed', 'form'], true)) {
            $bad[] = "element <$tag> non previsto dal template";
        }
        foreach ($el->attributes as $attr) {
            $an = strtolower($attr->nodeName);
            if (str_starts_with($an, 'on') && !($an === 'onclick' && $tag === 'th')) {
                $bad[] = "attributo {$attr->nodeName} su <$tag>";
            }
        }
    }
    // Solo lo <script> si controlla nel contenuto, e perche' li' il confronto ha senso:
    // il contenuto di uno <script> e' testo grezzo, il parser NON risolve le entita' dentro,
    // quindi la ricerca del payload e' informativa. Sui nodi normali non lo e': il DOM
    // restituisce i valori DECODIFICATI, e `XSS&lt;img` arriva li' come testo `XSS<img`.
    // Un controllo «il payload non compare nel testo» sarebbe quindi vero solo per codice
    // rotto, e falso su tutto il resto — l'esatto opposto di un test (trappola #50).
    // La prova che il payload sia stato portato come valore e non come markup e' gia' la
    // conta grezza/entita' fatta sopra, piu' l'assenza di elementi e handler nuovi qui.
    foreach ($xp->query('//script') as $el) {
        if ($needle !== '' && str_contains($el->textContent, $needle)) {
            $bad[] = 'il payload e\' dentro un <script>';
        }
    }
    return array_values(array_unique($bad));
}

$XSS = 'XSS<img src=x onerror=alert(1)>"\'<svg onload=alert(2)>';
$NEEDLE = 'XSS';

// Numeri, non il payload: sono i valori che il reindexer scrive come numeri, e senza cast
// (resolution, fov_w, fov_h, file_size) una stringa qui farebbe TypeError invece di
// iniettare. Lo scopo di questo test e' l'escaping delle stringhe.
$numeric = [
    'id' => 424243, 'mtime' => 1750000000, 'file_size' => 16980480,
    'width' => 9576, 'height' => 6388, 'resolution' => 2.14, 'fov_w' => 326.7, 'fov_h' => 218.0,
    'hfr' => 2.5, 'fwhm' => 3.1, 'star_count' => 1840, 'psf_signal' => 1234.5,
    'background_mean' => 512.3, 'min_pixel' => 12, 'max_pixel' => 65535,
    'mean_pixel' => 900.1, 'median_pixel' => 880.0, 'bit_depth' => 16, 'image_channels' => 3,
    'exptime' => 600.0, 'ccd_temp' => -9.8, 'xbinning' => 1, 'ybinning' => 1,
    'instrume' => 'CAM', 'cameraid' => '1', 'telescop' => 'TEL', 'ra' => 83.8, 'dec' => -5.4,
    'visible_duplicate_count' => 3, 'total_duplicate_count' => 5,
];

function hostileRow(array $numeric, string $xss): array
{
    return array_merge($numeric, [
        'name' => $xss,
        'path' => 'DIR/' . $xss . '.fits',
        'object' => $xss,
        'filter' => $xss,
        'imgtype' => $xss,
        'date_obs' => '2026-03-04 05:06:07',
        'file_hash' => $xss,
        // Le due viste usano due nomi diversi per lo stesso segnaposto: la vista schede
        // guarda $f['thumb'], quella a elenco delegata guarda $f['thumb']. Entrambe
        // coperte, perche' i due rami di table.php sono diversi.
        'thumb' => "\x89PNG\r\n\x1a\n",
        'thumb_crop' => "\x89PNG\r\n\x1a\n",
    ]);
}

// Visibilita' forzata: senza hiddenCols vuoto showColFor nasconderebbe quasi tutto e il
// test passerebbe senza aver renderizzato le celle che vuole coprire.
$hiddenCols = [];
$hiddenColsProjects = [];
$base = array_keys(getBaseColumns());
$groups = getColumnGroups();
$star = $frame = $adv = [];
foreach ($groups as $gk => $g) {
    if ($gk === 'base') {
        continue;
    }
    $bucket = $gk === 'star' ? 'star' : ($gk === 'frame' ? 'frame' : 'adv');
    foreach (array_keys($g['columns']) as $k) {
        ${$bucket}[] = $k;
    }
}
$visibleStarKeys = $star;
$visibleFrameKeys = $frame;
$visibleAdvKeys = $adv;
$visibleProjectStarKeys = $star;
$visibleProjectFrameKeys = $frame;
$visibleProjectAdvKeys = $adv;

$sortBy = 'date_obs';
$sortOrder = 'desc';

/**
 * Renderizza table.php.
 *
 * Attenzione a quello che il cookie NON fa: `viewMode` non sceglie quale dei due rami
 * venga renderizzato, cambia solo la classe `hidden` di un contenitore. Il partial stampa
 * SEMPRE elenco e schede, quindi le due esecuzioni qui sotto producono lo stesso byte per
 * byte (40111 byte in entrambe, misurato). Non sono due rendering separati: sono due
 * controlli che il cookie non peggiora nulla. E' la schede a avere un proprio sink del
 * nome (`htmlspecialchars($f['name'])` alla riga 146), distinto da quello di
 * renderFileTableCells, ed e' quello che il check posizionale seguente mira.
 */
function renderTable(string $viewMode, array $files): string
{
    $_COOKIE['viewMode'] = $viewMode;
    $_COOKIE['thumbSize'] = '3';
    $GLOBALS['files'] = $files;
    $GLOBALS['tableColspan'] = 12;
    // $conn assente: table.php fa isset($conn) ? getProjects($conn) : [], quindi la lista
    // progetti resta vuota e il test non tocca il database.
    unset($GLOBALS['conn']);
    $level = ob_get_level();
    ob_start();
    try {
        include '/var/www/html/includes/table.php';
        return (string)ob_get_clean();
    } finally {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
    }
}

$row = hostileRow($numeric, $XSS);
$files = [$row];

$baseline = null;
$baselineHtml = null;
foreach (['list' => 'viewMode=list', 'thumbnail' => 'viewMode=thumbnail'] as $mode => $desc) {
    echo "\n=== $desc ===\n";
    $html = renderTable($mode, $files);
    printf("  renderizzati %d byte\n", strlen($html));

    // Controllo di positivita': il payload deve essere arrivato.
    $total = substr_count($html, $NEEDLE);
    $raw = substr_count($html, $NEEDLE . '<');
    $ent = substr_count($html, $NEEDLE . '&lt;');
    $url = substr_count($html, $NEEDLE . '%3C');

    // Il cookie cambia l'output, ma solo le classi `hidden`: i due rami sono SEMPRE
    // stampati, quindi l'invariante non e' «stessi byte» bensi «stesse occorrenze del
    // payload, e tutte escaped». La versione precedente confrontava i byte e falliva sul
    // codice corretto, perche' `list-view hidden` e' piu' lungo di `list-view `.
    $baselineHtml ??= $html;
    if ($baseline !== null) {
        $counts = [$total, $raw, $ent, $url];
        check('  il cookie di vista non cambia quante volte il payload compare',
            $counts === $baseline,
            $total . ' occorrenze, stesse della passata precedente');
    } else {
        echo "  (prima passata: nessun confronto col cookie)\n";
    }
    $baseline = [$total, $raw, $ent, $url];
    printf("  occorrenze di '%s': %d totali = %d escaped + %d percent-encoded\n",
        $NEEDLE, $total, $ent, $url);
    check('il payload e\' arrivato nell\'HTML', $total > 0, "$total occorrenze");
    check('ogni occorrenza e\' escaped o percent-encoded',
        $ent + $url === $total && $raw === 0,
        "entita={$ent} url={$url} grezze={$raw}");

    $liveTag = preg_match('#' . preg_quote($NEEDLE, '#') . '\s*<(img|svg|script)#i', $html, $m1);
    check('nessun tag vivo dopo il payload', !$liveTag, $liveTag ? '>>> ' . $m1[0] : '');

    $bad = injectedMarkup($html, $NEEDLE);
    check('nessun elemento, handler o payload iniettato (parser)', $bad === [],
        $bad ? implode('; ', $bad) : '');
    check('  e lo <script> del partial non contiene il payload',
        !preg_match('#<script[^>]*>[^<]*' . preg_quote($NEEDLE, '#') . '#', $html), '');

    // Entrambi i rami di tabella devono aver disegnato la riga, altrimenti «zero
    // occorrenze» potrebbe voler dire «zero righe».
    check('la riga e\' stata disegnata', substr_count($html, 'selectable-item') > 0,
        substr_count($html, 'selectable-item') . ' elementi selectable-item');
    check('  e le checkbox dei file ci sono', substr_count($html, 'file-checkbox') > 0,
        substr_count($html, 'file-checkbox') . ' checkbox');
}

// I due sink di nome sono distinti e vanno verificati per posizione: quello della vista a
// schede e' proprio di table.php (riga 146), l'altro arriva da renderFileTableCells e
// coperto da file_cells_escape_check.php. Senza questi due check un test globale passerebbe
// anche se solo uno dei due fosse scoperto.
echo "\n=== i due sink di nome, per posizione ===\n";
$html = $baselineHtml;
// Vista a schede: dentro thumb-title. Il pattern aggancia `class="thumb-title"` e NON la
// parola `thumb-title>`: nell'HTML c'e' la virgoletta di chiusura dell'attributo prima
// dell'angolo, quindi `thumb-title>` non compare mai e la prima versione del check non
// trovava nulla su codice corretto.
$cardOk = (bool)preg_match('#class="thumb-title">\s*<a[^>]*>\s*'
    . preg_quote($NEEDLE, '#') . '&lt;#s', $html);
check('vista schede: il nome e\' escaped nel proprio <a>', $cardOk,
    $cardOk ? '' : 'il blocco thumb-title non contiene il nome escaped');
// Vista a elenco: dentro la cella delegata a renderFileTableCells.
$listOk = (bool)preg_match('#class="p-3"[^>]*>\s*<a href="/fits/[^"]*"[^>]*>\s*'
    . preg_quote($NEEDLE, '#') . '&lt;#s', $html);
check('vista elenco: il nome e\' escaped nelle celle delegate', $listOk, '');
// Entrambi i checkbox dei due rami, con il path escapato.
check('i checkbox di entrambi i rami hanno il path escaped',
    substr_count($html, 'value="' . 'DIR/' . $NEEDLE . '&lt;') === 2,
    substr_count($html, 'value="DIR/' . $NEEDLE . '&lt;') . ' checkbox con path escaped');
check('  e nessuno dei due href lascia il path grezzo',
    substr_count($html, 'href="/fits/' . 'DIR%2F' . $NEEDLE . '%3C') === 2,
    substr_count($html, 'href="/fits/DIR%2F' . $NEEDLE . '%3C') . ' href percent-encoded');

// La vista schede ha due <img> per riga (miniatura e ritaglio), quella a elenco altre due.
// Entrambe devono puntare a image.php con id intero e nessun src puo' contenere il payload.
echo "\n=== le <img> delle miniature ===\n";
foreach (['list', 'thumbnail'] as $mode) {
    $html = renderTable($mode, $files);
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $bad = [];
    $n = 0;
    foreach ($xp->query('//img') as $el) {
        $n++;
        $src = $el->getAttribute('src');
        if (!preg_match('#^/image\.php\?id=\d+&type=(thumb|crop)$#', $src)) {
            $bad[] = $src;
        }
    }
    check("viewMode=$mode: le <img> sono solo /image.php con id intero",
        $n > 0 && $bad === [],
        $bad ? implode(' | ', $bad) : "$n immagini conformi");
}

echo "\n--- byte grezzi, vista a schede ---\n";
$html = renderTable('thumbnail', $files);
if (preg_match('#class="file-checkbox[^"]*" value="[^"]{0,80}#', $html, $m)) {
    echo '  value= : ' . $m[0] . "\n";
}
if (preg_match('#href="/fits/[^"]{0,80}#', $html, $m)) {
    echo '  href   : ' . $m[0] . "\n";
}
if (preg_match('#thumb-title>\s*<a[^>]*>\s*[^<]{0,70}#s', $html, $m)) {
    echo '  nome   : ' . trim(strip_tags($m[0])) . "\n";
}

echo "\nRISULTATO: " . ($failed
    ? 'FALLITI: ' . implode(', ', $failed)
    : 'table.php escapa in entrambe le viste') . "\n";
exit($failed ? 1 : 0);
