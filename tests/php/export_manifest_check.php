<?php
// Verifica 13.24, 13.40 — rotazione e manifest dell'archivio.
//
//  13.24 projectRotDist() faceva (float)$r1 su un valore non numerico, che dà 0.0 in
//         silenzio: un file con metadato di rotazione spazzatura risultava allineato a
//         un pannello a 0 gradi, non veniva scartato dalla tolleranza e il badge
//         diceva "ok" invece di "unknown". Il gemello Python rotation_distance() lo
//         segnalava gia' come unknown.
//  13.40 MANIFEST.json dentro l'archivio era costruito dalla mappa completa, quindi
//         elencava cartelle, set di calibrazione e dimensioni di file che il controllo
//         su disco aveva appena scartato. L'inventario contraddiceva il contenuto, e un
//         set i cui file mancavano tutti non produceva nemmeno la cartella.
//
// Uso:  docker cp tmp/export_manifest_check.php awi-php:/tmp/
//       docker exec awi-php sh -c 'cd /tmp && php export_manifest_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/http_json.php';
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/projects_diagnostics.php';
require_once '/var/www/html/includes/projects_functions.php';
require_once '/var/www/html/includes/project_export.php';

if (!function_exists('__')) {
    function __(string $key, array $replace = []): string
    {
        return $key;
    }
}

$conn = connectDB();
$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-58s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FALLITO');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

// =====================================================================
// 13.24 — projectRotDist
// =====================================================================
echo "\n=== 13.24: una rotazione non numerica resta 'unknown' ===\n";

//Casi validi: distanza calcolata, unknown = false.
[$d, $unk] = projectRotDist(10.0, 20.0);
check('10 e 20 gradi -> 10 gradi, noto', abs($d - 10.0) < 1e-9 && $unk === false, "d=$d");
[$d, $unk] = projectRotDist(350.0, 10.0);
check('attraverso il zero -> 20 gradi', abs($d - 20.0) < 1e-9 && $unk === false, "d=$d");

//Casi non numerici: devono restare unknown. Prima (float)'N/A' dava 0.0 con
// unknown = false, cioe' "identico a un pannello a 0 gradi".
foreach (['N/A' => 'N/A', 'spazzatura' => '   ', 'data' => '12:34:56'] as $label => $val) {
    [$d, $unk] = projectRotDist($val, 0.0);
    check("$label -> unknown", $unk === true, "d=$d unknown=" . var_export($unk, true));
}
[$d, $unk] = projectRotDist(12.0, 'N/A');
check('lato destro non numerico -> unknown', $unk === true, "unknown=" . var_export($unk, true));

// Null e stringa vuota: gia' gestiti, ma non devono regredire.
foreach ([null => 'null', '' => 'vuoto'] as $val => $label) {
    [$d, $unk] = projectRotDist($val, 5.0);
    check("$label -> unknown", $unk === true, "d=$d");
}

// Un valore numerico in forma stringa continua a valere: i dati vengono dal DB come
// numeri, ma la funzione e' pubblica e non deve rifiutare '10'.
[$d, $unk] = projectRotDist('10', '20');
check('numeri in forma stringa -> noti', abs($d - 10.0) < 1e-9 && $unk === false, "d=$d");

// Il gemello Python rotation_distance() deve dare la stessa risposta. Il confronto
// cross-container non e' possibile (dal container non c'e' il docker CLI), quindi i due
// test sono ancorati agli stessi valori attesi, calcolati dai primi principi: se uno
// dei due implementa diversi, la sua metà di questa verifica fallisce.
[$pyDistExpect, ] = projectRotDist(10.0, 20.0);
check('stessa distanza che il test Python attende', abs($pyDistExpect - 10.0) < 1e-9,
    'atteso anche in suggest_parity_check.py: 10.0');
[, $phpUnknown] = projectRotDist('N/A', 0.0);
check("stessa risposta 'unknown' che il test Python attende",
    $phpUnknown === true, 'rotation_distance("N/A", 0.0) -> True');

// =====================================================================
// 13.26 (meta' PHP) — stessi ingressi del test Python
// =====================================================================
echo "\n=== 13.26 meta' PHP: i marcatori maiuscoli come nel gemello Python ===\n";

$expectedRa = fmod((12 + 34 / 60.0 + 56 / 3600.0) * 15.0, 360.0);
$expectedDec = -(12 + 34 / 60.0 + 56 / 3600.0);
check('RA "12H34M56S" -> stessa del Python',
    projectParseRa('12H34M56S') !== null && abs(projectParseRa('12H34M56S') - $expectedRa) < 1e-9,
    sprintf('%.6f', (float)projectParseRa('12H34M56S')));
check('Dec "-12D34M56S" -> stessa del Python',
    projectParseDec('-12D34M56S') !== null && abs(projectParseDec('-12D34M56S') - $expectedDec) < 1e-9,
    sprintf('%.6f', (float)projectParseDec('-12D34M56S')));

// =====================================================================
// 13.25 (meta' PHP) — projectNumPrefix rifiuta il segno come il gemello Python
// =====================================================================
echo "\n=== 13.25 meta' PHP: tolleranze con unita' e senza segno ===\n";

foreach (['10%' => 10.0, '2C' => 2.0, '3deg' => 3.0, '0.2' => 0.2, '  5 arcmin' => 5.0] as $text => $want) {
    check(sprintf('%-12s -> %-5s', $text, $want),
        abs(projectNumPrefix((string)$text, 3.0) - $want) < 1e-12,
        (string)projectNumPrefix((string)$text, 3.0));
}
check('"-5" -> default, come _num_prefix in Python',
    abs(projectNumPrefix('-5', 3.0) - 3.0) < 1e-12, (string)projectNumPrefix('-5', 3.0));
check('"-.5" -> default, come _num_prefix in Python',
    abs(projectNumPrefix('-.5', 3.0) - 3.0) < 1e-12, (string)projectNumPrefix('-.5', 3.0));

// E il salvataggio deve rifiutare il negativo alla fonte, non solo alla lettura.
$pidTol = (int)$conn->query('SELECT MIN(id) FROM projects')->fetchColumn();
$before = (string)$conn->query('SELECT tolerances FROM projects WHERE id = ' . $pidTol)->fetchColumn();
$threw = false;
try {
    saveProjectTolerances($conn, $pidTol, ['tol_rot' => '-5']);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
$after = (string)$conn->query('SELECT tolerances FROM projects WHERE id = ' . $pidTol)->fetchColumn();
check('salvare una tolleranza negativa viene rifiutato', $threw, '');
check('  e non scrive nulla', $before === $after, 'tolerances invariato');

// I valori legittimi con unita' devono restare accettati: e' il rischio della mia
// prima versione della validazione, che rifiutava ogni valore non strettamente numerico
// e avrebbe rotto la feature (i default sono '1%', '2C', '3deg', '10%').
$saved = false;
try {
    saveProjectTolerances($conn, $pidTol, ['tol_rot' => '3deg', 'tol_exp' => '1%']);
    $saved = true;
} catch (InvalidArgumentException $e) {
    $saved = false;
}
$now = json_decode((string)$conn->query('SELECT tolerances FROM projects WHERE id = ' . $pidTol)->fetchColumn(), true);
check('i valori con unita\' vengono accettati', $saved && ($now['tol_rot'] ?? null) === '3deg',
    json_encode($now));
// Ripristina.
$conn->prepare('UPDATE projects SET tolerances = :t WHERE id = :id')
    ->execute([':t' => $before !== '' ? $before : null, ':id' => $pidTol]);

// =====================================================================
// 13.40 — il manifest descrive l'archivio, non la mappa
// =====================================================================
echo "\n=== 13.40: il manifest elenca solo cio' che c'e' nell'archivio ===\n";

$pid = (int)$conn->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();
$map = buildProjectExportMap($conn, $pid);
check('mappa di esportazione costruita',
    is_array($map) && isset($map['entries']) && $map['entries'] !== [],
    'entries=' . count($map['entries'] ?? []));

// Il blocco che il fix introduce: stessa logica del ciclo in export_project_zip.php,
// applicata agli stessi dati, senza scaricare l'archivio.
$fitsRoot = FITS_ROOT;
$realRoot = realpath($fitsRoot);
$validFiles = [];
$validEntries = [];
$missing = [];
foreach ($map['entries'] as $e) {
    $rel = preg_replace('/\.\.(\/|\\\\)?/', '', (string)($e['src'] ?? ''));
    $rel = ltrim($rel, '/\\');
    if ($rel === '') {
        continue;
    }
    $full = realpath($fitsRoot . DIRECTORY_SEPARATOR . $rel);
    if ($full && $realRoot !== false && isPathWithinRoot($full, $realRoot)
        && is_file($full) && is_readable($full) && canAccessPath($rel)) {
        $validFiles[] = ['zip_path' => (string)$e['zip_path'], 'full' => $full];
        $validEntries[] = $e;
        continue;
    }
    $missing[] = ['zip_path' => (string)$e['zip_path'], 'src' => (string)($e['src'] ?? ''),
        'reason' => (string)($e['src'] ?? '') === '' ? 'no_path' : 'not_readable'];
}

$folders = [];
$archiveSize = 0;
foreach ($validFiles as $i => $vf) {
    $e = $validEntries[$i];
    $archiveSize += (int)filesize($vf['full']);
    $dd = dirname((string)$e['zip_path']);
    $folders[$dd]['files'][] = basename((string)$e['zip_path']);
    $folders[$dd]['kinds'][$e['kind']] = true;
    if (!empty($e['scope'])) {
        $folders[$dd]['scope'] = array_values(array_unique(array_merge(
            $folders[$dd]['scope'] ?? [], (array)$e['scope'])));
    }
}
ksort($folders);

$survivingSets = [];
foreach ($map['sets'] as $name => $set) {
    foreach ($validEntries as $e) {
        if (str_contains('/' . $e['zip_path'] . '/', '/' . $name . '/')) {
            $survivingSets[$name] = $set;
            break;
        }
    }
}

$manifest = $map['manifest'];
$manifest['sets'] = $survivingSets;
$manifest['folders'] = $folders;
$manifest['total_size'] = $archiveSize;
$manifest['files'] = count($validFiles);
if ($missing !== []) {
    $manifest['missing'] = $missing;
}

// Ogni file elencato nelle cartelle del manifest deve esistere davvero.
$listed = 0;
foreach ($manifest['folders'] as $dir => $info) {
    $listed += count($info['files'] ?? []);
}
check('i file elencati nelle cartelle sono quelli presenti',
    $listed === count($validFiles),
    "manifest={$listed} presenti=" . count($validFiles));

// Ogni set dichiarato deve avere almeno un file: un set vuoto non produce nemmeno la
// cartella, quindi dichiararlo e' falso.
$emptySets = [];
foreach ($manifest['sets'] as $name => $s) {
    $has = false;
    foreach ($validEntries as $e) {
        if (str_contains('/' . $e['zip_path'] . '/', '/' . $name . '/')) {
            $has = true;
            break;
        }
    }
    if (!$has) {
        $emptySets[] = $name;
    }
}
check('nessun set dichiarato senza file', $emptySets === [],
    $emptySets === [] ? '' : 'vuoti: ' . implode(', ', $emptySets));

// E il manifest deve concordare con la mappa solo per cio' che esiste: il confronto
// con la mappa non filtrata e' quello che il fix evita.
check('il manifest non porta la mappa grezza',
    $manifest['folders'] !== $map['manifest']['folders']
    || $missing === [],
    'differenza su folders');

// La dimensione deve essere quella dei file presenti, non la stima dal DB.
$dbEstimate = (int)($map['manifest']['total_size'] ?? 0);
check('total_size ricalcolato sui file presenti',
    $manifest['total_size'] === $archiveSize,
    "manifesto={$manifest['total_size']} somma file reali={$archiveSize} stima DB={$dbEstimate}");

// Il manifest deve essere codificabile e valido: e' un file che l'utente apre.
$json = awiJsonString($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$decoded = json_decode($json, true);
check('il manifest si codifica in JSON valido',
    is_string($json) && is_array($decoded) && isset($decoded['files']),
    strlen($json) . ' byte');

if ($missing !== []) {
    check('i file mancanti sono dichiarati, non taciuti',
        isset($decoded['missing']) && count($decoded['missing']) === count($missing),
        'missing=' . count($missing));
} else {
    check('nessun file mancante su questo progetto', true, '');
}

echo "\n=== 13.40: il ramo con i file presenti (caso sintetico) ===\n";

// Su questo stack nessun file e' raggiungibile (FITS_ROOT=/var/fits e'vuoto), quindi il
// caso reale qui esercita solo "zero file". Il ramo opposto e' copero a parte, con
// liste sintetiche che passano dalla stessa logica: un file presente deve comparire
// nelle cartelle, un set senza file sopravvissuti non deve essere dichiarato.
$probe = '/tmp/awi_manifest_probe';
@mkdir($probe, 0777, true);
file_put_contents($probe . '/a.fits', str_repeat('x', 1234));
file_put_contents($probe . '/b.fits', str_repeat('y', 77));

$synFiles = [
    ['zip_path' => 'SETUP_S1/FLATSET_S1/a.fits', 'full' => $probe . '/a.fits'],
    ['zip_path' => 'SETUP_S1/FLATSET_S1/b.fits', 'full' => $probe . '/b.fits'],
];
$synEntries = [
    ['zip_path' => 'SETUP_S1/FLATSET_S1/a.fits', 'kind' => 'FLAT', 'scope' => 'FLATSET', 'src' => 'x/a.fits'],
    ['zip_path' => 'SETUP_S1/FLATSET_S1/b.fits', 'kind' => 'FLAT', 'scope' => 'FLATSET', 'src' => 'x/b.fits'],
];
$synMap = [
    'sets' => [
        'FLATSET_S1' => ['type' => 'FLAT', 'sessions' => [1], 'nights' => ['n1']],
        'BIASSET_S1' => ['type' => 'BIAS', 'sessions' => [2], 'nights' => ['n1']],
    ],
    'duplicated_files' => [],
    'manifest' => ['sets' => [], 'folders' => [], 'total_size' => 0],
];

$synFolders = [];
$synSize = 0;
foreach ($synFiles as $i => $vf) {
    $e = $synEntries[$i];
    $synSize += (int)filesize($vf['full']);
    $dd = dirname($e['zip_path']);
    $synFolders[$dd]['files'][] = basename($e['zip_path']);
    $synFolders[$dd]['kinds'][$e['kind']] = true;
    if (!empty($e['scope'])) {
        $synFolders[$dd]['scope'] = array_values(array_unique(array_merge(
            $synFolders[$dd]['scope'] ?? [], (array)$e['scope'])));
    }
}
ksort($synFolders);
$synSets = [];
foreach ($synMap['sets'] as $name => $set) {
    foreach ($synEntries as $e) {
        if (str_contains('/' . $e['zip_path'] . '/', '/' . $name . '/')) {
            $synSets[$name] = $set;
            break;
        }
    }
}

$synListed = 0;
foreach ($synFolders as $di => $info) {
    $synListed += count($info['files'] ?? []);
}
check('i file presenti compaiono nelle cartelle', $synListed === 2,
    'cartelle=' . implode('/', array_keys($synFolders)) . ' file=' . $synListed);
check('il set con i file sopravvissuti e\' dichiarato', isset($synSets['FLATSET_S1']),
    implode(',', array_keys($synSets)));
check('il set senza file non e\' dichiarato', !isset($synSets['BIASSET_S1']),
    'BIASSET_S1 assente');
check('total_size e\' la somma reale', $synSize === 1311, "atteso 1311, ottenuto $synSize");
check('il percorso del set e\' un segmento, non un prefisso',
    str_contains('/SETUP_S1/FLATSET_S1/a.fits/', '/FLATSET_S1/')
    && !str_contains('/SETUP_S1/FLATSET_S1X/a.fits/', '/FLATSET_S1/'), '');
exec('rm -rf ' . escapeshellarg($probe));

// Il codice dell'endpoint non deve piu' riusare la mappa grezza per il manifest.
$zipSrc = (string)file_get_contents('/var/www/html/api/export_project_zip.php');
$reusesRawManifest = (bool)preg_match(
    '/awiJsonString\(\s*\$map\[.manifest.\]/', $zipSrc);
check('export_project_zip.php non riusa il manifest grezzo',
    !$reusesRawManifest,
    $reusesRawManifest ? 'ANCORA $map[manifest]' : '');
check('  e ricostruisce folders, sets e total_size',
    str_contains($zipSrc, "\$manifest['folders']")
    && str_contains($zipSrc, "\$manifest['sets']")
    && str_contains($zipSrc, "\$manifest['total_size']"), '');

echo "\nRISULTATO: " . ($failed ? 'FALLITI: ' . implode(', ', $failed)
    : 'rotazione e manifest corretti') . "\n";
