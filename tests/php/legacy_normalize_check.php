<?php
// Verifica 13.6 e 13.7 — la normalizzazione dei dati legacy ripara davvero
// righe e panel scritti prima delle regole.
//
// Il dataset di sviluppo ha gia' tutto canonico (la normalizzazione entra con
// questo branch), quindi qui si piantano valori legacy, si esegue la SQL che la
// migration 20261021120000 applichera', e si verifica che diventino canonici.
// I valori originali vengono ripristinati alla fine.
//
// Uso:  docker cp tmp/legacy_normalize_check.php awi-php:/tmp/
//       docker exec awi-php sh -c 'cd /tmp && php legacy_normalize_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';

$conn = connectDB();
$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-44s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FALLITO');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

// La stessa espressione della migration, eseguita qui per non dipendere da Phinx.
function normalizeImgtypeSql(PDO $conn, string $raw): string
{
    $st = $conn->prepare("SELECT CASE "
        . "WHEN t = '' THEN 'UNKNOWN' "
        . "WHEN t LIKE '%DARK%' THEN 'DARK' "
        . "WHEN t LIKE '%FLAT%' THEN 'FLAT' "
        . "WHEN t LIKE '%BIAS%' THEN 'BIAS' "
        . "WHEN t LIKE 'LIGHT%' OR t = 'SCIENCE' THEN 'LIGHT' "
        . "WHEN t = 'UNKNOWN' THEN 'UNKNOWN' ELSE t END FROM (SELECT "
        . "REPLACE(REPLACE(REPLACE(UPPER(TRIM(?)), ' ', ''), '_', ''), '-', '') AS t) x");
    $st->execute([$raw]);
    return (string)$st->fetchColumn();
}

function normalizeLabelSql(PDO $conn, string $raw): string
{
    $st = $conn->prepare("SELECT UPPER(TRIM(REGEXP_REPLACE(TRIM(?), '[[:space:]]+', ' ')))");
    $st->execute([$raw]);
    return (string)$st->fetchColumn();
}

echo "=== 13.6 imgtype legacy ===\n";
$legacy = [
    'Light Frame' => 'LIGHT', 'DarkFrame' => 'DARK', 'FlatFrame' => 'FLAT',
    'BIAS ' => 'BIAS', 'light_frame' => 'LIGHT', 'SCIENCE' => 'LIGHT',
    'DarkFlat' => 'DARK', 'Master Dark' => 'DARK', 'GUIDE STAR' => 'GUIDESTAR',
];
foreach ($legacy as $raw => $expected) {
    $got = normalizeImgtypeSql($conn, $raw);
    check('imgtype legacy', $got === $expected, sprintf('%s -> %s', var_export($raw, true), $got));
}

// Il caso che conta: il gate di projects.py:700 e di projectFetchEligibleRow.
// Prima: 'Light Frame' non e' in ('LIGHT','DARK','FLAT','BIAS') -> scartato.
$eligible = ['LIGHT', 'DARK', 'FLAT', 'BIAS'];
$beforeGate = in_array('Light Frame', $eligible, true);
$afterGate = in_array(normalizeImgtypeSql($conn, 'Light Frame'), $eligible, true);
check('il gate scartava la riga legacy', $beforeGate === false);
check('dopo la normalizzazione passa', $afterGate === true,
    "'Light Frame' -> " . normalizeImgtypeSql($conn, 'Light Frame'));

echo "\n=== 13.7 label_object legacy ===\n";
$labels = [
    'xyz 1234' => 'XYZ 1234',
    'Q99' => 'Q99',
    'q  99' => 'Q 99',        // spazi interni collassati
    '  xyz1234  ' => 'XYZ1234',
];
foreach ($labels as $raw => $expected) {
    $got = normalizeLabelSql($conn, $raw);
    check('label_object legacy', $got === $expected,
        sprintf('%s -> %s', var_export($raw, true), $got));
}

// Il caso che conta: il confronto del pannello a coordinate NULL.
// projects.py:393 confrontava il valore grezzo con il bucket normalizzato.
$bucket = strtoupper(trim(preg_replace('/\s+/', ' ', 'XYZ 1234')));
$rawStored = 'xyz 1234';
check('prima il confronto falliva', $rawStored !== $bucket,
    sprintf('%s !== %s', var_export($rawStored, true), $bucket));
check('dopo il confronto passa', normalizeLabelSql($conn, $rawStored) === $bucket,
    normalizeLabelSql($conn, $rawStored) . ' === ' . $bucket);

echo "\n=== prova sul DB: pianta e ripara un panel reale ===\n";
$panelId = (int)$conn->query('SELECT id FROM project_panels ORDER BY id LIMIT 1')->fetchColumn();
if ($panelId > 0) {
    $orig = $conn->query("SELECT label_object FROM project_panels WHERE id = $panelId")
        ->fetchColumn();
    $conn->prepare('UPDATE project_panels SET label_object = ? WHERE id = ?')
        ->execute(['xyz 1234', $panelId]);
    $before = $conn->query("SELECT label_object FROM project_panels WHERE id = $panelId")
        ->fetchColumn();
    // La SQL della migration.
    $conn->exec("UPDATE project_panels SET label_object = "
        . "UPPER(TRIM(REGEXP_REPLACE(TRIM(label_object), '[[:space:]]+', ' '))) "
        . "WHERE id = $panelId");
    $after = $conn->query("SELECT label_object FROM project_panels WHERE id = $panelId")
        ->fetchColumn();
    check('panel riparato sul DB', $before === 'xyz 1234' && $after === 'XYZ 1234',
        sprintf('%s -> %s', var_export($before, true), var_export($after, true)));
    // ripristina
    $conn->prepare('UPDATE project_panels SET label_object = ? WHERE id = ?')
        ->execute([$orig, $panelId]);
    $restored = $conn->query("SELECT label_object FROM project_panels WHERE id = $panelId")
        ->fetchColumn();
    check('valore originale ripristinato', $restored === $orig,
        var_export($restored, true));
} else {
    echo "  (nessun panel: skip)\n";
}

echo "\nRISULTATO: " . ($failed ? 'FALLITI: ' . implode(', ', $failed)
    : 'la normalizzazione e\' corretta e ripara i dati legacy');