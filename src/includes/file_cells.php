<?php
// Shared file-table rendering for the main page and the project
// integration-group tables. Single source of truth for column order,
// labels, tooltips, cell markup and formatting so both tables stay
// graphically identical. Visibility is scope-aware:
//   'main'    -> $hiddenCols (cookie `hiddenCols`)
//   'project' -> $hiddenColsProjects (independent cookie `hiddenColsProjects`)

/**
 * File id regardless of the row flavour: project tree rows carry
 * `file_id` (plus `id` from files.*), main-page rows carry `id`.
 */
function fileIdOf(array $f): int
{
    return (int)($f['file_id'] ?? $f['id'] ?? 0);
}

/**
 * Sort keys rendered right-aligned / parsed numerically by the
 * client-side sorter of the project tables.
 */
function fileColIsNumeric(string $sortKey): bool
{
    static $numeric = [
        'visible_duplicate_count', 'exptime',
        'hfr', 'fwhm', 'hfr_sd', 'eccentricity', 'star_count', 'snr_weight', 'psf_signal',
        'background_mean', 'min_pixel', 'max_pixel', 'mean_pixel', 'median_pixel',
        'bit_depth', 'image_channels',
        'xbinning', 'ybinning', 'egain', 'gain', 'offset', 'xpixsz', 'ypixsz',
        'set_temp', 'ccd_temp', 'usblimit', 'focallen', 'focratio', 'focpos',
        'focussz', 'foctemp', 'ra', 'dec', 'centalt', 'centaz', 'airmass',
        'objctrot', 'siteelev', 'sitelat', 'sitelong', 'equinox',
        'width', 'resolution', 'fov_w', 'file_size', 'mtime', 'moon_phase',
    ];
    return in_array($sortKey, $numeric, true);
}

/**
 * Raw sortable value for a column. Mirrors the displayed value so
 * client-side sorting matches what the user sees.
 */
function fileColRaw(string $sortKey, array $f): string
{
    switch ($sortKey) {
        case 'preview':
        case 'smart_frame_finder':
            return '';
        case 'path':
            return (string)dirname((string)($f['path'] ?? ''));
        case 'visible_duplicate_count':
            return (string)($f['visible_duplicate_count'] ?? $f['total_duplicate_count'] ?? '');
        case 'width':
            return (string)($f['width'] ?? '');
        default:
            $v = $f[$sortKey] ?? '';
            return is_scalar($v) ? (string)$v : '';
    }
}

/**
 * Extra attributes for project-scope cells: column key + raw value for
 * client-side sorting, charts and threshold evaluation.
 * Main scope keeps the historical markup byte-identical (no attributes).
 */
function fileCellAttrs(string $sortKey, array $f, string $scope): string
{
    if ($scope !== 'project') {
        return '';
    }
    return ' data-col="' . htmlspecialchars($sortKey) . '" data-val="' . htmlspecialchars(fileColRaw($sortKey, $f)) . '"';
}

/**
 * Header cells in main-table order: base columns first, then the
 * toggleable groups. Main scope renders server-side sort headers,
 * project scope renders client-side sort headers (key-based, so hidden
 * columns never break sorting or charts).
 */
function renderFileTableHeaders(string $scope): void
{
    if ($scope === 'project') {
        $headers = getBaseColumns();
        foreach ($headers as $sortKey => [$labelKey, $isCalculated]) {
            if (!showColFor($sortKey, 'project')) {
                continue;
            }
            projectHeaderCell($sortKey, $labelKey, $isCalculated);
        }
        foreach (getColumnGroups() as $groupKey => $group) {
            if ($groupKey === 'base') {
                continue;
            }
            foreach ($group['columns'] as $sortKey => [$labelKey, $isCalculated]) {
                if (!showColFor($sortKey, 'project')) {
                    continue;
                }
                projectHeaderCell($sortKey, $labelKey, $isCalculated);
            }
        }
        return;
    }

    // Main scope: historical markup (server-side sorting).
    global $sortBy, $sortOrder, $visibleStarKeys, $visibleFrameKeys, $visibleAdvKeys;
    $headers = getBaseColumns();
    foreach ($headers as $sortKey => [$labelKey, $isCalculated]) {
        if (!showCol($sortKey)) {
            continue;
        }
        render_header_with_tooltip($sortKey, $labelKey, $sortBy, $sortOrder, $isCalculated);
    }
    foreach (getColumnGroups() as $groupKey => $group) {
        if ($groupKey === 'base') {
            continue; // rendered above, in body order
        }
        if ($groupKey === 'star' && empty($visibleStarKeys ?? [])) {
            continue;
        }
        if ($groupKey === 'frame' && empty($visibleFrameKeys ?? [])) {
            continue;
        }
        if ($groupKey !== 'star' && $groupKey !== 'frame' && empty($visibleAdvKeys ?? [])) {
            continue;
        }
        foreach ($group['columns'] as $sortKey => [$labelKey, $isCalculated]) {
            if (!showCol($sortKey)) {
                continue;
            }
            render_header_with_tooltip($sortKey, $labelKey, $sortBy, $sortOrder, $isCalculated);
        }
    }
}

/**
 * Single project-scope header cell: same padding/hover style as the main
 * table, with tooltip and sort metadata instead of a server-side link.
 */
function projectHeaderCell(string $sortKey, string $labelKey, $isCalculated): void
{
    $label = __($labelKey);
    $tooltip = '';
    if ($isCalculated === true) {
        $tooltip = __('calculated_by_app');
    } elseif ($isCalculated === false) {
        $tooltip = '[ ' . strtoupper($sortKey) . ' ]';
    }
    $title = $tooltip ? ' title="' . htmlspecialchars($tooltip) . '"' : '';
    $type = fileColIsNumeric($sortKey) ? 'num' : 'text';
    if ($sortKey === 'preview' || $sortKey === 'smart_frame_finder') {
        $type = 'none';
    }
    echo '<th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600"' . $title
        . ' data-col="' . htmlspecialchars($sortKey) . '" data-type="' . $type . '">'
        . htmlspecialchars($label) . '<span class="ig-sort-ind"></span></th>';
}

/**
 * Body cells for one file row, identical in both scopes (same order,
 * classes, units, links, thumbnails, badges and SFF buttons as table.php).
 * Differences by scope:
 * - project cells carry data-col/data-val for client-side features;
 * - the duplicates badge is static in project scope (the duplicates modal
 *   lives on the main page only);
 * - $nameSuffix lets project rows append the (off)/(auto_off) marker
 *   inside the name cell.
 */
function renderFileTableCells(array $f, string $scope, string $nameSuffix = ''): void
{
    $show = fn(string $k): bool => showColFor($k, $scope);
    if ($scope === 'project') {
        global $visibleProjectStarKeys, $visibleProjectFrameKeys, $visibleProjectAdvKeys;
        $starSet = $visibleProjectStarKeys ?? [];
        $frameSet = $visibleProjectFrameKeys ?? [];
        $advSet = $visibleProjectAdvKeys ?? [];
    } else {
        global $visibleStarKeys, $visibleFrameKeys, $visibleAdvKeys;
        $starSet = $visibleStarKeys ?? [];
        $frameSet = $visibleFrameKeys ?? [];
        $advSet = $visibleAdvKeys ?? [];
    }
    $fid = fileIdOf($f);
    $attr = fn(string $k): string => fileCellAttrs($k, $f, $scope);
    ?>
                <?php if ($show('preview')): ?>
                <td class="p-3"<?= $attr('preview') ?>>
                    <div class="thumb-wrapper relative inline-block align-middle" tabindex="0">
                        <?php if (!empty($f['thumb'])): ?>
                            <!-- The original thumb, its size is controlled by the slider's CSS rules -->
                            <img src="/image.php?id=<?= $fid ?>&type=thumb"
                                 alt="Preview"
                                 class="thumb h-auto rounded shadow-md object-cover">

                            <?php if (!empty($f['thumb_crop'])): ?>
                            <!-- The crop viewport: an overlay positioned absolutely on top of the thumb -->
                            <div class="thumb-crop-viewport absolute top-0 left-0 w-full h-full rounded overflow-hidden opacity-0 transition-opacity duration-200 pointer-events-none bg-gray-900">
                                 <img src="/image.php?id=<?= $fid ?>&type=crop"
                                      alt="Crop Preview"
                                      class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 max-w-none h-auto w-auto">
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="w-[150px] h-[150px] flex items-center justify-center bg-gray-900 rounded">
                                <span class="text-gray-500 text-sm">N/A</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </td>
                <?php endif; ?>
                <td class="p-3"<?= $attr('name') ?>>
                    <a href="/fits/<?= rawurlencode($f['path'] ?? '') ?>" download class="text-blue-400 hover:text-blue-300">
                        <?= htmlspecialchars($f['name'] ?? '') ?>
                    </a><?= $nameSuffix ?>
                </td>
                <?php if ($show('visible_duplicate_count')): ?>
                                <td class="p-3 text-center"<?= $attr('visible_duplicate_count') ?>>
                    <?php if (($f['total_duplicate_count'] ?? 1) > 1): ?>
                        <?php
                            $visibleCount = $f['visible_duplicate_count'];
                            $totalCount = $f['total_duplicate_count'];
                            $badgeColor = ($visibleCount > 1) ? 'bg-yellow-600 text-yellow-100' : 'bg-gray-600 text-gray-100';
                        ?>
                        <?php if ($scope === 'project'): ?>
                        <span class="<?= $badgeColor ?> text-xs font-semibold px-2.5 py-0.5 rounded-full"
                              title="<?= sprintf(__('duplicates_tooltip'), $visibleCount, $totalCount) ?>">
                            <?= $visibleCount ?> / <?= $totalCount ?>
                        </span>
                        <?php else: ?>
                        <span class="duplicate-badge cursor-pointer <?= $badgeColor ?> text-xs font-semibold px-2.5 py-0.5 rounded-full"
                              data-hash="<?= htmlspecialchars($f['file_hash']) ?>"
                              title="<?= sprintf(__('duplicates_tooltip'), $visibleCount, $totalCount) ?>">
                            <?= $visibleCount ?> / <?= $totalCount ?>
                        </span>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <?php if ($show('path')): ?><td class="p-3 text-sm text-gray-400 break-all"<?= $attr('path') ?>><?= htmlspecialchars(dirname($f['path'] ?? '')) ?></td><?php endif; ?>
                <?php if ($show('object')): ?><td class="p-3 text-gray-200"<?= $attr('object') ?>><?= htmlspecialchars($f['object'] ?? '') ?></td><?php endif; ?>
                <?php if ($show('date_obs')): ?>
                <td class="p-3 text-sm text-gray-300"<?= $attr('date_obs') ?>>
                    <span class="utc-date" data-timestamp="<?= !empty($f['date_obs']) ? strtotime($f['date_obs']) : '' ?>">
                        <?= htmlspecialchars($f['date_obs'] ?? '') ?>
                    </span>
                </td>
                <?php endif; ?>
                <?php if ($show('moon_phase')): ?>
                <td class="p-3 text-xl text-center"<?= $attr('moon_phase') ?>>
                    <?= getMoonPhaseMarkup($f['moon_angle'] ?? null, $f['moon_phase'] ?? null) ?>
                </td>
                <?php endif; ?>
                <?php if ($show('exptime')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('exptime') ?>><?= htmlspecialchars($f['exptime'] ?? '') ?>s</td><?php endif; ?>
                <?php if ($show('filter')): ?><td class="p-3 text-gray-200"<?= $attr('filter') ?>><?= htmlspecialchars($f['filter'] ?? '') ?></td><?php endif; ?>

                <?php if ($show('imgtype')): ?><td class="p-3 text-gray-200"<?= $attr('imgtype') ?>><?= htmlspecialchars($f['imgtype'] ?? '') ?></td><?php endif; ?>

                                <?php if ($show('smart_frame_finder')): ?>
                                <td class="p-3 whitespace-nowrap"<?= $attr('smart_frame_finder') ?>>
                    <?php if (strtoupper($f['imgtype'] ?? '') === 'LIGHT'): ?>
                        <div class="flex items-center gap-2">
                            <span class="sff-button cursor-pointer font-mono text-xs bg-sky-800 hover:bg-sky-700 px-2 py-1 rounded" title="<?php echo __('sff_find_similar_lights'); ?>" data-file-id="<?= $fid ?>" data-search-type="lights">L</span>
                            <span class="sff-button cursor-pointer font-mono text-xs bg-gray-600 hover:bg-gray-500 px-2 py-1 rounded" title="<?php echo __('sff_find_bias'); ?>" data-file-id="<?= $fid ?>" data-search-type="bias">B</span>
                            <span class="sff-button cursor-pointer font-mono text-xs bg-gray-600 hover:bg-gray-500 px-2 py-1 rounded" title="<?php echo __('sff_find_darks'); ?>" data-file-id="<?= $fid ?>" data-search-type="darks">D</span>
                            <span class="sff-button cursor-pointer font-mono text-xs bg-gray-600 hover:bg-gray-500 px-2 py-1 rounded" title="<?php echo __('sff_find_flats'); ?>" data-file-id="<?= $fid ?>" data-search-type="flats">F</span>
                        </div>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <?php if (!empty($starSet)): ?>
                    <?php if ($show('hfr')): ?><td class="p-3 text-sm text-gray-300 text-right whitespace-nowrap"<?= $attr('hfr') ?>><?= isset($f['hfr']) && $f['hfr'] !== '' ? number_format((float)$f['hfr'], 2) . ' px' : '' ?></td><?php endif; ?>
                    <?php if ($show('fwhm')): ?><td class="p-3 text-sm text-gray-300 text-right whitespace-nowrap"<?= $attr('fwhm') ?>><?= isset($f['fwhm']) && $f['fwhm'] !== '' ? number_format((float)$f['fwhm'], 2) . '"' : '' ?></td><?php endif; ?>
                    <?php if ($show('hfr_sd')): ?><td class="p-3 text-sm text-gray-300 text-right whitespace-nowrap"<?= $attr('hfr_sd') ?>><?= isset($f['hfr_sd']) && $f['hfr_sd'] !== '' ? number_format((float)$f['hfr_sd'], 2) . ' px' : '' ?></td><?php endif; ?>
                    <?php if ($show('eccentricity')): ?><td class="p-3 text-sm text-gray-300 text-right"<?= $attr('eccentricity') ?>><?= isset($f['eccentricity']) && $f['eccentricity'] !== '' ? number_format((float)$f['eccentricity'], 3) : '' ?></td><?php endif; ?>
                    <?php if ($show('star_count')): ?><td class="p-3 text-sm text-gray-300 text-right"<?= $attr('star_count') ?>><?= isset($f['star_count']) && $f['star_count'] !== '' ? (int)$f['star_count'] : '' ?></td><?php endif; ?>
                    <?php if ($show('snr_weight')): ?><td class="p-3 text-sm text-gray-300 text-right"<?= $attr('snr_weight') ?>><?= isset($f['snr_weight']) && $f['snr_weight'] !== '' ? number_format((float)$f['snr_weight'], 3) : '' ?></td><?php endif; ?>
                    <?php if ($show('psf_signal')): ?><td class="p-3 text-sm text-gray-300 text-right"<?= $attr('psf_signal') ?> title="<?= isset($f['psf_signal']) ? htmlspecialchars((string)$f['psf_signal']) : '' ?>"><?= isset($f['psf_signal']) && $f['psf_signal'] !== '' ? sprintf('%.6g', (float)$f['psf_signal']) : '' ?></td><?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($frameSet)): ?>
                    <?php if ($show('background_mean')): ?><td class="p-3 text-sm text-gray-300 text-right"<?= $attr('background_mean') ?>><?= isset($f['background_mean']) && $f['background_mean'] !== '' ? number_format((float)$f['background_mean'], 1) : '' ?></td><?php endif; ?>
                    <?php if ($show('min_pixel')): ?><td class="p-3 text-sm text-gray-300 text-right"<?= $attr('min_pixel') ?>><?= isset($f['min_pixel']) && $f['min_pixel'] !== '' ? (int)$f['min_pixel'] : '' ?></td><?php endif; ?>
                    <?php if ($show('max_pixel')): ?><td class="p-3 text-sm text-gray-300 text-right"<?= $attr('max_pixel') ?>><?= isset($f['max_pixel']) && $f['max_pixel'] !== '' ? (int)$f['max_pixel'] : '' ?></td><?php endif; ?>
                    <?php if ($show('mean_pixel')): ?><td class="p-3 text-sm text-gray-300 text-right"<?= $attr('mean_pixel') ?>><?= isset($f['mean_pixel']) && $f['mean_pixel'] !== '' ? number_format((float)$f['mean_pixel'], 1) : '' ?></td><?php endif; ?>
                    <?php if ($show('median_pixel')): ?><td class="p-3 text-sm text-gray-300 text-right"<?= $attr('median_pixel') ?>><?= isset($f['median_pixel']) && $f['median_pixel'] !== '' ? number_format((float)$f['median_pixel'], 1) : '' ?></td><?php endif; ?>
                    <?php if ($show('bit_depth')): ?><td class="p-3 text-sm text-gray-300 text-right"<?= $attr('bit_depth') ?>><?= isset($f['bit_depth']) && $f['bit_depth'] !== '' ? (int)$f['bit_depth'] : '' ?></td><?php endif; ?>
                    <?php if ($show('image_channels')): ?><td class="p-3 text-sm text-gray-300 text-right"<?= $attr('image_channels') ?>><?= isset($f['image_channels']) && $f['image_channels'] !== '' ? (int)$f['image_channels'] : '' ?></td><?php endif; ?>
                    <?php if ($show('image_color_type')): ?><td class="p-3 text-gray-200"<?= $attr('image_color_type') ?>><?= htmlspecialchars($f['image_color_type'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('bayer_pattern')): ?><td class="p-3 text-gray-200"<?= $attr('bayer_pattern') ?>><?= htmlspecialchars($f['bayer_pattern'] ?? '') ?></td><?php endif; ?>
                <?php endif; ?>
                                <?php if (!empty($advSet)): ?>
                    <!-- Sensor Data -->
                    <?php if ($show('xbinning')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('xbinning') ?>><?= htmlspecialchars($f['xbinning'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('ybinning')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('ybinning') ?>><?= htmlspecialchars($f['ybinning'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('egain')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('egain') ?>><?= htmlspecialchars($f['egain'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('gain')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('gain') ?>><?= htmlspecialchars($f['gain'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('offset')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('offset') ?>><?= htmlspecialchars($f['offset'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('xpixsz')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('xpixsz') ?>><?= htmlspecialchars($f['xpixsz'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('ypixsz')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('ypixsz') ?>><?= htmlspecialchars($f['ypixsz'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('set_temp')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('set_temp') ?>><?= htmlspecialchars($f['set_temp'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('ccd_temp')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('ccd_temp') ?>><?= htmlspecialchars($f['ccd_temp'] ?? '') ?></td><?php endif; ?>

                    <!-- Equipment Data -->
                    <?php if ($show('instrume')): ?><td class="p-3 text-gray-200"<?= $attr('instrume') ?>><?= htmlspecialchars($f['instrume'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('cameraid')): ?><td class="p-3 text-gray-200"<?= $attr('cameraid') ?>><?= htmlspecialchars($f['cameraid'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('usblimit')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('usblimit') ?>><?= htmlspecialchars($f['usblimit'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('fwheel')): ?><td class="p-3 text-gray-200"<?= $attr('fwheel') ?>><?= htmlspecialchars($f['fwheel'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('telescop')): ?><td class="p-3 text-gray-200"<?= $attr('telescop') ?>><?= htmlspecialchars($f['telescop'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('focallen')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('focallen') ?>><?= htmlspecialchars($f['focallen'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('focratio')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('focratio') ?>><?= htmlspecialchars($f['focratio'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('focname')): ?><td class="p-3 text-gray-200"<?= $attr('focname') ?>><?= htmlspecialchars($f['focname'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('focpos')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('focpos') ?>><?= htmlspecialchars($f['focpos'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('focussz')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('focussz') ?>><?= htmlspecialchars($f['focussz'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('foctemp')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('foctemp') ?>><?= htmlspecialchars($f['foctemp'] ?? '') ?></td><?php endif; ?>

                    <!-- Pointing & Position Data -->
                    <?php if ($show('ra')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('ra') ?>><?= htmlspecialchars($f['ra'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('dec')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('dec') ?>><?= htmlspecialchars($f['dec'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('centalt')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('centalt') ?>><?= htmlspecialchars($f['centalt'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('centaz')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('centaz') ?>><?= htmlspecialchars($f['centaz'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('airmass')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('airmass') ?>><?= htmlspecialchars($f['airmass'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('pierside')): ?><td class="p-3 text-gray-200"<?= $attr('pierside') ?>><?= htmlspecialchars($f['pierside'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('objctrot')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('objctrot') ?>><?= htmlspecialchars($f['objctrot'] ?? '') ?></td><?php endif; ?>

                    <!-- Observatory Site Data -->
                    <?php if ($show('siteelev')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('siteelev') ?>><?= htmlspecialchars($f['siteelev'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('sitelat')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('sitelat') ?>><?= htmlspecialchars($f['sitelat'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('sitelong')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('sitelong') ?>><?= htmlspecialchars($f['sitelong'] ?? '') ?></td><?php endif; ?>

                    <!-- File Metadata -->
                    <?php if ($show('swcreate')): ?><td class="p-3 text-gray-200"<?= $attr('swcreate') ?>><?= htmlspecialchars($f['swcreate'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('roworder')): ?><td class="p-3 text-gray-200"<?= $attr('roworder') ?>><?= htmlspecialchars($f['roworder'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('equinox')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('equinox') ?>><?= htmlspecialchars($f['equinox'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('date_avg')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('date_avg') ?>><span class="utc-date" data-timestamp="<?= !empty($f['date_avg']) ? strtotime($f['date_avg']) : '' ?>"><?= htmlspecialchars($f['date_avg'] ?? '') ?></span></td><?php endif; ?>
                    <?php if ($show('objctra')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('objctra') ?>><?= htmlspecialchars($f['objctra'] ?? '') ?></td><?php endif; ?>
                    <?php if ($show('objctdec')): ?><td class="p-3 text-sm text-gray-300"<?= $attr('objctdec') ?>><?= htmlspecialchars($f['objctdec'] ?? '') ?></td><?php endif; ?>

                    <?php if ($show('width')): ?>
                    <td class="p-3 text-sm text-gray-300"<?= $attr('width') ?>>
                        <?php if (!empty($f['width']) && !empty($f['height'])): ?>
                            <?= htmlspecialchars($f['width']) ?>x<?= htmlspecialchars($f['height']) ?>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <?php if ($show('resolution')): ?>
                    <td class="p-3 text-sm text-gray-300"<?= $attr('resolution') ?>>
                        <?php if (!empty($f['resolution'])): ?>
                            <?= number_format($f['resolution'], 2) ?>"/px
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <?php if ($show('fov_w')): ?>
                                        <td class="p-3 text-sm text-gray-300"<?= $attr('fov_w') ?>>
                        <?php if (!empty($f['fov_w']) && !empty($f['fov_h'])):
                            $fov_w_deg = $f['fov_w'] / 60;
                            $fov_h_deg = $f['fov_h'] / 60;
                        ?>
                            <span title="<?= number_format($f['fov_w'], 1) ?>' x <?= number_format($f['fov_h'], 1) ?>'">
                                <?= number_format($fov_w_deg, 2) ?>° x <?= number_format($fov_h_deg, 2) ?>°
                            </span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <?php if ($show('file_size')): ?>
                    <td class="p-3 text-sm text-gray-300 text-right"<?= $attr('file_size') ?>>
                        <?php if (!empty($f['file_size'])): ?>
                            <?= number_format($f['file_size'] / (1024 * 1024), 2) ?> MB
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <?php if ($show('mtime')): ?>
                    <td class="p-3 text-sm text-gray-300"<?= $attr('mtime') ?>>
                        <span class="utc-date" data-timestamp="<?= !empty($f['mtime']) ? (int)$f['mtime'] : '' ?>">
                            <?= !empty($f['mtime']) ? date('Y-m-d H:i:s', (int)$f['mtime']) : '' ?>
                        </span>
                    </td>
                    <?php endif; ?>
                    <?php if ($show('file_hash')): ?>
                    <td class="p-3 text-sm text-gray-300 font-mono text-xs"<?= $attr('file_hash') ?>><?= htmlspecialchars($f['file_hash'] ?? '') ?></td>
                    <?php endif; ?>
                <?php endif; ?>
    <?php
}
