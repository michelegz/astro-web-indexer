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
 * Order = chooser display order. NOTE: the table body renders star cells right
 * after the SFF column and the rest afterwards — table.php renders headers in
 * this same group order, so the two stay aligned.
 * groupKey => ['label' => groupLabelKey, 'columns' => [sortKey => [labelKey, isCalculated]]].
 */
function getColumnGroups(): array
{
    // Main columns (all except 'name', which is mandatory and has no checkbox).
    $base = getBaseColumns();
    unset($base['name']);
    return [
        'base' => ['label' => 'colgroup_base', 'columns' => $base],
        'star' => ['label' => 'colgroup_star', 'columns' => [
            'hfr' => ['hfr', true], 'fwhm' => ['fwhm', true],
            'hfr_sd' => ['hfr_sd', true], 'eccentricity' => ['eccentricity', true],
            'star_count' => ['star_count', true],
            'snr_weight' => ['snr_weight', true], 'psf_signal' => ['psf_signal', true],
        ]],
        'frame' => ['label' => 'colgroup_frame', 'columns' => [
            'background_mean' => ['background_mean', true],
            'min_pixel' => ['min_pixel', true], 'max_pixel' => ['max_pixel', true],
            'mean_pixel' => ['mean_pixel', true], 'median_pixel' => ['median_pixel', true],
            'bit_depth' => ['bit_depth', false], 'image_channels' => ['image_channels', false],
            'image_color_type' => ['image_color_type', true], 'bayer_pattern' => ['bayer_pattern', false],
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
 * Default visible columns for the project integration-group tables
 * (scope 'project'). 'name' is always visible and needs no entry.
 * This mirrors the old fixed igroup table plus the preview thumbnail.
 */
function getProjectsDefaultVisible(): array
{
    return ['preview', 'date_obs', 'hfr', 'fwhm', 'hfr_sd', 'eccentricity', 'star_count', 'snr_weight', 'psf_signal'];
}

/**
 * Resolve hidden columns for an arbitrary cookie scope.
 * When the cookie is absent, everything outside $defaultVisible is hidden.
 * Unknown keys in the cookie are ignored (intersect with $toggleable).
 */
function resolveHiddenColumnsForCookie(array $toggleable, string $cookieName, ?array $defaultVisible = null): array
{
    if ($cookieName === 'hiddenCols' && isset($_GET['show_advanced'])) {
        return [];
    }
    if (isset($_COOKIE[$cookieName])) {
        $raw = trim((string)$_COOKIE[$cookieName]);
        if ($raw === '') {
            return [];
        }
        return array_values(array_intersect(explode(',', $raw), $toggleable));
    }
    if ($defaultVisible === null) {
        $defaultVisible = array_diff(array_keys(getBaseColumns()), ['name']);
    }
    return array_values(array_diff($toggleable, $defaultVisible));
}

/**
 * Resolve hidden columns: legacy ?show_advanced=1 forces everything visible
 * (old bookmarks); otherwise the `hiddenCols` cookie (CSV of sortKeys);
 * when the cookie is absent, hide everything except the base columns
 * (legacy default: base visible, advanced/star hidden).
 */
function resolveHiddenColumns(array $toggleable): array
{
    return resolveHiddenColumnsForCookie($toggleable, 'hiddenCols');
}

/**
 * Scope-aware visibility check. Scope 'main' uses $hiddenCols (cookie
 * `hiddenCols`), scope 'project' uses $hiddenColsProjects (independent
 * cookie `hiddenColsProjects` for the integration-group tables).
 * The file name column has no checkbox and is always visible.
 */
function showColFor(string $sortKey, string $scope = 'main'): bool
{
    if ($sortKey === 'name') {
        return true;
    }
    if ($scope === 'project') {
        global $hiddenColsProjects;
        return !in_array($sortKey, $hiddenColsProjects ?? [], true);
    }
    global $hiddenCols;
    return !in_array($sortKey, $hiddenCols ?? [], true);
}

/**
 * Per-cell visibility check for table.php. Relies on $hiddenCols from init.php.
 * The file name column has no checkbox and is always visible.
 */
function showCol(string $sortKey): bool
{
    return showColFor($sortKey, 'main');
}
