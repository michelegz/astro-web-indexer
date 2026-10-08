<?php
// Verifica — includes/projects_tree.php deve scrivere ogni valore controllato dal
// database come testo o attributo con htmlspecialchars.
//
// Il partial produce l'HTML che finisce in due sink:
//
//   api/project_tree_preview.php  -> JSON 'html' -> main.js:646 projectTreePreview.innerHTML
//   projects.php:803              -> direttamente nel documento
//
// I valori che lo attraversano vengono dal database: getProjectTree() seleziona f.* su
// ogni riga di collegamento, quindi files.name (nome del file FITS) e i label di
// progetto_sessions / project_panels / project_setups. Chi puo' depositare un FITS
// nell'archivio controlla quella stringa.
//
// Qui non serve scrivere nulla: l'albero e' sintetico, costruito qui sotto. Quindi
// questo test puo' girare in qualsiasi momento e resta un test di regressione vero e
// proprio: basta togliere una delle htmlspecialchars e va rosso.
//
// Eseguito in TRE modalita' (hypo 1, albero 0, review 2), in tre processi separati,
// perche' i rami prendono percorsi diversi e il partial dichiara funzioni a livello
// di file: includerlo due volte nello stesso processo e' un errore fatale.
//
//   hypo=1  quello di project_tree_preview.php: niente checkbox, niente pulsante di
//           rinomina, gli setup sono gia' aperti
//   hypo=0  quello di projects.php: compare data-setup-name="" (riga 250), il
//           contesto attributo, che hypoMode non raggiunge mai
//   rev =2  quello del modale suggerimenti (sugBulkForm): niente checkbox link_keys[],
//           niente rename, checkbox suggestion_ids[] a intero sui soli pending con
//           reason nel tooltip escapato, master di gruppo senza name, aperti solo
//           i rami con pending
//
// Uso:
//   docker cp tmp/tree_render_escape_check.php awi-php:/tmp/
//   docker exec awi-php sh -c 'cd /tmp && php tree_render_escape_check.php 1 && php tree_render_escape_check.php 0 && php tree_render_escape_check.php 2'

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/projects_functions.php';
require_once '/var/www/html/includes/projects_diagnostics.php';

// language.php definisce __(), che il partial chiama a ogni riga. $strings va
// inizializzato a mano perche' il file di lingua lo leggerebbe altrimenti con
// HEADER_TITLE gia' definito sopra. getBestLanguage() sta in language_functions.php
// e va caricato prima di language.php, che lo chiama subito.
$lang = DEFAULT_LANGUAGE;
$strings = include '/var/www/html/languages/' . $lang . '.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';

$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-56s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FALLITO');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

/**
 * Secondo parere indipendente dai regex: si chiede a un parser HTML reale se esiste
 * un elemento o un attributo che il template non ha mai scritto. Nel controllo
 * negativo questo e' stato il controllo che ha beccato la rottura del contesto
 * attributo, dove la mia espressione regolare guardava il carattere sbagliato.
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

// Il payload copre i tre contesti in cui il partial scrive: testo, attributo con
// doppie apici, e attributo con apici singoli. parseProjectAddRequest tronca a 64
// caratteri solo il nome del custom setup, quindi qui la lunghezza non e' un limite.
$XSS = 'XSSPAYLOAD<img src=x onerror=alert(1)>"\'<svg onload=alert(2)>';
$NEEDLE = 'XSSPAYLOAD';

$arg = (string)($argv[1] ?? '1');
$review = $arg === '2';

$cal = static function (int $fid) use ($XSS, $review) {
    return [
        'file_id' => $fid, 'name' => $XSS, 'imgtype' => $XSS,
        'enabled' => 1, 'pending' => $review ? 1 : 0,
        'suggestion_id' => $fid + 1000, 'reason' => $XSS,
        'level' => 'setup', 'node_id' => 1,
        'scope_sessions' => [], 'scope_nights' => [$XSS],
        'exptime' => $XSS, 'ccd_temp' => $XSS,
    ];
};

$light = static function (int $fid) use ($XSS, $review) {
    return [
        'file_id' => $fid, 'name' => $XSS, 'exptime' => $XSS, 'ccd_temp' => 1.0,
        'enabled' => 1, 'pending' => $review ? 1 : 0, 'auto_off' => 0,
        'suggestion_id' => $fid + 1000, 'reason' => $XSS,
        'filter' => $XSS,
    ];
};

$projectTree = [
    'setups' => [[
        'id' => 1, 'setup_no' => 1, 'label' => $XSS, 'fingerprint' => $XSS,
        'calibrations' => [$cal(101)],
        'panels' => [[
            'id' => 10, 'panel_no' => 1, 'ra' => 1.0, 'dec' => 2.0, 'label_object' => $XSS,
            'calibrations' => [$cal(102)],
            'sessions' => [[
                'id' => 100, 'session_no' => 3, 'astro_night' => $XSS,
                'calibrations' => [$cal(103)],
                'filters' => [[
                    'name' => $XSS,
                    'calibrations' => [$cal(104)],
                    'lights' => [$light(201)],
                ]],
            ]],
        ]],
    ]],
    'project_links' => [],
];

$projectDiag = diagnoseProjectTree($projectTree, [], []);
$dupLinks = indexDuplicateLinks($projectTree);
$darkRoles = indexDarkRoles($projectTree, []);

$mode = !$review && (!isset($argv[1]) || (string)$argv[1] === '1');
$desc = $review ? 'suggestReviewMode=true (projects.php sugModal)'
    : ($mode ? 'hypoMode=true  (project_tree_preview.php)' : 'hypoMode=false (projects.php)');

echo "\n=== $desc ===\n";

$calCtxBase = [
    'dup' => $dupLinks,
    'filterAliases' => [],
    'darkRoles' => $darkRoles,
    'flatCov' => diagnoseFlatCoverage($projectTree, [], $darkRoles),
    'reviewMode' => $review,
    // Solo in hypoMode il partial legge questo, per il badge verde.
    'hypoLinks' => $mode ? ['101:setup:1' => true, '102:panel:10' => true,
                            '103:session:100' => true, '104:filter:100' => true,
                            '201:filter:100' => true] : [],
    'hypoMode' => $mode,
    'tols' => ['exp' => '1%', 'temp' => '2C'],
];
$hypoMode = $mode;
$hypoOpen = ['setups' => [], 'panels' => [], 'sessions' => []];
$suggestReviewMode = $review;
$suggestOpen = ['setups' => [1 => true], 'panels' => [10 => true], 'sessions' => [100 => true]];

$level = ob_get_level();
ob_start();
try {
    include '/var/www/html/includes/projects_tree.php';
    $html = (string)ob_get_clean();
} finally {
    while (ob_get_level() > $level) {
        ob_end_clean();
    }
}

    printf("  renderizzati %d byte\n", strlen($html));
if (!$mode) {
    // Salvato solo per mostrare i byte grezzi dei due contesti in coda all'output.
    file_put_contents('/tmp/tree_render_last.html', $html);
}

// Controllo di positivita'. Se il payload non arrivasse nell'HTML, 'nessuna
// iniezione' sarebbe vero solo perche' la pagina era vuota.
$occ = substr_count($html, $NEEDLE);
$esc = substr_count($html, $NEEDLE . '&lt;');
check('il payload e\' arrivato nell\'HTML', $occ > 0, "$occ occorrenze");
printf("  occorrenze: totali=%d  con '<' come entita=%d\n", $occ, $esc);
check('  ogni occorrenza ha \'<\' come entita', $esc === $occ,
    $esc === $occ ? '' : ($occ - $esc) . ' grezze');

$liveTag = preg_match('#' . preg_quote($NEEDLE, '#') . '\s*<(img|svg|script)#i', $html, $m1);
check('nessun tag vivo dopo il payload', !$liveTag, $liveTag ? '>>> ' . $m1[0] : '');

$bad = injectedMarkup($html);
check('nessun elemento o handler iniettato (parser)', $bad === [],
    $bad ? implode('; ', $bad) : '');

// Il contesto attributo esiste solo nel ramo non-hypo. La sua assenza in hypoMode
// e' attesa, e va affermata: altrimenti il test passerebbe in silenzio su un
// contesto che non ha visitato.
$hasAttr = str_contains($html, 'data-setup-name=');
if ($mode === false && !$review) {
    check('il ramo non-hypo renderizza data-setup-name', $hasAttr, '');
    check('  e il suo valore resta chiuso',
        $hasAttr && str_contains($html, 'data-setup-name="' . $NEEDLE . '&lt;'), '');
} else {
    check('i rami hypoMode/review non renderizzano data-setup-name', !$hasAttr,
        $hasAttr ? 'il contesto attributo sarebbe stato visitato due volte' : '');
}

if ($review) {
    // La review mostra suggestion_ids[] (interi dal DB) e il reason testuale:
    // il primo non puo' uscire dal value, il secondo va escapato.
    preg_match_all('/name="suggestion_ids\[\]" value="([^"]*)"/', $html, $mSug);
    $sugVals = $mSug[1];
    $allInt = $sugVals !== [];
    foreach ($sugVals as $v) {
        if (!ctype_digit($v)) {
            $allInt = false;
            break;
        }
    }
    check('la review ha checkbox sui pending (suggestion_ids[])', $sugVals !== [],
        count($sugVals) . ' checkbox');
    check('  i value sono interi puri', $allInt, $allInt ? '' : implode(',', $sugVals));
    check('la review non ha checkbox link_keys[]', !str_contains($html, 'name="link_keys[]"'), '');
    // La review ha le master di gruppo come l'albero vero (selezionano i soli
    // pending), ma senza name: non vengono mai inviate, conta solo sug-check.
    check('la review ha le master di gruppo', str_contains($html, 'pgroup-check')
        && str_contains($html, 'cgroup-check'), '');
    preg_match_all('/<input[^>]*class="[^"]*(?:pgroup-check|cgroup-check)[^"]*"[^>]*>/', $html, $mGrp);
    $grpNamed = array_filter($mGrp[0], fn($tag) => str_contains($tag, 'name='));
    check('  e non hanno name (non inviate)', $mGrp[0] !== [] && $grpNamed === [], count($mGrp[0]) . ' master');
}

// Byte grezzi dei due contesti, cosi' il risultato si giudica a occhio e non dal solo
// esito del check. Solo nel ramo non-hypo, che e' quello che contiene entrambi.
if (!$mode && !$review) {
    echo "\n--- testo (riga 248) e attributo (riga 250), byte grezzi ---\n";
    if (preg_match('#S\d+:\s*' . preg_quote($NEEDLE, '#') . '&lt;[^<]{0,60}#', $html, $m)) {
        echo '  testo     : ' . $m[0] . "\n";
    }
    if (preg_match('#data-setup-name="[^"]{0,80}#', $html, $m)) {
        echo '  attributo : ' . $m[0] . "\n";
    }
}

echo "\nRISULTATO: " . ($failed
    ? 'FALLITI: ' . implode(', ', $failed)
    : 'projects_tree.php escapa in tutti i contesti, modalita ' . ($review ? 'review' : ($mode ? 'hypo' : 'non-hypo')))
    . "\n";
exit($failed ? 1 : 0);
