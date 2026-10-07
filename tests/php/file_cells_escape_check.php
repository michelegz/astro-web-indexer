<?php
// Verifica — includes/file_cells.php deve scrivere ogni valore controllato dall'archivio
// con htmlspecialchars.
//
// Questo partial e' la superficie di XSS piu' grande del progetto e non aveva nessuna
// copertura con input ostile: disegna TUTTE le righe dell'archivio, e su questa copia ci
// sono 365 file, ciascuno con nome, object, filter, instrume, cameraid, telescop e una
// quarantina di altri header FITS. Chi deposita un file nell'archivio li controlla.
//
//   index.php -> includes/table.php:103 -> renderFileTableCells($f, 'main')
//   projects.php -> includes/igroup_files_table.php:29 -> renderFileTableCells($li, 'project', $liSuffix)
//
// I due ambiti prendono rami diversi: in 'project' ogni cella porta data-col/data-val per
// l'ordinamento lato client, il badge duplicati e' statico, e i marker (off)/(auto_off)
// arrivano come $nameSuffix. Vengono esercitati entrambi.
//
// Nessuna scrittura: la riga e' sintetica, costruita qui sotto.
//
// I tre contesti che il partial scrive, e come sono controllati:
//
//   testo           htmlspecialchars() su ogni colonna stringa
//   attributo       htmlspecialchars() in fileCellAttrs() (71), sul data-val grezzo
//   href            rawurlencode() sul path (228): codifica '%' anche, quindi il payload
//                   compare come XSS%3C e non come XSS<
//
// Sul terzo punto il test conta tre forme invece di una: grezza, entita' HTML e
// percent-encoded. Se il path finisse nell'href senza codifica, la forma grezza salirebbe
// da 0 e il test lo direbbe.
//
// Uso:
//   docker cp tmp/file_cells_escape_check.php awi-php:/tmp/
//   docker exec awi-php sh -c 'cd /tmp && php file_cells_escape_check.php'

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once '/var/www/html/includes/config.php';

// __() serve a ogni etichetta. $strings va inizializzato a mano perche' il file di
// lingua usa HEADER_TITLE, che in CLI non e' definito; language_functions.php va prima di
// language.php, che lo chiama subito.
$lang = DEFAULT_LANGUAGE;
$strings = include '/var/www/html/languages/' . $lang . '.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
require_once '/var/www/html/includes/columns.php';
require_once '/var/www/html/includes/template_functions.php';
require_once '/var/www/html/includes/file_cells.php';

$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-56s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FALLITO');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

/**
 * Secondo parere indipendente dai regex: un parser HTML reale cerca (a) un elemento
 * pericoloso che il template non scrive mai e (b) un attributo on* su QUALSIASI elemento.
 *
 * Nota su (b): qui non si puo' usare la lista dei tag consentiti come negli altri test,
 * perche' questo partial scrive di proposito <img> per le miniature, <div>, <span>, <a>,
 * <td>, <table>, <tbody>, <tr>. La lista di divieto e' quella di cio' che non deve mai
 * arrivare dal payload.
 *
 * Nota piu' importante, gia' scritta come trappola #34 nel README: cercare la
 * sottostringa `onerror=` per provare che un handler non sia iniettabile e' SBAGLIATO e
 * produce un falso positivo. Il testo `onerror=alert(1)` resta leggibile dentro un
 * attributo correttamente escapato (`data-hash="XSS&lt;img src=x onerror=alert(1)&gt;"`),
 * e una regex che lo cerca li' dice "iniettato". L'unico livello giusto e' il nome
 * dell'attributo nel DOM parsato.
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

/** Il badge duplicati, visto dal DOM: nessun handler, e data-hash con il testo escaped. */
function badgeAudit(string $html): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $handlers = [];
    $node = $xp->query('//span[contains(@class,"duplicate-badge")]')->item(0);
    if ($node === null) {
        return ['found' => false, 'handlers' => [], 'hash' => null];
    }
    foreach ($node->attributes as $attr) {
        if (stripos($attr->nodeName, 'on') === 0) {
            $handlers[] = $attr->nodeName;
        }
    }
    return ['found' => true, 'handlers' => $handlers, 'hash' => $node->getAttribute('data-hash')];
}

// Il payload copre i tre contesti: testo, attributo con doppie apici, attributo con apici
// singoli. Nessuno slash, cosi' dirname() sul path non lo mangia: il path ne ha uno
// proprio, perche' serve a far restare il payload dentro la directory.
$XSS = 'XSS<img src=x onerror=alert(1)>"\'<svg onload=alert(2)>';
$NEEDLE = 'XSS';

// Colonne che il reindexer scrive come numeri. Qui ricevono numeri, non il payload: non
// sono controllate dall'archivio, e senza cast (resolution, fov_w, fov_h, file_size) un
// valore non numerico farebbe TypeError invece di iniettare, quindi il test morirebbe
// senza dirlo. Lo scopo del test e' l'escaping delle stringhe, non la validazione dei
// numeri.
$numeric = [
    'mtime' => 1750000000, 'file_size' => 16980480, 'width' => 9576, 'height' => 6388,
    'resolution' => 2.14, 'fov_w' => 326.7, 'fov_h' => 218.0,
    'hfr' => 2.5, 'fwhm' => 3.1, 'hfr_sd' => 0.4, 'eccentricity' => 0.11,
    'star_count' => 1840, 'snr_weight' => 0.9231, 'psf_signal' => 12345.678,
    'background_mean' => 512.3, 'min_pixel' => 12, 'max_pixel' => 65535,
    'mean_pixel' => 900.1, 'median_pixel' => 880.0, 'bit_depth' => 16, 'image_channels' => 3,
    'xbinning' => 1, 'ybinning' => 1, 'egain' => 2.0, 'gain' => 1.5, 'offset' => 10,
    'xpixsz' => 3.76, 'ypixsz' => 3.76, 'set_temp' => -10.0, 'ccd_temp' => -9.8,
    'ra' => 83.822, 'dec' => -5.391, 'centalt' => 60.1, 'centaz' => 180.2, 'airmass' => 1.23,
    'objctrot' => 0.0, 'rotator_angle' => 12.5, 'siteelev' => 120.0, 'sitelat' => 0.0,
    'sitelong' => 0.0, 'equinox' => 2000.0, 'moon_angle' => 135.4, 'moon_phase' => 0.72,
    'cloudcvr' => 10.0, 'dewpoint' => 5.5, 'humidity' => 45.0, 'pressure' => 1013.2,
    'ambtemp' => 12.0, 'focallen' => 1200.0, 'focratio' => 7.0, 'focpos' => 42123,
    'focussz' => 3.5, 'foctemp' => 20.0, 'visible_duplicate_count' => 1,
    'total_duplicate_count' => 1,
];

/**
 * Riga sintetica con il payload in ogni colonna stringa che arriva dall'archivio.
 * L'elenco e' quello che la query della tabella principale seleziona da `files`: sono
 * gli header FITS piu' i metadati calcolati.
 */
function hostileRow(array $numeric, string $xss, bool $withThumb = false): array
{
    $row = [
        'id' => 424242,
        'file_id' => 424242,
        'name' => $xss,
        'path' => 'DIR/' . $xss . '.fits',
        'object' => $xss,
        'filter' => $xss,
        'imgtype' => $xss,
        'date_obs' => '2026-03-04 05:06:07',
        'date_avg' => '2026-03-04 05:07:07',
        'file_hash' => $xss,
        'instrume' => $xss,
        'cameraid' => $xss,
        'usblimit' => $xss,
        'fwheel' => $xss,
        'telescop' => $xss,
        'focname' => $xss,
        'pierside' => $xss,
        'rotator_name' => $xss,
        'readoutm' => $xss,
        'swcreate' => $xss,
        'roworder' => $xss,
        'objctra' => $xss,
        'objctdec' => $xss,
        'siteelev' => $xss,
        'sitelat' => $xss,
        'sitelong' => $xss,
        'image_color_type' => $xss,
        'bayer_pattern' => $xss,
        'thumb' => $withThumb ? "\x89PNG\r\n\x1a\n" : null,
        'thumb_crop' => $withThumb ? "\x89PNG\r\n\x1a\n" : null,
    ];
    return array_merge($row, $numeric);
}

// Visibilita' forzata: senza hiddenCols vuoto showColFor nasconderebbe quasi tutto e il
// test passerebbe senza aver renderizzato nessuna delle celle che vuole coprire.
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

function render(string $scope, array $row, string $suffix = ''): string
{
    $level = ob_get_level();
    ob_start();
    try {
        renderFileTableCells($row, $scope, $suffix);
        return (string)ob_get_clean();
    } finally {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
    }
}

/**
 * Il conteggio che rende il test onesto: ogni occorrenza del payload deve essere o
 * escaped come entita' o percent-encoded. Una terza forma significa che il payload e'
 * finito grezzo in una delle due direzioni.
 */
function tally(string $html, string $needle): array
{
    $raw = substr_count($html, $needle . '<');
    $ent = substr_count($html, $needle . '&lt;');
    $url = substr_count($html, $needle . '%3C');
    $total = substr_count($html, $needle);
    return ['raw' => $raw, 'ent' => $ent, 'url' => $url, 'total' => $total];
}

echo "\n=== ambito 'main' (home, tabella principale) ===\n";
$row = hostileRow($numeric, $XSS);
$html = render('main', $row);

printf("  renderizzati %d byte\n", strlen($html));
$t = tally($html, $NEEDLE);
printf("  occorrenze di '%s': %d totali = %d escaped + %d percent-encoded\n",
    $NEEDLE, $t['total'], $t['ent'], $t['url']);

// Controllo di positivita': senza payload renderizzato, "nessuna iniezione" sarebbe
// vero solo perche' la riga era vuota.
check('il payload e\' arrivato nell\'HTML', $t['total'] > 0,
    "{$t['total']} occorrenze");
check('ogni occorrenza e\' escaped o percent-encoded',
    $t['ent'] + $t['url'] === $t['total'],
    "entita={$t['ent']} url={$t['url']} grezze={$t['raw']}");
check('nessuna occorrenza grezza', $t['raw'] === 0, "grezze={$t['raw']}");

$liveTag = preg_match('#' . preg_quote($NEEDLE, '#') . '\s*<(img|svg|script)#i', $html, $m1);
check('nessun tag vivo dopo il payload', !$liveTag, $liveTag ? '>>> ' . $m1[0] : '');

$bad = injectedMarkup($html);
check('nessun elemento o handler iniettato (parser)', $bad === [],
    $bad ? implode('; ', $bad) : '');

// Il contesto attributo di questo ambito: il badge duplicati porta data-hash. Serve un
// valore > 1 perche' il badge venga renderizzato. Il controllo sul badge e' fatto sul DOM,
// non con una regex: vedi la nota su badgeAudit().
$htmlDup = render('main', hostileRow(array_merge($numeric, [
    'visible_duplicate_count' => 3, 'total_duplicate_count' => 5]), $XSS));
$badge = badgeAudit($htmlDup);
check('il badge duplicati c\'e\'', $badge['found'], $badge['found'] ? '' : 'non trovato');
// Sul DOM l'attributo torna DECODIFICATO: getAttribute() restituisce
// 'XSS<img src=x onerror=alert(1)>...', non la forma con &lt;. Cercare la forma escaped
// qui sarebbe sbagliato perche' e' il parser ad averla gia' risolta. L'invariante giusta
// e' che il valore torni identico a quello di partenza: prova che il payload e' stato
// portato come VALORE e non come markup, e che il confine dell'attributo e' integro
// (se l'escaping avesse mangiato un separatore, il valore sarebbe diverso).
check('  data-hash torna identico all\'originale (escaping senza perdite)',
    $badge['hash'] === $XSS,
    $badge['hash'] === $XSS ? '' : 'valore="' . $badge['hash'] . '"');
check('  e nel sorgente e\' escaped come entita\'',
    str_contains($htmlDup, 'data-hash="' . $NEEDLE . '&lt;'), '');
check('  e non ha attributi on* (controllati sul DOM, non con una regex)',
    $badge['handlers'] === [],
    $badge['handlers'] ? implode(', ', $badge['handlers']) : '');
check('questo ambito NON porta data-col (solo il project lo fa)',
    !str_contains($html, 'data-col='), '');

echo "\n=== ambito 'project' (pagina progetti, tabelle integration group) ===\n";
$suffix = ' <span class="text-gray-500">(' . __('projects_link_off') . ')</span>';
$htmlP = render('project', $row, $suffix);
printf("  renderizzati %d byte\n", strlen($htmlP));
$t = tally($htmlP, $NEEDLE);
printf("  occorrenze di '%s': %d totali = %d escaped + %d percent-encoded\n",
    $NEEDLE, $t['total'], $t['ent'], $t['url']);
check('il payload e\' arrivato nell\'HTML', $t['total'] > 0, "{$t['total']} occorrenze");
check('ogni occorrenza e\' escaped o percent-encoded',
    $t['ent'] + $t['url'] === $t['total'],
    "entita={$t['ent']} url={$t['url']} grezze={$t['raw']}");

$badP = injectedMarkup($htmlP);
check('nessun elemento o handler iniettato (parser)', $badP === [],
    $badP ? implode('; ', $badP) : '');

// Il contesto attributo proprio di questo ambito: fileCellAttrs() mette il valore grezzo
// della colonna in data-val, quindi riceve stringhe dell'archivio non solo numeri.
check('l\'ambito project porta data-col', str_contains($htmlP, 'data-col="'), '');
check('  e il data-val di una colonna testuale e\' escaped',
    (bool)preg_match('#data-val="' . preg_quote($NEEDLE, '#') . '&lt;#', $htmlP), '');
check('  e il data-col non contiene il payload',
    !preg_match('#data-col="[^"]*' . preg_quote($NEEDLE, '#') . '#', $htmlP), '');

// $nameSuffix e' l'unico punto del partial che stampa senza htmlspecialchars (riga 230).
// Oggi l'unico chiamante ci passa markup fisso attorno a una traduzione, quindi non e'
// controllato dall'archivio: va verificato, non dato per scontato.
check('$nameSuffix contiene solo il markup e la traduzione del chiamante',
    substr_count($htmlP, '<span class="text-gray-500">') === 1
    && str_contains($htmlP, '(' . __('projects_link_off') . ')'),
    substr_count($htmlP, '<span class="text-gray-500">') . ' span inseriti a mano');
check('  e nessun payload ci passa dentro',
    !preg_match('#text-gray-500">[^<]*' . preg_quote($NEEDLE, '#') . '#', $htmlP), '');

echo "\n=== il ramo delle miniature scrive <img> di proposito ===\n";
// Con thumb valorizzato il partial scrive due <img> verso image.php. Non e' un'iniezione,
// quindi non puo' finire nella lista di sopra, ma i loro src vono controllati: il
// parametro e' un id numerico e non deve poter diventare una stringa.
$htmlT = render('main', hostileRow($numeric, $XSS, true));
$badT = injectedMarkup($htmlT);
check('con le miniature non c\'e\' nulla di inatteso', $badT === [],
    $badT ? implode('; ', $badT) : '');
check('le due <img> puntano a image.php con id numerico',
    substr_count($htmlT, '/image.php?id=424242&type=') === 2,
    substr_count($htmlT, '/image.php?id=424242&type=') . ' riferimenti');
$doc = new DOMDocument();
libxml_use_internal_errors(true);
$doc->loadHTML('<!DOCTYPE html><html><body>' . $htmlT . '</body></html>', LIBXML_NOWARNING);
libxml_clear_errors();
$xp = new DOMXPath($doc);
$srcs = [];
foreach ($xp->query('//img') as $el) {
    $srcs[] = $el->getAttribute('src');
}
check('  nessuno src contiene il payload',
    !preg_match('#' . preg_quote($NEEDLE, '#') . '#', implode(' ', $srcs)), implode(' | ', $srcs));

echo "\n--- byte grezzi dei tre contesti ---\n";
// data-val della colonna *testuale* che contiene il payload: la prima data-val e' quella
// di 'preview', vuota, quindi va cercata per contenuto e non per posizione.
if (preg_match('#data-val="[^"]*' . preg_quote($NEEDLE, '#') . '&lt;[^"]{0,40}#', $htmlP, $m)) {
    echo '  attributo  : ' . $m[0] . "\n";
}
if (preg_match('#href="/fits/[^"]{0,90}#', $html, $m)) {
    echo '  href       : ' . $m[0] . "\n";
}
// Il testo del nome sta DOPO il `>` che chiude il tag <a>, e quel tag ha altri attributi
// dopo l'href (`download class="..."`), quindi serve `[^>]*` per arrivare al confine.
if (preg_match('#<a href="/fits/[^"]*"[^>]*>([^<]*)</a>#', $html, $m)) {
    echo '  testo name : ' . trim($m[1]) . "\n";
}
if (preg_match('#data-hash="[^"]{0,80}#', $htmlDup, $m)) {
    echo '  data-hash  : ' . $m[0] . "\n";
}

echo "\nRISULTATO: " . ($failed
    ? 'FALLITI: ' . implode(', ', $failed)
    : 'file_cells.php escapa testo, attributi e href in entrambi gli ambiti') . "\n";
exit($failed ? 1 : 0);
