<?php
// Leggi le preferenze di visualizzazione dai cookie per evitare il flickering
$viewMode = $_COOKIE['viewMode'] ?? 'list';
$thumbSize = $_COOKIE['thumbSize'] ?? '3';
?>
<div class="mb-4 flex flex-wrap justify-end gap-2">
    <button id="exportAstroBinBtn" class="bg-sky-600 hover:bg-sky-700 text-white font-bold py-2 px-4 rounded disabled:opacity-50" disabled>
        <?php echo __('export_astrobin_csv') ?>
    </button>
    <?php if (canDownload()): ?>
    <button id="downloadSelectedBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded disabled:opacity-50" disabled>
        <?php echo __('download_selected') ?>
    </button>
    <?php endif; ?>
    <button id="addToProjectBtn" class="bg-teal-600 hover:bg-teal-700 text-white font-bold py-2 px-4 rounded disabled:opacity-50" disabled>
        <?php echo __('projects_add_btn') ?>
    </button>
</div>

<!-- Add to project modal (2 steps: destination -> preview -> confirm) -->
<?php $projectList = isset($conn) ? getProjects($conn) : []; ?>
<div id="projectModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/60">
    <div class="bg-gray-800 rounded-lg p-6 w-full max-w-2xl max-h-[85vh] overflow-y-auto">
        <h3 class="text-lg font-semibold mb-4"><?php echo __('projects_add_title') ?></h3>

        <div id="projectStep1">
            <label class="block text-sm text-gray-400 mb-3"><?php echo __('projects_select_project') ?>
                <select id="projectSelect" class="mt-1 w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded text-gray-100">
                    <?php foreach ($projectList as $pl): ?>
                        <option value="<?= (int)$pl['id'] ?>"><?= htmlspecialchars($pl['name']) ?></option>
                    <?php endforeach; ?>
                    <option value="0"><?php echo __('projects_add_new_option') ?></option>
                </select>
            </label>
            <div id="newProjectFields" class="hidden flex-col gap-3 mb-3">
                <input type="text" id="newProjectName" maxlength="255"
                       placeholder="<?php echo __('projects_add_new_name') ?>"
                       class="px-3 py-2 bg-gray-700 border border-gray-600 rounded text-gray-100">
                <textarea id="newProjectNotes" rows="2"
                          placeholder="<?php echo __('projects_add_new_notes') ?>"
                          class="px-3 py-2 bg-gray-700 border border-gray-600 rounded text-gray-100"></textarea>
            </div>
            <div id="projectAddMsg" class="hidden mb-4 p-3 rounded text-sm"></div>
            <div class="flex justify-end gap-2">
                <button type="button" id="projectModalCancel" class="px-4 py-2 bg-gray-600 hover:bg-gray-500 text-white rounded-lg"><?php echo __('projects_add_cancel') ?></button>
                <button type="button" id="projectModalAnalyze" class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg"><?php echo __('projects_add_analyze') ?></button>
            </div>
        </div>

        <div id="projectStep2" class="hidden">
            <div id="projectFrozenBanner" class="hidden mb-4 p-3 rounded text-sm bg-red-900/50 border border-red-700 text-red-300"></div>
            <div id="projectPreviewMixed" class="hidden mb-4 p-3 rounded text-sm bg-yellow-900/50 border border-yellow-700 text-yellow-300"></div>
            <div id="projectPreviewGroups" class="flex flex-col gap-4 mb-4"></div>
            <div id="projectPreviewSkipped" class="mb-4 text-sm text-gray-400"></div>
            <div class="flex justify-end gap-2">
                <button type="button" id="projectModalBack" class="px-4 py-2 bg-gray-600 hover:bg-gray-500 text-white rounded-lg"><?php echo __('projects_add_back') ?></button>
                <button type="button" id="projectModalConfirm" class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg"><?php echo __('projects_add_confirm') ?></button>
            </div>
        </div>
    </div>
</div>

<!-- View container -->
<div id="selectable-container" class="view-container thumb-size-<?php echo htmlspecialchars($thumbSize); ?>">
    
<!-- List View -->
<p id="hscroll-hint" class="hidden text-xs text-gray-500 mb-2"><?php echo __('hscroll_hint'); ?></p>
<div class="list-view <?php if ($viewMode !== 'list') echo 'hidden'; ?> bg-gray-800 rounded-lg shadow-lg overflow-x-auto">
    <table class="w-full text-left">
        <thead class="bg-gray-700 text-gray-200">
    <tr>
        <th class="p-3 whitespace-nowrap"><input type="checkbox" id="selectAll" class="form-checkbox h-4 w-4 text-blue-600 rounded"></th>
        <?php renderFileTableHeaders('main'); ?>
    </tr>
</thead>
                <tbody>
            <?php foreach ($files as $f): ?>
                        <tr data-id="<?= $f['id'] ?>" class="selectable-item border-b border-gray-700 hover:bg-gray-700">
                <td class="p-3"><input type="checkbox" class="file-checkbox h-4 w-4 text-blue-600 rounded" value="<?= htmlspecialchars($f['path'] ?? '') ?>" data-id="<?= $f['id'] ?>"></td>
                <?php renderFileTableCells($f, 'main'); ?>
            </tr>
            <?php endforeach; ?>
                        <?php if (empty($files)): ?>
                <tr><td colspan="<?= (int)($tableColspan ?? 12) ?>" class="p-4 text-center text-gray-500"><?php echo __('no_files_found') ?></td></tr>
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

<?php include __DIR__ . '/astrobin_modal.php'; ?>
