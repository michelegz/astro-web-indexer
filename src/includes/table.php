<div class="mb-4 flex justify-end gap-2">
    <button id="exportAstroBinBtn" class="bg-sky-600 hover:bg-sky-700 text-white font-bold py-2 px-4 rounded disabled:opacity-50" disabled>
        <?php echo __('export_astrobin_csv') ?>
    </button>
    <button id="downloadSelectedBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded disabled:opacity-50" disabled>
        <?php echo __('download_selected') ?>
    </button>
</div>

<div class="overflow-x-auto bg-gray-800 rounded-lg shadow-lg">
    <table class="w-full text-left">
        <thead class="bg-gray-700 text-gray-200">
            <tr>
                                                <th class="p-3 whitespace-nowrap"><input type="checkbox" id="selectAll" class="form-checkbox h-4 w-4 text-blue-600 rounded"></th>
                                <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('name')"><?php echo __('file_name') ?> <?php echoIcon('name', $sortBy, $sortOrder); ?></th>
                <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600 text-center" onclick="sortTable('visible_duplicate_count')"><?php echo __('duplicates') ?> <?php echoIcon('visible_duplicate_count', $sortBy, $sortOrder); ?></th>
                <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('path')"><?php echo __('path') ?> <?php echoIcon('path', $sortBy, $sortOrder); ?></th>
                <th class="p-3 whitespace-nowrap"><?php echo __('preview') ?></th>
                                <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('object')"><?php echo __('object') ?> <?php echoIcon('object', $sortBy, $sortOrder); ?></th>
                <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('date_obs')"><?php echo __('date_obs') ?> <?php echoIcon('date_obs', $sortBy, $sortOrder); ?></th>
                <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('exptime')"><?php echo __('exposure') ?> <?php echoIcon('exptime', $sortBy, $sortOrder); ?></th>
                <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('filter')"><?php echo __('filter') ?> <?php echoIcon('filter', $sortBy, $sortOrder); ?></th>
                                <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('imgtype')"><?php echo __('type') ?> <?php echoIcon('imgtype', $sortBy, $sortOrder); ?></th>
                <?php if ($showAdvanced): ?>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('xbinning')"><?php echo __('xbinning') ?> <?php echoIcon('xbinning', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('ybinning')"><?php echo __('ybinning') ?> <?php echoIcon('ybinning', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('egain')"><?php echo __('egain') ?> <?php echoIcon('egain', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('offset')"><?php echo __('offset') ?> <?php echoIcon('offset', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('xpixsz')"><?php echo __('xpixsz') ?> <?php echoIcon('xpixsz', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('ypixsz')"><?php echo __('ypixsz') ?> <?php echoIcon('ypixsz', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('instrume')"><?php echo __('instrume') ?> <?php echoIcon('instrume', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('set_temp')"><?php echo __('set_temp') ?> <?php echoIcon('set_temp', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('ccd_temp')"><?php echo __('ccd_temp') ?> <?php echoIcon('ccd_temp', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('telescop')"><?php echo __('telescop') ?> <?php echoIcon('telescop', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-g
                    ray-600" onclick="sortTable('focallen')"><?php echo __('focallen') ?> <?php echoIcon('focallen', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('focratio')"><?php echo __('focratio') ?> <?php echoIcon('focratio', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('ra')"><?php echo __('ra') ?> <?php echoIcon('ra', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('dec')"><?php echo __('dec') ?> <?php echoIcon('dec', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('centalt')"><?php echo __('centalt') ?> <?php echoIcon('centalt', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('centaz')"><?php echo __('centaz') ?> <?php echoIcon('centaz', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('airmass')"><?php echo __('airmass') ?> <?php echoIcon('airmass', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('pierside')"><?php echo __('pierside') ?> <?php echoIcon('pierside', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('siteelev')"><?php echo __('siteelev') ?> <?php echoIcon('siteelev', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('sitelat')"><?php echo __('sitelat') ?> <?php echoIcon('sitelat', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('sitelong')"><?php echo __('sitelong') ?> <?php echoIcon('sitelong', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('focpos')"><?php echo __('focpos') ?> <?php echoIcon('focpos', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('mtime')"><?php echo __('modification_time') ?> <?php echoIcon('mtime', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('file_hash')"><?php echo __('hash') ?> <?php echoIcon('file_hash', $sortBy, $sortOrder); ?></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($files as $f): ?>
                        <tr data-id="<?= $f['id'] ?>" class="border-b border-gray-700 hover:bg-gray-700">
                <td class="p-3"><input type="checkbox" class="file-checkbox h-4 w-4 text-blue-600 rounded" value="<?= htmlspecialchars($f['path'] ?? '') ?>" data-id="<?= $f['id'] ?>"></td>
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
                <td class="p-3 text-sm text-gray-400"><?= htmlspecialchars(dirname($f['path'] ?? '')) ?></td>
                <td class="p-3">
                    <?php if ($f['thumb']): ?>
                        <img src="data:image/png;base64,<?= base64_encode($f['thumb']) ?>" 
                             alt="Preview" 
                             class="thumb max-w-[150px] h-auto rounded shadow-md object-cover">
                    <?php else: ?>
                        <span class="text-gray-500 text-sm">N/A</span>
                    <?php endif; ?>
                </td>
                <td class="p-3 text-gray-200"><?= htmlspecialchars($f['object'] ?? '') ?></td>
                <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['date_obs'] ?? '') ?></td>
                <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['exptime'] ?? '') ?>s</td>
                <td class="p-3 text-gray-200"><?= htmlspecialchars($f['filter'] ?? '') ?></td>
                <td class="p-3 text-gray-200"><?= htmlspecialchars($f['imgtype'] ?? '') ?></td>
                <?php if ($showAdvanced): ?>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['xbinning'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['ybinning'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['egain'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['offset'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['xpixsz'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['ypixsz'] ?? '') ?></td>
                    <td class="p-3 text-gray-200"><?= htmlspecialchars($f['instrume'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['set_temp'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['ccd_temp'] ?? '') ?></td>
                    <td class="p-3 text-gray-200"><?= htmlspecialchars($f['telescop'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['focallen'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['focratio'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['ra'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['dec'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['centalt'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['centaz'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['airmass'] ?? '') ?></td>
                    <td class="p-3 text-gray-200"><?= htmlspecialchars($f['pierside'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['siteelev'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['sitelat'] ?? '') ?></td>
                                        <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['sitelong'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['focpos'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= !empty($f['mtime']) ? date('Y-m-d H:i:s', (int)$f['mtime']) : '' ?></td>
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
