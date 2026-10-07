<?php
// Verifica §4 — allineamento <th>/<td> nelle tabelle di integration group.
//
// Conta i <th> e i <td> realmente emessi dalle funzioni di produzione
// (renderFileTableHeaders / renderFileTableCells, scope 'project') nelle
// configurazioni STAR_METRICS_ENABLED on/off e con varie impostazioni del cookie
// hiddenColsProjects.
//
// Uso:  docker cp tmp/cols_alignment_check.php awi-php:/var/www/html/
//       docker exec awi-php php /var/www/html/cols_alignment_check.php
//
// Il file assume il gate star aggiunto in file_cells.php. Per provare la
// regressione, rimuovere temporaneamente il blocco
//   if ($groupKey === 'star' && empty($pStar)) { continue; }
// e il caso "star OFF" deve risultare DISALLINEATO.

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
require_once '/var/www/html/includes/columns.php';
require_once '/var/www/html/includes/template_functions.php';
require_once '/var/www/html/includes/file_cells.php';

// Riga finta: i renderer leggono i valori, la forma conta.
function fakeRow(): array
{
    $r = [];
    foreach (array_keys(getBaseColumns()) as $k) {
        $r[$k] = '';
    }
    foreach (getColumnGroups() as $g) {
        foreach (array_keys($g['columns']) as $k) {
            $r[$k] = '';
        }
    }
    $r['name'] = 'file.fits';
    $r['file_id'] = 1;
    $r['id'] = 1;
    // helper con tipo: '' non e' accettato
    $r['moon_angle'] = null;
    $r['moon_phase'] = null;
    return $r;
}

function scenario(string $label, bool $starMetricsEnabled, ?string $cookie): void
{
    // NB: il parametro non puo' chiamarsi $showStarMetrics: un `global` su quel
    // nome lo sovrascriverebbe con il valore di init.php.
    global $columnGroups, $hiddenColsProjects, $visibleProjectAdvKeys,
        $visibleProjectStarKeys, $visibleProjectFrameKeys;

    $columnGroups = getColumnGroups();
    $advKeys = [];
    foreach ($columnGroups as $gk => $g) {
        if ($gk !== 'star' && $gk !== 'frame') {
            $advKeys = array_merge($advKeys, array_keys($g['columns']));
        }
    }
    $toggleable = array_values(array_unique(array_merge(
        array_keys(getBaseColumns()),
        $advKeys,
        array_keys($columnGroups['star']['columns']),
        array_keys($columnGroups['frame']['columns'])
    )));

    unset($_COOKIE['hiddenColsProjects']);
    if ($cookie !== null) {
        $_COOKIE['hiddenColsProjects'] = $cookie;
    }

    // replica di init.php:69-72
    $hiddenColsProjects = resolveHiddenColumnsForCookie(
        $toggleable, 'hiddenColsProjects', getProjectsDefaultVisible());
    $visibleProjectAdvKeys = array_values(array_diff($advKeys, $hiddenColsProjects));
    $visibleProjectStarKeys = $starMetricsEnabled
        ? array_values(array_diff(array_keys($columnGroups['star']['columns']), $hiddenColsProjects))
        : [];
    $visibleProjectFrameKeys = array_values(array_diff(
        array_keys($columnGroups['frame']['columns']), $hiddenColsProjects));

    ob_start();
    renderFileTableHeaders('project');
    $th = substr_count(ob_get_clean(), '<th');

    ob_start();
    renderFileTableCells(fakeRow(), 'project');
    $td = substr_count(ob_get_clean(), '<td');

    $ok = $th === $td;
    printf("%-44s th=%-4d td=%-4d  %s\n", $label, $th, $td, $ok ? 'allineato' : '<<< DISALLINEATO');
    if (!$ok) {
        $GLOBALS['failed'] = true;
    }
}

$allStar = 'hfr,fwhm,hfr_sd,eccentricity,star_count,snr_weight,psf_signal';
$onlyBase = 'hfr,fwhm,hfr_sd,eccentricity,star_count,snr_weight,psf_signal,'
    . 'ccd_temp,visible_duplicate_count,smart_frame_finder';
$failed = false;

echo "caso" . str_repeat(' ', 22) . "th    td    esito\n";
echo str_repeat('-', 74) . "\n";
scenario('star ON,  cookie assente (default)', true, null);
scenario('star OFF, cookie assente (default)', false, null);
scenario('star ON,  tutte le star nascoste', true, $allStar);
scenario('star OFF, tutte le star nascoste', false, $allStar);
scenario('star ON,  solo preview+date_obs', true, $onlyBase);
scenario('star OFF, solo preview+date_obs', false, $onlyBase);

echo "\n" . ($failed ? "RISULTATO: ci sono casi disallineati" : "RISULTATO: tutti allineati");