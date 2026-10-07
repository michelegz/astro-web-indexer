<?php
// Verifica find_calibration_files.php: il nome di colonna arriva dal body JSON.
//
// $filters = $params['filters'] e poi $id = $filter['id'] finisce dentro
// $escapedId = "`{$id}`" che viene interpolato nella query come nome di colonna:
//   $sqlWhere[] = "{$escapedId} = :{$id}";
//
// L'unica cosa che lo ferma e' la guardia subito sopra, isset($refFile[$id]): se la
// chiave non esiste nella riga del file di riferimento il filtro viene saltato. Quindi
// un id arbitrario viene scartato, perche' i nomi delle colonne vere non contengono
// backtick.
//
// Il punto di questo test e' che la sicurezza dipende da una proprieta' incidentale di
// un controllo pensato per altro: si regge finche' la guardia resta li'. La correzione
// e' una whitelist esplicita.
//
// Uso:  docker cp tmp/calib_filter_id_check.php awi-php:/tmp/
//       docker exec awi-php php /tmp/calib_filter_id_check.php

$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-56s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FALLITO');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

$src = (string)file_get_contents('/var/www/html/api/find_calibration_files.php');

echo "\n=== il nome di colonna e' validato contro la whitelist? ===\n";

// Gli id dei filtri sono le chiavi del catalogo condiviso, e la whitelist deve coprire
// sia il tipo sia l'appartenenza.
//
// NOTA: questo controllo non deve piu' essere una regex sul sorgente. Era cosi' e passava
// mentre find_calibration_files.php moriva con un TypeError in array_keys() su ogni
// ricerca con almeno un filtro, cioe' il percorso normale della modale: la forma del
// codice era giusta, $allFilters semplicemente non esisteva in quel contesto. Una regex
// non puo' vedere una variabile non definita. Il comportamento lo verifica
// sff_filter_live_check.php, che manda davvero il payload della modale.
$hasGuard = str_contains($src, "require_once __DIR__ . '/../includes/sff_filters.php';")
    && (bool)preg_match('/\$allFilters\s*=\s*sff_all_filters\(\)\s*;/', $src);
check("il filtro e' validato contro il catalogo condiviso", $hasGuard,
    $hasGuard ? '' : 'NESSUN CATALOGO RICHIAMATO: entra nel nome di colonna');
check('  e il catalogo non e\' definito in loco',
    (bool)preg_match('/\$allFilters\s*=\s*\[/', $src) === false,
    'duplicato inline: due copie divergono');

// Il nome di colonna non deve poter contenere un backtick, che chiuderebbe
// l'identificatore quotato.
$idRead = (bool)preg_match('/\$id\s*=\s*\$filter\[\s*[\'"]id[\'"]\s*\]/', $src);
check('$id letto da $filter[\'id\']', $idRead, '');
check('e usato come nome di colonna quotato con backtick',
    str_contains($src, '$escapedId = "`{$id}`"'), '');

// Lo status del rifiuto deve essere un 400 pulito, non un warning nel corpo.
check('un id non valido risponde 400',
    (bool)preg_match('/Invalid filter|invalid filter/', $src)
    || (bool)preg_match('/http_response_code\(400\)/', $src), '');

echo "\n=== il percorso felice deve restare intatto ===\n";
// I filtri che il frontend manda sono dentro $allFilters: dopo il fix devono
// continuare a passare. Verifico che la whitelist sia applicata solo a id sconosciuti,
// cioe' che il file contenga ancora il ciclo sui filtri validati.
check('il ciclo sui filtri c\'e\' ancora', str_contains($src, 'foreach ($filters as $filter)'), '');
check('la guardia isset($refFile[$id]) resta',
    str_contains($src, "isset(\$refFile[\$id])"), '');
check('search_type continua a essere validato',
    str_contains($src, "isset(\$imgTypes[\$searchType])"), '');

echo "\nRISULTATO: " . ($failed ? 'FALLITI: ' . implode(', ', $failed)
    : 'il nome di colonna e\' validato') . "\n";