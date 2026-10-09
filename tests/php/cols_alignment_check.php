<?php
// Check §4 — <th>/<td> alignment in the integration group tables.
//
// It counts the <th> and the <td> really emitted by the production functions
// (renderFileTableHeaders / renderFileTableCells, scope 'project') in the
// STAR_METRICS_ENABLED on/off configurations and with various settings of the
// hiddenColsProjects cookie.
//
// Usage:  docker cp tmp/cols_alignment_check.php awi-php:/var/www/html/
//         docker exec awi-php php /var/www/html/cols_alignment_check.php
//
// The file assumes the star gate added in file_cells.php. To try the
// regression, temporarily remove the block
//   if ($groupKey === 'star' && empty($pStar)) { continue; }
// and the "star OFF" case must come out MISALIGNED.

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
require_once '/var/www/html/includes/columns.php';
require_once '/var/www/html/includes/template_functions.php';
require_once '/var/www/html/includes/file_cells.php';

// Fake row: the renderers read the values, the shape is what counts.
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
    // typed helper: '' is not accepted
    $r['moon_angle'] = null;
    $r['moon_phase'] = null;
    return $r;
}

function scenario(string $label, bool $starMetricsEnabled, ?string $cookie): void
{
    // NB: the parameter cannot be called $showStarMetrics: a `global` on that
    // name would overwrite it with the value from init.php.
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

    // replica of init.php:69-72
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
    printf("%-44s th=%-4d td=%-4d  %s\n", $label, $th, $td, $ok ? 'aligned' : '<<< MISALIGNED');
    if (!$ok) {
        $GLOBALS['failed'] = true;
    }
}

$allStar = 'hfr,fwhm,hfr_sd,eccentricity,star_count,snr_weight,psf_signal';
$onlyBase = 'hfr,fwhm,hfr_sd,eccentricity,star_count,snr_weight,psf_signal,'
    . 'ccd_temp,visible_duplicate_count,smart_frame_finder';
$failed = false;

echo "case" . str_repeat(' ', 21) . "th    td    verdict\n";
echo str_repeat('-', 74) . "\n";
scenario('star ON,  cookie absent (default)', true, null);
scenario('star OFF, cookie absent (default)', false, null);
scenario('star ON,  all stars hidden', true, $allStar);
scenario('star OFF, all stars hidden', false, $allStar);
scenario('star ON,  preview+date_obs only', true, $onlyBase);
scenario('star OFF, preview+date_obs only', false, $onlyBase);

echo "\n" . ($failed ? "RESULT: some cases are misaligned" : "RESULT: all aligned") . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed MISALIGNED.
exit($failed ? 1 : 0);