<?php
// Verifica — includes/sff_filter_template.php deve scrivere il valore di riferimento
// con htmlspecialchars.
//
// L'HTML prodotto qui e' quello che api/sff_get_filters.php fa echo e che
// sff.js:53 mette in sffFiltersPanel.innerHTML:
//
//   sff.js:53  sffFiltersPanel.innerHTML = html
//     <- fetch /api/sff_get_filters.php?id=<file>&type=<searchType>
//       <- render_sff_filter($config, $referenceFile[$key])
//
// Il valore di riferimento e' files.<colonna> di un frame LIGHT, cioe' l'header FITS
// di un file dell'archivio: chi deposita un file lo controlla.
//
// Qui non serve scrivere nulla: il valore di riferimento e' sintetico, passato come
// argomento. Quindi il test e' un vero test di regressione — togliere una delle due
// htmlspecialchars e va rosso.
//
// Nota sul percorso HTTP: l'endpoint legge il valore dal database e non dalla
// richiesta, quindi non esiste modo di renderlo controllabile dal client senza
// scrivere una riga in files. tree_preview_escape_check.php copre invece il percorso
// HTTP reale di project_tree_preview.php, dove il nome arriva dalla richiesta.
//
// Uso:
//   docker cp tmp/sff_filter_escape_check.php awi-php:/tmp/
//   docker exec awi-php sh -c 'cd /tmp && php sff_filter_escape_check.php'

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once '/var/www/html/includes/config.php';

// language.php definisce __(), che il template chiama nel ramo toggle. $strings va
// inizializzato a mano perche' il file di lingua lo leggerebbe altrimenti con
// HEADER_TITLE gia' definito sopra. getBestLanguage() sta in language_functions.php
// e va caricato prima di language.php, che lo chiama subito.
$lang = DEFAULT_LANGUAGE;
$strings = include '/var/www/html/languages/' . $lang . '.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
require_once '/var/www/html/includes/sff_filter_template.php';

$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-56s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

/**
 * Second opinion independent of the regexes: a real HTML parser says whether there is an
 * element or an attribute the template did not write. In the negative check it
 * saw both the text branch and the attribute one.
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
                $bad[] = "attribute {$attr->nodeName} on <$tag>";
            }
        }
    }
    return array_values(array_unique($bad));
}

// The payload covers text, attribute with double quotes and attribute with single quotes.
$XSS = 'XSSPAYLOAD<img src=x onerror=alert(1)>"\'<svg onload=alert(2)>';
$NEEDLE = 'XSSPAYLOAD';

echo "\n=== render_sff_filter() with a hostile reference value ===\n";

// Un filtro per ogni tipo, cosi' sono coperti sia il ramo toggle (che non ha slider)
// sia i tre tipi di slider, che aggiungono data-type, value= e data-unit=.
//
// Il lato ATTACCO e' solo $referenceValue: arriva dal database, quindi e' controllato
// da chi deposita il file. I campi di configurazione vengono invece da sff_all_filters(),
// un catalogo scritto a mano: non sono dati d'archivio. Qui ricevono comunque il
// payload come difesa in profondita', e il ramo toggle porta un'unita' ostile per
// coprire anche l'etichetta testuale della riga 52.
$configs = [
    ['id' => 'object', 'label' => $XSS, 'type' => 'toggle', 'default_on' => true, 'unit' => $XSS],
    ['id' => 'filter', 'label' => 'Filter', 'type' => 'slider_percent', 'default_on' => true,
     'min' => 0, 'max' => 100, 'step' => 1, 'default_tolerance' => 5, 'unit' => '%'],
    ['id' => 'exptime', 'label' => 'Exposure', 'type' => 'slider_absolute', 'default_on' => false,
     'min' => 0, 'max' => 3600, 'step' => 1, 'default_tolerance' => 0, 'unit' => $XSS],
    ['id' => 'ra', 'label' => 'RA', 'type' => 'slider_degrees', 'default_on' => false,
     'min' => 0, 'max' => 360, 'step' => 1, 'default_tolerance' => 1, 'unit' => "\u{00b0}"],
];

$level = ob_get_level();
ob_start();
foreach ($configs as $cfg) {
    render_sff_filter($cfg, $XSS);
}
$html = (string)ob_get_clean();

file_put_contents('/tmp/sff_render_escape_check.html', $html);
printf("  rendered %d bytes over %d filters\n", strlen($html), count($configs));

// Controllo di positivita': senza payload nell'HTML, 'nessuna iniezione' sarebbe
// vero solo perche' la pagina era vuota.
$occ = substr_count($html, $NEEDLE);
$esc = substr_count($html, $NEEDLE . '&lt;');
check('the payload reached the HTML', $occ > 0, "$occ occurrences");
printf("  occurrences: total=%d  with '<' as entity=%d\n", $occ, $esc);
check('  every occurrence has \'<\' as an entity', $esc === $occ,
    $esc === $occ ? '' : ($occ - $esc) . ' grezze');

$liveTag = preg_match('#' . preg_quote($NEEDLE, '#') . '\s*<(img|svg|script)#i', $html, $m1);
check('no live tag after the payload', !$liveTag, $liveTag ? '>>> ' . $m1[0] : '');

$bad = injectedMarkup($html);
check('no injected element or handler (parser)', $bad === [],
    $bad ? implode('; ', $bad) : '');

// I due contesti vanno verificati per posizione, non solo globalmente: altrimenti
// un test che copre solo il testo passerebbe anche senza proteggere l'attributo.
check('the value is escaped in the text (line 37)',
    str_contains($html, 'text-gray-300">' . $NEEDLE . '&lt;'), '');
check('the value is escaped in value= (line 38)',
    str_contains($html, 'class="sff-reference-value" value="' . $NEEDLE . '&lt;'), '');
// L'etichetta della riga 51-52 usa $filterConfig['unit']: un doppio apice li'
// chiuderebbe data-unit e inietterebbe un handler. Il campo e' del catalogo, non
// dell'archivio, quindi e' difesa in profondita'.
check('the unit is escaped in data-unit (line 51)',
    str_contains($html, 'data-unit="' . $NEEDLE . '&lt;'), '');
check('  and the text label next to it (line 52)',
    str_contains($html, '&pm;0' . $NEEDLE . '&lt;'), '');

// Il ramo toggle non ha slider: senza questo, il test passerebbe anche se il ramo
// slider fosse sparito e con esso la copertura di value= e data-unit.
check('the slider branch was rendered',
    substr_count($html, 'sff-filter-slider') === 3,
    substr_count($html, 'sff-filter-slider') . ' sliders over ' . count($configs) . ' filters');

echo "\n--- text and attribute, raw bytes ---\n";
if (preg_match('#text-gray-300">' . preg_quote($NEEDLE, '#') . '&lt;[^<]{0,60}#', $html, $m)) {
    echo '  text      : ' . $m[0] . "\n";
}
if (preg_match('#class="sff-reference-value" value="[^"]{0,80}#', $html, $m)) {
    echo '  attribute : ' . $m[0] . "\n";
}

echo "\nRESULT: " . ($failed
    ? 'FAILED: ' . implode(', ', $failed)
    : 'sff_filter_template.php escapes text, value= and data-unit') . "\n";
exit($failed ? 1 : 0);
