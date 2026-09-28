<?php
// Central column registry for the main file table.
// Single source of truth for chooser groups, labels and tooltips.
// Hidden columns are skipped server-side (see showCol()), so the rendered
// <th>/<td> sequence always stays dense and aligned. Base columns are always visible.

/**
 * Base columns: sortKey => [labelKey, isCalculated].
 * The leading select-all checkbox is rendered separately (not a data column).
 */
function getBaseColumns(): array
{
    return [
        'preview' => ['preview', true],
        'name' => ['file_name', null],
        'visible_duplicate_count' => ['duplicates', true],
        'path' => ['path', null],
        'object' => ['object', false],
        'date_obs' => ['date_obs', false],
        'moon_phase' => ['moon_phase', true],
        'exptime' => ['exposure', false],
        'filter' => ['filter', false],
        'imgtype' => ['type', false],
        'smart_frame_finder' => ['smart_frame_finder', null],
    ];
}

/**
 * Toggleable column groups.
 * Order = chooser display order (star metrics first). NOTE: the table body
 * renders star cells right after the SFF column and the rest afterwards —
 * table.php renders headers in this same group order, so the two stay aligned.
 * groupKey => ['label' => groupLabelKey, 'columns' => [sortKey => [labelKey, isCalculated]]].
 */
function getColumnGroups(): array
{
    return [
        'star' => ['label' => 'colgroup_star', 'columns' => [
            'hfr_avg' => ['hfr', true], 'fwhm_avg' => ['fwhm', true],
            'ecc_avg' => ['eccentricity', true], 'star_count' => ['star_count', true],
            'snr_weight' => ['snr_weight', true], 'psf_signal' => ['psf_signal', true],
        ]],
        'sensor' => ['label' => 'colgroup_sensor', 'columns' => [
            'xbinning' => ['xbinning', false], 'ybinning' => ['ybinning', false],
            'egain' => ['egain', false], 'gain' => ['gain', false],
            'offset' => ['offset', false], 'xpixsz' => ['xpixsz', false],
            'ypixsz' => ['ypixsz', false], 'set_temp' => ['set_temp', false],
            'ccd_temp' => ['ccd_temp', false],
        ]],
        'equipment' => ['label' => 'colgroup_equipment', 'columns' => [
            'instrume' => ['instrume', false], 'cameraid' => ['cameraid', false],
            'usblimit' => ['usblimit', false], 'fwheel' => ['fwheel', false],
            'telescop' => ['telescop', false], 'focallen' => ['focallen', false],
            'focratio' => ['focratio', false], 'focname' => ['focname', false],
            'focpos' => ['focpos', false], 'focussz' => ['focussz', false],
            'foctemp' => ['foctemp', false],
        ]],
        'pointing' => ['label' => 'colgroup_pointing', 'columns' => [
            'ra' => ['ra', false], 'dec' => ['dec', false],
            'centalt' => ['centalt', false], 'centaz' => ['centaz', false],
            'airmass' => ['airmass', false], 'pierside' => ['pierside', false],
            'objctrot' => ['objctrot', false],
        ]],
        'site' => ['label' => 'colgroup_site', 'columns' => [
            'siteelev' => ['siteelev', false], 'sitelat' => ['sitelat', false],
            'sitelong' => ['sitelong', false],
        ]],
        'filemeta' => ['label' => 'colgroup_filemeta', 'columns' => [
            'swcreate' => ['swcreate', false], 'roworder' => ['roworder', false],
            'equinox' => ['equinox', false], 'date_avg' => ['date_avg', false],
            'objctra' => ['objctra', false], 'objctdec' => ['objctdec', false],
        ]],
        'calculated' => ['label' => 'colgroup_calculated', 'columns' => [
            'width' => ['dimensions', true],
            'resolution' => ['resolution', true],
            'fov_w' => ['field_of_view', true],
            'file_size' => ['size', null],
            'mtime' => ['modification_time', null],
            'file_hash' => ['hash', true],
        ]],
    ];
}

/**
 * Flat list of toggleable sortKeys in body order (advanced + star).
 */
function getToggleableKeys(): array
{
    $keys = [];
    foreach (getColumnGroups() as $group) {
        foreach ($group['columns'] as $sortKey => $_) {
            $keys[] = $sortKey;
        }
    }
    return $keys;
}

/**
 * Resolve hidden columns: legacy ?show_advanced=1 forces everything visible
 * (old bookmarks); otherwise the `hiddenCols` cookie (CSV of sortKeys);
 * when the cookie is absent, hide everything (legacy default).
 */
function resolveHiddenColumns(array $toggleable): array
{
    if (isset($_GET['show_advanced'])) {
        return [];
    }
    if (isset($_COOKIE['hiddenCols'])) {
        $raw = trim((string)$_COOKIE['hiddenCols']);
        if ($raw === '') {
            return [];
        }
        return array_values(array_intersect(explode(',', $raw), $toggleable));
    }
    return $toggleable;
}

/**
 * Per-cell visibility check for table.php. Relies on $hiddenCols from init.php.
 */
function showCol(string $sortKey): bool
{
    global $hiddenCols;
    return !in_array($sortKey, $hiddenCols ?? [], true);
}
