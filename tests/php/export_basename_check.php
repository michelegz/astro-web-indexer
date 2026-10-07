<?php
// Verifica §11 — i basename dell'export devono sopravvivere a un unzip su
// Windows, che e' dove questo export viene consumato (flusso WBPP).
//
// Prima del fix i basename passavano verbatim e la mappa delle collisioni era
// case-sensitive, quindi:
//   - un nome con ":" o "*" falliva o veniva riscritto all'estrazione;
//   - "light.fits " perdeva lo spazio finale e diventava "light.fits";
//   - CON/NUL non erano estraibili;
//   - "Light.fits" e "light.fits" collidevano sul filesystem case-insensitive e
//     il secondo sovrascriveva il primo: perdita dati silenziosa.
//
// Uso:  docker cp tmp/export_basename_check.php awi-php:/var/www/html/
//       docker exec awi-php php /var/www/html/export_basename_check.php
//
// Non tocca il DB: lavora sulla funzione e su buildProjectExportMap con un
// albero finto.

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
require_once '/var/www/html/includes/projects_functions.php';
require_once '/var/www/html/includes/project_export.php';

$failed = [];
function check(string $label, bool $cond, string $detail): void
{
    printf("  %-40s %-34s %s\n", $label, $detail, $cond ? 'OK' : '<<< FALLITO');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

/** Caratteri che Windows non accetta in un nome di file. */
function windowsSafe(string $name): bool
{
    if (preg_match('/[\x00-\x1F<>:"|?*]/', $name)) {
        return false;
    }
    // Windows elimina spazi e punti finali
    if (preg_match('/[ .]$/', $name)) {
        return false;
    }
    // Nessun separatore di percorso puo' comparire nel basename
    if (str_contains($name, '/') || str_contains($name, '\\')) {
        return false;
    }
    $stem = str_contains($name, '.') ? substr($name, 0, strrpos($name, '.')) : $name;
    if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i', $stem) === 1) {
        return false;
    }
    return true;
}

echo "=== nomi pericolosi diventano estraibili ===\n";
$cases = [
    'Q99:Ha.fits',
    'light*2024.fits',
    'a?b.fits',
    "quote\".fits",
    'pipe|.fits',
    'lt<gt>.fits',
    "ctrl\x01char.fits",
    'light.fits ',      // spazio finale
    'light.',           // punto finale
    'CON.fits',
    'nul.fits',
    'COM1.fits',
    'LPT9.fits',
    'a/b.fits',
    'a\\b.fits',
    '.hidden.fits',
];
foreach ($cases as $raw) {
    $safe = exportSafeBasename($raw, 1);
    $vis = str_replace(["\x01"], ['^'], $raw);
    check('sanitizzato', windowsSafe($safe), sprintf('%s -> %s', $vis, $safe));
}

echo "\n=== i nomi leggibili restano leggibili ===\n";
$keep = [
    '2023-05-17_01-23-45_R_300.00s_0042.fits',
    'Q99 Ha (2024).fits',
    'light_flat_ha_300s.fits',
    'XYZ1234_L.fits',
];
foreach ($keep as $raw) {
    check('preservato', exportSafeBasename($raw, 1) === $raw, $raw);
}

echo "\n=== nomi vuoti o solo punteggiatura ===\n";
check('vuoto -> fallback', exportSafeBasename('', 42) === 'file_42', exportSafeBasename('', 42));
check('solo punti -> fallback', exportSafeBasename('...', 7) === 'file_7', exportSafeBasename('...', 7));
check('estensione strana', exportSafeBasename('img.a b', 1) === 'img', exportSafeBasename('img.a b', 1));

echo "\n=== collisioni case-insensitive nella stessa cartella ===\n";
// Costruisce la mappa 'used' come fa $addFile e verifica che due nomi che
// collidono su filesystem case-insensitive NON restino con lo stesso nome.
$dir = 'SETUP_S1/PANEL_P1/LIGHT';
$used = [];
$names = [];
foreach (['Light.fits', 'light.fits', 'LIGHT.FITS', 'Light_1.fits'] as $base) {
    $name = $base;
    $i = 1;
    while (isset($used[mb_strtolower($dir . "\0" . $name)])) {
        $dot = strrpos($base, '.');
        $name = $dot === false ? $base . '_' . $i : substr($base, 0, $dot) . '_' . $i . substr($base, $dot);
        $i++;
    }
    $used[mb_strtolower($dir . "\0" . $name)] = true;
    $names[] = $name;
}
echo '  nomi emessi: ' . implode(', ', $names) . "\n";
$folded = array_map('mb_strtolower', $names);
check('tutti distinti (case-folded)', count($folded) === count(array_unique($folded)),
      implode(', ', $names));

echo "\n=== cartelle diverse: lo stesso nome resta identico ===\n";
$used2 = [];
$emitted = [];
foreach ([['SETUP_S1/A/LIGHT', 'light.fits'], ['SETUP_S2/B/LIGHT', 'light.fits']] as [$d, $base]) {
    $name = $base;
    $i = 1;
    while (isset($used2[mb_strtolower($d . "\0" . $name)])) {
        $dot = strrpos($base, '.');
        $name = $dot === false ? $base . '_' . $i : substr($base, 0, $dot) . '_' . $i . substr($base, $dot);
        $i++;
    }
    $used2[mb_strtolower($d . "\0" . $name)] = true;
    $emitted[] = $d . '/' . $name;
}
echo '  emessi: ' . implode(' | ', $emitted) . "\n";
check('nessun rinominamento cross-cartella',
    $emitted[0] === 'SETUP_S1/A/LIGHT/light.fits' && $emitted[1] === 'SETUP_S2/B/LIGHT/light.fits',
    'ogni setup ha la sua copia');

echo "\n=== il file esiste davvero? (il caso perdita dati) ===\n";
// Simula l'estrazione su un filesystem case-insensitive: due path che differiscono
// solo per il case finiscono sulla stessa voce e una sovrascrive l'altra.
$extracted = [];
foreach ($names as $n) {
    $key = mb_strtolower($n);
    $extracted[$key] = ($extracted[$key] ?? 0) + 1;
}
$lost = array_filter($extracted, fn($c) => $c > 1);
check('nessun file perso nell estrazione', empty($lost),
      empty($lost) ? count($extracted) . ' voci distinte'
                   : count($lost) . ' sovrascritte');

echo "\nRISULTATO: " . ($failed ? 'FALLITI: ' . implode(', ', $failed)
    : 'tutti i controlli superati');