<?php
// Leggi le preferenze di visualizzazione dai cookie per evitare il flickering
$viewMode = $_COOKIE['viewMode'] ?? 'list';
$thumbSize = $_COOKIE['thumbSize'] ?? '3';
?>
<div class="mb-4 flex justify-end gap-2">
    <button id="exportAstroBinBtn" class="bg-sky-600 hover:bg-sky-700 text-white font-bold py-2 px-4 rounded disabled:opacity-50" disabled>
        <?php echo __('export_astrobin_csv') ?>
    </button>
    <button id="downloadSelectedBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded disabled:opacity-50" disabled>
        <?php echo __('download_selected') ?>
    </button>
</div>

<!-- View container -->
<div id="selectable-container" class="view-container thumb-size-<?php echo htmlspecialchars($thumbSize); ?>">
    
<!-- List View -->
<div class="list-view <?php if ($viewMode !== 'list') echo 'hidden'; ?> bg-gray-800 rounded-lg shadow-lg overflow-x-auto">
    <table class="w-full text-left">
        <thead class="bg-gray-700 text-gray-200">
    <tr>
        <th class="p-3 whitespace-nowrap"><input type="checkbox" id="selectAll" class="form-checkbox h-4 w-4 text-blue-600 rounded"></th>
        <?php
        // Define headers: sort_key => [label_key, is_calculated_flag]
        $headers = [
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

        foreach ($headers as $sortKey => [$labelKey, $isCalculated]) {
            render_header_with_tooltip($sortKey, $labelKey, $sortBy, $sortOrder, $isCalculated);
        }

        if ($showAdvanced) {
            $advancedHeaders = [
                'xbinning' => ['xbinning', false], 'ybinning' => ['ybinning', false], 'egain' => ['egain', false], 
                'offset' => ['offset', false], 'xpixsz' => ['xpixsz', false], 'ypixsz' => ['ypixsz', false], 
                'set_temp' => ['set_temp', false], 'ccd_temp' => ['ccd_temp', false], 'instrume' => ['instrume', false], 
                'cameraid' => ['cameraid', false], 'usblimit' => ['usblimit', false], 'fwheel' => ['fwheel', false], 
                'telescop' => ['telescop', false], 'focallen' => ['focallen', false], 'focratio' => ['focratio', false], 
                'focname' => ['focname', false], 'focpos' => ['focpos', false], 'focussz' => ['focussz', false], 
                'foctemp' => ['foctemp', false], 'ra' => ['ra', false], 'dec' => ['dec', false], 
                'centalt' => ['centalt', false], 'centaz' => ['centaz', false], 'airmass' => ['airmass', false], 
                'pierside' => ['pierside', false], 'objctrot' => ['objctrot', false], 'siteelev' => ['siteelev', false], 
                'sitelat' => ['sitelat', false], 'sitelong' => ['sitelong', false], 'swcreate' => ['swcreate', false], 
                'roworder' => ['roworder', false], 'equinox' => ['equinox', false], 'date_avg' => ['date_avg', false], 
                'objctra' => ['objctra', false], 'objctdec' => ['objctdec', false],
                'width' => ['dimensions', true], 
                'resolution' => ['resolution', true], 
                'fov_w' => ['field_of_view', true], 
                'file_size' => ['size', null], 
                'mtime' => ['modification_time', null], 
                'file_hash' => ['hash', true],
            ];
            foreach ($advancedHeaders as $sortKey => [$labelKey, $isCalculated]) {
                render_header_with_tooltip($sortKey, $labelKey, $sortBy, $sortOrder, $isCalculated);
            }
        }
        ?>
    </tr>
</thead>
                <tbody>
            <?php foreach ($files as $f): ?>
                        <tr data-id="<?= $f['id'] ?>" class="selectable-item border-b border-gray-700 hover:bg-gray-700">
                <td class="p-3"><input type="checkbox" class="file-checkbox h-4 w-4 text-blue-600 rounded" value="<?= htmlspecialchars($f['path'] ?? '') ?>" data-id="<?= $f['id'] ?>"></td>
                <td class="p-3">
                    <div class="thumb-wrapper relative inline-block align-middle" tabindex="0">
                        <?php if ($f['thumb']): ?>
                            <!-- The original thumb, its size is controlled by the slider's CSS rules -->
                            <img src="/image.php?id=<?= $f['id'] ?>&type=thumb" 
                                 alt="Preview" 
                                 class="thumb h-auto rounded shadow-md object-cover">
                            
                            <?php if ($f['thumb_crop']): ?>
                            <!-- The crop viewport: an overlay positioned absolutely on top of the thumb -->
                            <div class="thumb-crop-viewport absolute top-0 left-0 w-full h-full rounded overflow-hidden opacity-0 transition-opacity duration-200 pointer-events-none bg-gray-900">
                                 <img src="/image.php?id=<?= $f['id'] ?>&type=crop" 
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
                <td class="p-3">
                    <a href="/fits/<?= rawurlencode($f['path']) ?>" download class="text-blue-400 hover:text-blue-300">
                        <?= htmlspecialchars($f['name'] ?? '') ?>
                    </a>
                </td>
                                <td class="p-3 text-center">
                    <?php if (($f['total_duplicate_count'] ?? 1) > 1): ?>
                        <?php 
                            $visibleCount = $f['visible_duplicate_count'];
                            $totalCount = $f['total_duplicate_count'];
                            $badgeColor = ($visibleCount > 1) ? 'bg-yellow-600 text-yellow-100' : 'bg-gray-600 text-gray-100';
                        ?>
                        <span class="duplicate-badge cursor-pointer <?= $badgeColor ?> text-xs font-semibold px-2.5 py-0.5 rounded-full"
                              data-hash="<?= htmlspecialchars($f['file_hash']) ?>"
                              title="<?= sprintf(__('duplicates_tooltip'), $visibleCount, $totalCount) ?>">
                            <?= $visibleCount ?> / <?= $totalCount ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td class="p-3 text-sm text-gray-400 break-all"><?= htmlspecialchars(dirname($f['path'] ?? '')) ?></td>
                <td class="p-3 text-gray-200"><?= htmlspecialchars($f['object'] ?? '') ?></td>
                <td class="p-3 text-sm text-gray-300">
                    <span class="utc-date" data-timestamp="<?= !empty($f['date_obs']) ? strtotime($f['date_obs']) : '' ?>">
                        <?= htmlspecialchars($f['date_obs'] ?? '') ?>
                    </span>
                </td>
                <td class="p-3 text-xl text-center">
                    <?= getMoonPhaseMarkup($f['moon_angle'] ?? null, $f['moon_phase'] ?? null) ?>
                </td>
                <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['exptime'] ?? '') ?>s</td>
                <td class="p-3 text-gray-200"><?= htmlspecialchars($f['filter'] ?? '') ?></td>
                
                <td class="p-3 text-gray-200"><?= htmlspecialchars($f['imgtype'] ?? '') ?></td>
                                
                                <td class="p-3 whitespace-nowrap">
                    <?php if (strtoupper($f['imgtype'] ?? '') === 'LIGHT'): ?>
                        <div class="flex items-center gap-2">
                            <span class="sff-button cursor-pointer font-mono text-xs bg-sky-800 hover:bg-sky-700 px-2 py-1 rounded" title="<?php echo __('sff_find_similar_lights'); ?>" data-file-id="<?= $f['id'] ?>" data-search-type="lights">L</span>
                            <span class="sff-button cursor-pointer font-mono text-xs bg-gray-600 hover:bg-gray-500 px-2 py-1 rounded" title="<?php echo __('sff_find_bias'); ?>" data-file-id="<?= $f['id'] ?>" data-search-type="bias">B</span>
                            <span class="sff-button cursor-pointer font-mono text-xs bg-gray-600 hover:bg-gray-500 px-2 py-1 rounded" title="<?php echo __('sff_find_darks'); ?>" data-file-id="<?= $f['id'] ?>" data-search-type="darks">D</span>
                            <span class="sff-button cursor-pointer font-mono text-xs bg-gray-600 hover:bg-gray-500 px-2 py-1 rounded" title="<?php echo __('sff_find_flats'); ?>" data-file-id="<?= $f['id'] ?>" data-search-type="flats">F</span>
                        </div>
                    <?php endif; ?>
                </td>
                                <?php if ($showAdvanced): ?>
                    <!-- Sensor Data -->
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['xbinning'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['ybinning'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['egain'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['offset'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['xpixsz'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['ypixsz'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['set_temp'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['ccd_temp'] ?? '') ?></td>
                    
                    <!-- Equipment Data -->
                    <td class="p-3 text-gray-200"><?= htmlspecialchars($f['instrume'] ?? '') ?></td>
                    <td class="p-3 text-gray-200"><?= htmlspecialchars($f['cameraid'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['usblimit'] ?? '') ?></td>
                    <td class="p-3 text-gray-200"><?= htmlspecialchars($f['fwheel'] ?? '') ?></td>
                    <td class="p-3 text-gray-200"><?= htmlspecialchars($f['telescop'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['focallen'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['focratio'] ?? '') ?></td>
                    <td class="p-3 text-gray-200"><?= htmlspecialchars($f['focname'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['focpos'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['focussz'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['foctemp'] ?? '') ?></td>

                    <!-- Pointing & Position Data -->
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['ra'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['dec'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['centalt'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['centaz'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['airmass'] ?? '') ?></td>
                    <td class="p-3 text-gray-200"><?= htmlspecialchars($f['pierside'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['objctrot'] ?? '') ?></td>

                    <!-- Observatory Site Data -->
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['siteelev'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['sitelat'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['sitelong'] ?? '') ?></td>

                    <!-- File Metadata -->
                    <td class="p-3 text-gray-200"><?= htmlspecialchars($f['swcreate'] ?? '') ?></td>
                    <td class="p-3 text-gray-200"><?= htmlspecialchars($f['roworder'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['equinox'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><span class="utc-date" data-timestamp="<?= !empty($f['date_avg']) ? strtotime($f['date_avg']) : '' ?>"><?= htmlspecialchars($f['date_avg'] ?? '') ?></span></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['objctra'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['objctdec'] ?? '') ?></td>

                    <td class="p-3 text-sm text-gray-300">
                        <?php if (!empty($f['width']) && !empty($f['height'])): ?>
                            <?= htmlspecialchars($f['width']) ?>x<?= htmlspecialchars($f['height']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="p-3 text-sm text-gray-300">
                        <?php if (!empty($f['resolution'])): ?>
                            <?= number_format($f['resolution'], 2) ?>"/px
                        <?php endif; ?>
                    </td>
                                        <td class="p-3 text-sm text-gray-300">
                        <?php if (!empty($f['fov_w']) && !empty($f['fov_h'])): 
                            $fov_w_deg = $f['fov_w'] / 60;
                            $fov_h_deg = $f['fov_h'] / 60;
                        ?>
                            <span title="<?= number_format($f['fov_w'], 1) ?>' x <?= number_format($f['fov_h'], 1) ?>'">
                                <?= number_format($fov_w_deg, 2) ?>° x <?= number_format($fov_h_deg, 2) ?>°
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="p-3 text-sm text-gray-300 text-right">
                        <?php if (!empty($f['file_size'])): ?>
                            <?= number_format($f['file_size'] / (1024 * 1024), 2) ?> MB
                        <?php endif; ?>
                    </td>
                    <td class="p-3 text-sm text-gray-300">
                        <span class="utc-date" data-timestamp="<?= !empty($f['mtime']) ? (int)$f['mtime'] : '' ?>">
                            <?= !empty($f['mtime']) ? date('Y-m-d H:i:s', (int)$f['mtime']) : '' ?>
                        </span>
                    </td>
                    <td class="p-3 text-sm text-gray-300 font-mono text-xs"><?= htmlspecialchars($f['file_hash'] ?? '') ?></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
                        <?php if (empty($files)): ?>
                <tr><td colspan="<?= $showAdvanced ? '33' : '9' ?>" class="p-4 text-center text-gray-500"><?php echo __('no_files_found') ?></td></tr>
            <?php endif; ?>
</tbody>
    </table>
</div>

<!-- Thumbnail View (Initially Hidden) -->
<div class="thumbnail-view bg-gray-800 rounded-lg shadow-lg p-4 <?php if ($viewMode !== 'thumbnail') echo 'hidden'; ?>">
    <?php foreach ($files as $f): ?>
    <div class="selectable-item thumb-card" data-id="<?= $f['id'] ?>">
        <div class="thumb-wrapper relative inline-block align-middle" tabindex="0">
            <div class="thumb-image-container">
                <input type="checkbox" class="file-checkbox thumb-checkbox h-4 w-4 text-blue-600 rounded" 
                    value="<?= htmlspecialchars($f['path'] ?? '') ?>" 
                    data-id="<?= $f['id'] ?>">
                <?php if ($f['thumb']): ?>
                    <img src="/image.php?id=<?= $f['id'] ?>&type=thumb" 
                        alt="Preview" 
                        class="thumb max-w-full h-auto rounded shadow-md object-cover">
                <?php else: ?>


                    <div class="flex items-center justify-center h-32 w-full bg-gray-900 text-gray-500 text-sm rounded">N/A</div>
                <?php endif; ?>

                 <?php if ($f['thumb_crop']): ?>
                            <!-- The crop viewport: an overlay positioned absolutely on top of the thumb -->
                            <div class="thumb-crop-viewport absolute top-0 left-0 w-full h-full rounded overflow-hidden opacity-0 transition-opacity duration-200 pointer-events-none bg-gray-900">
                                 <img src="/image.php?id=<?= $f['id'] ?>&type=crop" 
                                      alt="Crop Preview" 
                                      class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 max-w-none h-auto w-auto">
                            </div>
                 <?php endif; ?>

            </div>
        </div>
        <div class="thumb-details">
            <div class="thumb-title">
                <a href="/fits/<?= rawurlencode($f['path']) ?>" download class="text-blue-400 hover:text-blue-300">
                    <?= htmlspecialchars($f['name'] ?? '') ?>
                </a>
                <?php if (($f['total_duplicate_count'] ?? 1) > 1): ?>
                    <?php 
                        $visibleCount = $f['visible_duplicate_count'];
                        $totalCount = $f['total_duplicate_count'];
                        $badgeColor = ($visibleCount > 1) ? 'bg-yellow-600 text-yellow-100' : 'bg-gray-600 text-gray-100';
                    ?>
                <?php endif; ?>
            </div>
            <div class="thumb-meta">
                <div class="meta-item">
                    <span class="meta-label"><?php echo __('object') ?></span>
                    <span class="meta-value"><?= htmlspecialchars($f['object'] ?? '') ?></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label"><?php echo __('filter') ?></span>
                    <span class="meta-value"><?= htmlspecialchars($f['filter'] ?? '') ?></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label"><?php echo __('exposure') ?></span>
                    <span class="meta-value"><?= htmlspecialchars($f['exptime'] ?? '') ?>s</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label"><?php echo __('type') ?></span>
                    <span class="meta-value"><?= htmlspecialchars($f['imgtype'] ?? '') ?></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label"><?php echo __('date_obs') ?></span>
                    <span class="meta-value utc-date" data-timestamp="<?= !empty($f['date_obs']) ? strtotime($f['date_obs']) : '' ?>">
                        <?= htmlspecialchars($f['date_obs'] ?? '') ?>
                    </span>
                </div>
                <div class="meta-item">
                    <span class="meta-label"><?php echo __('path') ?></span>
                    <span class="meta-value text-xs break-all"><?= htmlspecialchars(dirname($f['path'] ?? '')) ?></span>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <?php if (empty($files)): ?>
    <div class="p-4 text-center text-gray-500"><?php echo __('no_files_found') ?></div>
    <?php endif; ?>
</div>

<!-- Modal for Duplicates Management -->
<div id="duplicatesModal" class="fixed inset-0 bg-gray-900 bg-opacity-75 flex items-center justify-center hidden z-50">
    <div class="bg-gray-800 rounded-lg shadow-xl p-6 w-full max-w-7xl transform transition-all">
        <div class="flex justify-between items-center border-b border-gray-700 pb-3">
            <h3 class="text-xl font-semibold text-white"><?php echo __('duplicate_files') ?></h3>
            <button id="closeModalBtn" class="text-gray-400 hover:text-white text-2xl">&times;</button>
        </div>
        
        <div class="mt-4">
            <p class="text-sm text-gray-400 mb-2" id="modalReferenceFile"></p>
        </div>

        <div id="duplicatesContainer" class="mt-4 max-h-[60vh] overflow-y-auto">
            <!-- Duplicate file table will be injected here -->
        </div>

        <div class="flex justify-end pt-4 border-t border-gray-700 mt-4">
            <button id="showSelectedBtn" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded mr-2 disabled:opacity-50" disabled><?php echo __('show_selected') ?></button>
            <button id="hideSelectedBtn" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded disabled:opacity-50" disabled><?php echo __('hide_selected') ?></button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('duplicatesModal');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const container = document.getElementById('duplicatesContainer');
    const referenceFileElement = document.getElementById('modalReferenceFile');
    const hideBtn = document.getElementById('hideSelectedBtn');
    const showBtn = document.getElementById('showSelectedBtn');
    
    let currentHash = null;
    let referencePath = null;
    
    document.querySelectorAll('.duplicate-badge').forEach(badge => {
        badge.addEventListener('click', function() {
            currentHash = this.dataset.hash;
            referencePath = this.closest('tr').querySelector('a').href.split('/fits/')[1];
            referencePath = decodeURIComponent(referencePath);
            
            if (!currentHash) return;

            container.innerHTML = `<p class="text-center p-4">${'<?php echo __('loading...') ?>'}</p>`;
            modal.classList.remove('hidden');

            fetch(`/api/get_duplicates.php?hash=${currentHash}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) throw new Error(data.error);
                    renderDuplicatesTable(data);
                })
                .catch(error => {
                    container.innerHTML = `<p class="text-red-500 p-4">${'<?php echo __('error_fetching_duplicates') ?>'}: ${error.message}</p>`;
                });
        });
    });

    function renderDuplicatesTable(files) {
        referenceFileElement.innerHTML = `<strong><?php echo __('reference_file') ?>:</strong> ${escapeHTML(referencePath)}`;

        // Move reference file to the top
        files.sort((a, b) => (a.path === referencePath) ? -1 : (b.path === referencePath) ? 1 : 0);
        
        let tableHtml = `
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-700 text-gray-200">
                    <tr>
                        <th class="p-2"><input type="checkbox" id="selectAllDuplicates" /></th>
                        <th class="p-2"><?php echo __('file_name') ?></th>
                        <th class="p-2"><?php echo __('path') ?></th>
                        <th class="p-2"><?php echo __('hash') ?></th>
                        <th class="p-2"><?php echo __('modification_time') ?></th>
                    </tr>
                </thead>
                <tbody>`;

        files.forEach(file => {
            const isReference = file.path === referencePath;
            const isHidden = file.is_hidden == 1;
            const mtime = new Date(file.mtime * 1000).toLocaleString();
            
            tableHtml += `
                <tr data-id="${file.id}" class="${isReference ? 'bg-gray-600' : ''} ${isHidden ? 'opacity-50 line-through' : ''}">
                    <td class="p-2">
                        <input type="checkbox" class="duplicate-checkbox" data-id="${file.id}" data-is-hidden="${isHidden ? '1' : '0'}" ${isReference ? 'disabled' : ''}>
                    </td>
                    <td class="p-2"><a href="/fits/${encodeURIComponent(file.path)}" download class="text-blue-400 hover:text-blue-300">${escapeHTML(file.name)}</a></td>
                    <td class="p-2">${escapeHTML(file.path)}</td>
                    <td class="p-2 font-mono text-xs">${escapeHTML(file.file_hash)}</td>
                    <td class="p-2">${mtime}</td>
                </tr>`;
        });
        
        tableHtml += '</tbody></table>';
        container.innerHTML = tableHtml;
        updateButtonStates();
    }

    function updateButtonStates() {
        const checkedVisible = container.querySelectorAll('.duplicate-checkbox:checked[data-is-hidden="0"]').length;
        const checkedHidden = container.querySelectorAll('.duplicate-checkbox:checked[data-is-hidden="1"]').length;
        
        hideBtn.disabled = checkedVisible === 0;
        showBtn.disabled = checkedHidden === 0;
    }
    
    container.addEventListener('change', function(event) {
        if (event.target.matches('.duplicate-checkbox') || event.target.id === 'selectAllDuplicates') {
            if (event.target.id === 'selectAllDuplicates') {
                const isChecked = event.target.checked;
                container.querySelectorAll('.duplicate-checkbox:not(:disabled)').forEach(cb => cb.checked = isChecked);
            }
            updateButtonStates();
        }
    });

    hideBtn.addEventListener('click', () => handleVisibilityChange('hide'));
    showBtn.addEventListener('click', () => handleVisibilityChange('show'));

    function handleVisibilityChange(action) {
        const selector = (action === 'hide') ? '.duplicate-checkbox:checked[data-is-hidden="0"]' : '.duplicate-checkbox:checked[data-is-hidden="1"]';
        const ids = Array.from(container.querySelectorAll(selector)).map(cb => parseInt(cb.dataset.id));

        if (ids.length === 0) return;

        fetch('/api/update_visibility.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: ids, action: action, hash: currentHash })
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) throw new Error(data.error);
            if (data.success) {
                // Refresh modal content
                fetch(`/api/get_duplicates.php?hash=${currentHash}`)
                    .then(res => res.json())
                    .then(renderDuplicatesTable);
                
                // Update badge on the main page
                const badge = document.querySelector(`.duplicate-badge[data-hash="${currentHash}"]`);
                if (badge) {
                    const newCount = data.new_visible_count;
                    if (newCount > 1) {
                        badge.textContent = newCount;
                    } else {
                        badge.remove(); // Or hide it: badge.style.display = 'none';
                    }
                }

                // Remove/add rows from the main table if they are visible
                ids.forEach(id => {
                    const mainRowSelector = `.file-checkbox[value*="${id}"]`; // This might need a better selector
                    // This part is complex because we don't have a direct link from file ID to main table row.
                    // A full page reload is simpler and more reliable.
                });
                // For simplicity and reliability, we'll just reload the page to reflect changes.
                window.location.reload();
            }
        })
        .catch(error => {
            alert(`Error: ${error.message}`);
        });
    }

    closeModalBtn.addEventListener('click', () => modal.classList.add('hidden'));
    modal.addEventListener('click', e => (e.target === modal) && modal.classList.add('hidden'));
    function escapeHTML(str) { /* ... same as before ... */ }
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('duplicatesModal');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const container = document.getElementById('duplicatesContainer');
    const referenceFileElement = document.getElementById('modalReferenceFile');
    const hideBtn = document.getElementById('hideSelectedBtn');
    const showBtn = document.getElementById('showSelectedBtn');
    
    let currentHash = null;
    let referencePath = null;
    
    // Use event delegation for dynamically added/removed badges
    document.body.addEventListener('click', function(event) {
        const badge = event.target.closest('.duplicate-badge');
        if (!badge) return;

        currentHash = badge.dataset.hash;
        const referenceRow = badge.closest('tr');
        const referenceLink = referenceRow.querySelector('a[href*="/fits/"]');
        if (!referenceLink) return;
        
        referencePath = decodeURIComponent(referenceLink.getAttribute('href').split('/fits/')[1]);
        
        if (!currentHash) return;

        container.innerHTML = `<p class="text-center p-4">${'<?php echo __('loading...') ?>'}</p>`;
        modal.classList.remove('hidden');

        fetch(`/api/get_duplicates.php?hash=${currentHash}`)
            .then(response => response.json())
            .then(data => {
                if (data.error) throw new Error(data.error);
                renderDuplicatesTable(data);
            })
            .catch(error => {
                container.innerHTML = `<p class="text-red-500 p-4">${'<?php echo __('error_fetching_duplicates') ?>'}: ${error.message}</p>`;
            });
    });

    function renderDuplicatesTable(files) {
        referenceFileElement.innerHTML = `<strong><?php echo __('reference_file') ?>:</strong> ${escapeHTML(referencePath)}`;

        files.sort((a, b) => {
            if (a.path === referencePath) return -1;
            if (b.path === referencePath) return 1;
            return a.path.localeCompare(b.path);
        });
        
        let tableHtml = `
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-700 text-gray-200">
                    <tr>
                        <th class="p-2"><input type="checkbox" id="selectAllDuplicates" /></th>
                        <th class="p-2"><?php echo __('file_name') ?></th>
                        <th class="p-2"><?php echo __('path') ?></th>
                        <th class="p-2"><?php echo __('hash') ?></th>
                        <th class="p-2"><?php echo __('modification_time') ?></th>
                    </tr>
                </thead>
                <tbody>`;

        files.forEach(file => {
            const isReference = file.path === referencePath;
            const isHidden = file.is_hidden == 1;
            const mtime = file.mtime ? new Date(file.mtime * 1000).toLocaleString() : 'N/A';
            
            tableHtml += `
                <tr data-id="${file.id}" class="${isReference ? 'bg-gray-600' : ''} ${isHidden ? 'opacity-50 ' : ''}">
                    <td class="p-2">
                        <input type="checkbox" class="duplicate-checkbox" data-id="${file.id}" data-is-hidden="${isHidden ? '1' : '0'}" ${isReference ? 'disabled' : ''}>
                    </td>
                    <td class="p-2"><a href="/fits/${encodeURIComponent(file.path)}" download class="text-blue-400 hover:text-blue-300">${escapeHTML(file.name)}</a></td>
                    <td class="p-2">${escapeHTML(file.path)}</td>
                    <td class="p-2 font-mono text-xs">${escapeHTML(file.file_hash)}</td>
                    <td class="p-2">${mtime}</td>
                </tr>`;
        });
        
        tableHtml += '</tbody></table>';
        container.innerHTML = tableHtml;
        updateButtonStates();
    }

    function updateButtonStates() {
        const checkedVisible = container.querySelectorAll('.duplicate-checkbox:checked[data-is-hidden="0"]').length;
        const checkedHidden = container.querySelectorAll('.duplicate-checkbox:checked[data-is-hidden="1"]').length;
        
        hideBtn.disabled = checkedVisible === 0;
        showBtn.disabled = checkedHidden === 0;
    }
    
    container.addEventListener('change', function(event) {
        if (event.target.matches('.duplicate-checkbox, #selectAllDuplicates')) {
            if (event.target.id === 'selectAllDuplicates') {
                const isChecked = event.target.checked;
                container.querySelectorAll('.duplicate-checkbox:not(:disabled)').forEach(cb => cb.checked = isChecked);
            }
            updateButtonStates();
        }
    });

    hideBtn.addEventListener('click', () => handleVisibilityChange('hide'));
    showBtn.addEventListener('click', () => handleVisibilityChange('show'));

    function handleVisibilityChange(action) {
        const stateToSelect = (action === 'hide') ? '0' : '1';
        const ids = Array.from(container.querySelectorAll(`.duplicate-checkbox:checked[data-is-hidden="${stateToSelect}"]`))
                         .map(cb => parseInt(cb.dataset.id));

        if (ids.length === 0) return;

        // Disable buttons to prevent double-clicking
        hideBtn.disabled = true;
        showBtn.disabled = true;

        fetch('/api/update_visibility.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: ids, action: action, hash: currentHash })
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) throw new Error(data.error);
            if (data.success) {
                // Instead of a full reload, we can be smarter.
                // 1. Refresh the modal content
                fetch(`/api/get_duplicates.php?hash=${currentHash}`)
                    .then(res => res.json())
                    .then(renderDuplicatesTable);
                
                                // 2. Update the badge on the main page for all files with the same hash
                const allBadges = document.querySelectorAll(`.duplicate-badge[data-hash="${currentHash}"]`);
                if (allBadges.length > 0) {
                    const newVisible = data.new_visible_count;
                    const newTotal = data.new_total_count;
                    
                    allBadges.forEach(badge => {
                        if (newTotal <= 1) {
                            badge.remove();
                            return;
                        }

                        badge.textContent = `${newVisible} / ${newTotal}`;
                        badge.title = `<?php echo sprintf(__('duplicates_tooltip'), '${newVisible}', '${newTotal}'); ?>`.replace("'${newVisible}'", newVisible).replace("'${newTotal}'", newTotal);

                        if (newVisible > 1) {
                            badge.classList.remove('bg-gray-600', 'text-gray-100');
                            badge.classList.add('bg-yellow-600', 'text-yellow-100');
                        } else {
                            badge.classList.remove('bg-yellow-600', 'text-yellow-100');
                            badge.classList.add('bg-gray-600', 'text-gray-100');
                        }
                    });
                }
                
                // 3. Instead of a full reload, just hide the rows that were hidden
                if (action === 'hide') {
                    ids.forEach(id => {
                        const row = document.querySelector(`tr[data-id="${id}"]`);
                        if(row) row.remove();
                    });
                } else {
                    // For showing files, a reload is safer to ensure pagination and sorting are correct
                     window.location.reload();
                }

                // Close the modal after a successful action
                modal.classList.add('hidden');
            }
        })
        .catch(error => {
            alert(`Error: ${error.message}`);
            updateButtonStates(); // Re-enable buttons on error
        });
    }
    
    closeModalBtn.addEventListener('click', () => modal.classList.add('hidden'));
    modal.addEventListener('click', e => (e.target === modal) && modal.classList.add('hidden'));
    
    function escapeHTML(str) {
        if (!str) return '';
        return str.replace(/[&<>'"]/g, 
            tag => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
                        }[tag] || tag));
    }
});
</script>

<!-- Modal for AstroBin CSV Export -->
<div id="astrobinModal" class="fixed inset-0 bg-gray-900 bg-opacity-75 flex items-center justify-center hidden z-50">
    <div class="bg-gray-800 rounded-lg shadow-xl p-6 w-full max-w-6xl transform transition-all">
        <div class="flex justify-between items-center border-b border-gray-700 pb-3">
            <h3 class="text-xl font-semibold text-white"><?php echo __('export_astrobin_csv') ?></h3>
            <button id="closeAstrobinModalBtn" class="text-gray-400 hover:text-white text-2xl">&times;</button>
        </div>
        
        <div class="mt-4">
            <p class="text-sm text-gray-300 mb-4">
                <?php echo __('astrobin_modal_explanation'); ?>
            </p>
            <textarea id="astrobinCsvText" readonly class="w-full h-64 bg-gray-900 text-gray-300 font-mono text-sm p-3 rounded-md border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:outline-none"></textarea>
        </div>

        <div class="flex justify-end pt-4 border-t border-gray-700 mt-4">
            <button id="copyAstrobinCsvBtn" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded"><?php echo __('copy_to_clipboard') ?></button>
        </div>
    </div>
</div>
