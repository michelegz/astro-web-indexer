<?php
// Verifica 13.21 — un docblock deve descrivere la funzione che segue.
//
// In projects_functions.php il docblock di projectAddFiles() ("Manual match-or-create
// (add to project) ... Returns ['added' => int, 'skipped' => [['name' =>, 'reason' =>
// ]]]") era stato staccato dalla sua funzione da un helper inserito nel mezzo:
// descriveva projectNormPart(), che normalizza un singolo valore per la tree. Il
// risultato era che la funzione da 240 righe che crea davvero i link non aveva alcuna
// documentazione, e un helper di sei righe era descritto come il matcher.
//
// Il test cerca docblock la cui funzione successiva non è quella descritta. Serve a
// prendere il caso, non a certificare la qualità della documentazione.
//
// Uso:  docker cp tmp/docblock_owner_check.php awi-php:/tmp/
//       docker exec awi-php sh -c 'cd /tmp && php docblock_owner_check.php'

require_once '/var/www/html/includes/config.php';

$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-58s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FALLITO');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

$file = '/var/www/html/includes/projects_functions.php';
$src = (string)file_get_contents($file);
$lines = explode("\n", $src);

echo "\n=== 13.21: ogni docblock di funzione documenta la funzione che segue ===\n";

// projectAddFiles() deve avere il proprio docblock, con la forma di ritorno che
// dichiara: e' la funzione che il docblock orfano descriveva.
$addFilesAt = 0;
foreach ($lines as $i => $l) {
    if (str_starts_with(ltrim($l), 'function projectAddFiles(')) {
        $addFilesAt = $i;
        break;
    }
}
check('projectAddFiles() trovata', $addFilesAt > 0, 'riga ' . ($addFilesAt + 1));

$before = '';
// $i >= 0 e non $i -ge: "-ge" e' sintassi PowerShell, in PHP diventa la costante "ge".
for ($i = $addFilesAt - 1; $i >= max(0, $addFilesAt - 16); $i--) {
    $before = $lines[$i] . "\n" . $before;
}
check('projectAddFiles() ha un docblock suo',
    str_contains($before, '/**') && str_contains($before, 'Manual match-or-create'),
    '');
check('  che dichiara la forma di ritorno',
    str_contains($before, "'added' => int") && str_contains($before, "'skipped' =>"),
    '');
check('  senza spazi vuoti che staccherebbero il commento dalla funzione',
    !preg_match('/\*\/\s*\n\s*\n\s*function projectAddFiles/', $src),
    '');

// projectNormPart() non deve essere piu' descritto come il matcher.
$normAt = 0;
foreach ($lines as $i => $l) {
    if (str_starts_with(ltrim($l), 'function projectNormPart(')) {
        $normAt = $i;
        break;
    }
}
$normBefore = '';
for ($i = $normAt - 1; $i >= max(0, $normAt - 16); $i--) {
    $normBefore = $lines[$i] . "\n" . $normBefore;
}
check('projectNormPart() ha un docblock proprio',
    str_contains($normBefore, '/**'), '');
check('  che la descrive per quello che fa',
    str_contains($normBefore, 'Normalize a name-ish value')
    && !str_contains($normBefore, 'Manual match-or-create'),
    str_contains($normBefore, 'Manual match-or-create') ? 'ANCORA descritta come il matcher' : '');

// Le affermazioni del docblock spostato devono essere vere sul codice di projectAddFiles.
// Questo e' il punto: spostare un commento sbagliato non servirebbe a nulla.
$body = '';
$depth = 0;
$started = false;
foreach ($lines as $i => $l) {
    if ($i <= $addFilesAt) {
        continue;
    }
    $depth += substr_count($l, '{') - substr_count($l, '}');
    $started = true;
    $body .= $l . "\n";
    if ($started && $depth <= 0) {
        break;
    }
}
check('il docblock promette la rimozione dei suggerimenti superseded',
    str_contains($body, 'DELETE FROM project_suggestions'), '');
check('  e la creazione della catena setup/panel/session',
    str_contains($body, 'projectCreateSetup') && str_contains($body, 'projectCreatePanel')
    && str_contains($body, 'projectFindOrCreateSession'), '');
check('  e restituisce added/skipped con name e reason',
    (bool)preg_match("/return \['added' =>[^\]]*'skipped' =>/", $body)
    && substr_count($body, "'name' =>") >= 3,
    substr_count($body, "'name' =>") . ' occorrenze di name');

// Nessun altro docblock di funzione deve essere orfano in questo file: il controllo
// cerca un '/**' seguito da uno o due righe vuote e poi da una funzione, che e' la
// forma del difetto trovato.
echo "\n=== nessun altro docblock orfano ===\n";
$orphans = [];
for ($i = 0; $i < count($lines); $i++) {
    if (!preg_match('/^\s*\/\*\*\s*$/', $lines[$i])) {
        continue;
    }
    $j = $i + 1;
    while ($j < count($lines) && trim($lines[$j]) !== '' && !str_starts_with(trim($lines[$j]), 'function')) {
        $j++;
    }
    // il docblock deve terminare con */ e la riga dopo deve essere la funzione
    $k = $j;
    if ($k < count($lines) && preg_match('/^\s*\*\/\s*$/', $lines[$k])
        && isset($lines[$k + 1]) && preg_match('/^\s*function\s+(\w+)/', $lines[$k + 1], $m2)) {
        $fn = $m2[1];
        // le intestazioni di sezione descrivono piu' funzioni: saltale se il testo
        // nomina piu' di una o usa parole di sezione
        $block = implode("\n", array_slice($lines, $i, $k - $i + 1));
        $isHeader = str_contains($block, 'Shared ') || str_contains($block, 'building blocks')
            || str_contains($block, 'file header');
        if (!$isHeader) {
            $orphans[] = ($k + 2) . ':' . $fn;
        }
    }
}
check('nessun altro docblock staccato dalla funzione che descrive',
    $orphans === [],
    $orphans === [] ? '' : implode(', ', $orphans));

echo "\nRISULTATO: " . ($failed ? 'FALLITI: ' . implode(', ', $failed)
    : 'ogni docblock documenta la sua funzione') . "\n";