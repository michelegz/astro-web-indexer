document.addEventListener('DOMContentLoaded', () => {
    // --- DOM references ---
    const sidebar = document.getElementById('sidebar');
    const selectAllCheckbox = document.getElementById('selectAll');
    const tableBody = document.querySelector('table tbody'); 
    const downloadSelectedBtn = document.getElementById('downloadSelectedBtn');
    const exportAstroBinBtn = document.getElementById('exportAstroBinBtn');
    const addToProjectBtn = document.getElementById('addToProjectBtn');
    const projectModal = document.getElementById('projectModal');
    const projectModalCancel = document.getElementById('projectModalCancel');
    const projectModalConfirm = document.getElementById('projectModalConfirm');
    const projectSelect = document.getElementById('projectSelect');
    const projectAddMsg = document.getElementById('projectAddMsg');
    const filtersForm = document.getElementById('filters-form');

    // --- MULTI-ROW SELECTION LOGIC ---
    const selectableContainer = document.getElementById('selectable-container');
    let lastCheckedCheckbox = null;
    const viewContainer = document.querySelector('.view-container');
    const listView = document.querySelector('.list-view');
    const thumbnailView = document.querySelector('.thumbnail-view');
    const listViewBtn = document.getElementById('list-view-btn');
    const thumbnailViewBtn = document.getElementById('thumbnail-view-btn');
    const thumbnailSizeSlider = document.getElementById('thumbnail-size-slider');

    // --- VIEW MODE & SIZE ---
    function loadViewPreferences() {
        const viewMode = localStorage.getItem('viewMode') || 'list';
        const thumbSize = localStorage.getItem('thumbSize') || '3';
        return { viewMode, thumbSize };
    }

    function saveViewPreferences(viewMode, thumbSize) {
        localStorage.setItem('viewMode', viewMode);
        localStorage.setItem('thumbSize', thumbSize);
        
        // Set cookies to be read by PHP on next page load to prevent flickering
        const expiryDate = new Date();
        expiryDate.setFullYear(expiryDate.getFullYear() + 1); // Expire in 1 year
        document.cookie = `viewMode=${viewMode};path=/;expires=${expiryDate.toUTCString()};samesite=Lax`;
        document.cookie = `thumbSize=${thumbSize};path=/;expires=${expiryDate.toUTCString()};samesite=Lax`;
    }

    function setViewMode(mode) {
        if (mode === 'list') {
            // Make list view visible
            listView.classList.remove('hidden');
            listView.style.display = '';
            // Hide thumbnail view
            thumbnailView.classList.add('hidden');
            thumbnailView.style.display = 'none';
            // Update button styles
            listViewBtn.classList.add('bg-blue-600');
            thumbnailViewBtn.classList.remove('bg-blue-600');
        } else {
            // Hide list view
            listView.classList.add('hidden');
            listView.style.display = 'none';
            // Make thumbnail view visible
            thumbnailView.classList.remove('hidden');
            thumbnailView.style.display = 'grid';
            // Update button styles
            listViewBtn.classList.remove('bg-blue-600');
            thumbnailViewBtn.classList.add('bg-blue-600');
        }
        updateHScrollHint();
    }

    // --- HORIZONTAL SCROLL HINT (desktop only, shown when the table overflows) ---
    // Browsers already scroll horizontally with Shift+wheel; this only toggles
    // the hint. pointer:fine excludes touch devices (they swipe natively).
    const hscrollHint = document.getElementById('hscroll-hint');
    const finePointer = window.matchMedia && window.matchMedia('(pointer: fine)').matches;
    function updateHScrollHint() {
        if (!hscrollHint || typeof listView === 'undefined' || !listView) return;
        const overflows = listView.scrollWidth > listView.clientWidth + 1;
        const visible = !!finePointer && !listView.classList.contains('hidden') && overflows;
        hscrollHint.classList.toggle('hidden', !visible);
    }
    window.addEventListener('resize', updateHScrollHint);

    function setThumbnailSize(size) {
        // Remove all existing size classes
        viewContainer.classList.remove('thumb-size-1', 'thumb-size-2', 'thumb-size-3', 'thumb-size-4', 'thumb-size-5');
        // Add new size class
        viewContainer.classList.add(`thumb-size-${size}`);
        // Update slider
        if (thumbnailSizeSlider) {
            thumbnailSizeSlider.value = size;
        }
        updateHScrollHint();
    }

    // Initialize view preferences
    const { viewMode, thumbSize } = loadViewPreferences();
    setViewMode(viewMode);
    setThumbnailSize(thumbSize);

    // View toggle handlers
    if (listViewBtn) {
        listViewBtn.addEventListener('click', () => {
            setViewMode('list');
            saveViewPreferences('list', thumbnailSizeSlider.value);
        });
    }

    if (thumbnailViewBtn) {
        thumbnailViewBtn.addEventListener('click', () => {
            setViewMode('thumbnail');
            saveViewPreferences('thumbnail', thumbnailSizeSlider.value);
        });
    }

    // Thumbnail size slider
    if (thumbnailSizeSlider) {
        thumbnailSizeSlider.addEventListener('input', () => {
            const size = thumbnailSizeSlider.value;
            setThumbnailSize(size);
        });
        thumbnailSizeSlider.addEventListener('change', () => {
            const size = thumbnailSizeSlider.value;
            saveViewPreferences(
                listView.classList.contains('hidden') ? 'thumbnail' : 'list', 
                size
            );
        });
    }

    // --- FILTRI DATA ---
    const dateObsFrom = document.getElementById('date_obs_from');
    const dateObsTo = document.getElementById('date_obs_to');

    const contentArea = document.getElementById('content-area');

    // --- UTILS ---
    function getFileCheckboxes() {
        return document.querySelectorAll('.file-checkbox');
    }
    function getSelectedFiles() {
        // List and thumbnail views render the same files twice (the hidden
        // view stays in the DOM): dedupe by file id so every downstream flow
        // (download, export, add-to-project) sees each file once.
        const seen = new Set();
        return Array.from(getFileCheckboxes()).filter(cb => {
            if (!cb.checked) return false;
            const id = cb.dataset.id;
            if (seen.has(id)) return false;
            seen.add(id);
            return true;
        });
    }
    function updateButtonStates() {
        const hasSelection = getSelectedFiles().length > 0;
        if (downloadSelectedBtn) downloadSelectedBtn.disabled = !hasSelection;
        if (exportAstroBinBtn) exportAstroBinBtn.disabled = !hasSelection;
        if (addToProjectBtn) addToProjectBtn.disabled = !hasSelection;
    }

    // --- MENU MOBILE ---
    window.toggleMenu = () => {
        const isOpen = sidebar?.classList.toggle('-translate-x-full');
        sidebar?.classList.toggle('translate-x-0');


        if (window.innerWidth >= 768) { // md breakpoint
            if (!isOpen) {
                contentArea?.classList.add('md:ml-60');
            } else {
                contentArea?.classList.remove('md:ml-60');
            }
        }
    };

    // --- PROJECTS PANEL (right side, symmetric to the folders menu) ---
    window.toggleProjectsPanel = () => {
        const panel = document.getElementById('projects-panel');
        const isHidden = panel?.classList.toggle('translate-x-full');

        if (window.innerWidth >= 768) { // md breakpoint
            if (!isHidden) {
                contentArea?.classList.add('md:mr-60');
            } else {
                contentArea?.classList.remove('md:mr-60');
            }
        }
    };

    // --- SELECT ALL CHECKBOX ---
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', () => {
            getFileCheckboxes().forEach(cb => cb.checked = selectAllCheckbox.checked);
            updateButtonStates();
        });
    }

    // --- FILE CHECKBOXES (delegation with SHIFT multi-select logic) ---
    if (selectableContainer) {
        selectableContainer.addEventListener('click', (e) => {
            const targetElement = e.target;
            
            // We only care about clicks on the checkbox itself for shift-select
            if (!targetElement.classList.contains('file-checkbox')) {
                // If the click is on other parts of the item, let other event handlers manage it
                // or just let it bubble. For now, we do nothing to keep it simple.
                return;
            }

            const checkbox = targetElement;
            const allCheckboxes = Array.from(selectableContainer.querySelectorAll('.file-checkbox'));

            // If SHIFT key is pressed and there was a previous checkbox clicked
            if (e.shiftKey && lastCheckedCheckbox) {
                const start = allCheckboxes.indexOf(lastCheckedCheckbox);
                const end = allCheckboxes.indexOf(checkbox);
                const range = [start, end].sort((a, b) => a - b);
                
                // The behavior should be to set the state of the range to the state of the clicked checkbox
                const shouldBeChecked = checkbox.checked;

                // Check/uncheck all checkboxes within the range
                for (let i = range[0]; i <= range[1]; i++) {
                    allCheckboxes[i].checked = shouldBeChecked;
                }
            }

            lastCheckedCheckbox = checkbox; // Update the last checked checkbox

            // --- Update UI state after any click on a checkbox ---
            updateButtonStates();
            if (selectAllCheckbox) {
                const allSelected = allCheckboxes.length > 0 && allCheckboxes.every(cb => cb.checked);
                const someSelected = allCheckboxes.some(cb => cb.checked);
                selectAllCheckbox.checked = allSelected;
                selectAllCheckbox.indeterminate = someSelected && !allSelected;
            }
        });
    }

    // Event delegation for thumbnail view checkboxes
    if (thumbnailView) {
        thumbnailView.addEventListener('change', (event) => {
            if (event.target.classList.contains('file-checkbox')) {
                const fileCheckboxes = getFileCheckboxes();
                if (!event.target.checked && selectAllCheckbox) {
                    selectAllCheckbox.checked = false;
                } else if (selectAllCheckbox) {
                    selectAllCheckbox.checked = Array.from(fileCheckboxes).every(cb => cb.checked);
                }
                updateButtonStates();
            }
        });
    }

    // Ensure duplicate badges work in thumbnail view
    if (thumbnailView) {
        thumbnailView.addEventListener('click', (event) => {
            const badge = event.target.closest('.duplicate-badge');
            if (!badge) return;
            
            const hash = badge.dataset.hash;
            const cardElement = badge.closest('.thumb-card');
            if (!hash || !cardElement) return;
            
            // Find the download link to extract the file path
            const downloadLink = cardElement.querySelector('a[href*="/fits/"]');
            if (!downloadLink) return;
            
            const referencePath = decodeURIComponent(downloadLink.getAttribute('href').split('/fits/')[1]);
            
            // Now we have hash and referencePath, we can trigger the same behavior as in the duplicate badge click handler
            const modal = document.getElementById('duplicatesModal');
            const container = document.getElementById('duplicatesContainer');
            
            if (!modal || !container) return;
            
            container.innerHTML = `<p class="text-center p-4">${window.i18n?.loading || 'Loading...'}</p>`;
            modal.classList.remove('hidden');
            
            fetch(`/api/get_duplicates.php?hash=${hash}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) throw new Error(data.error);
                    // The renderDuplicatesTable function is defined in the script in table.php
                    // It will be called from event delegation in document.body
                    // We're just opening the modal here
                })
                .catch(error => {
                    container.innerHTML = `<p class="text-red-500 p-4">${window.i18n?.error_fetching_duplicates || 'Error fetching duplicates'}: ${error.message}</p>`;
                });
        });
    }

    // --- DOWNLOAD SELECTED (POST con form) ---
    if (downloadSelectedBtn) {
        downloadSelectedBtn.addEventListener('click', () => {
            const selectedFiles = getSelectedFiles().map(cb => cb.value);
            if (selectedFiles.length === 0) {
                alert(window.i18n?.no_files_selected || 'Please select at least one file to download.');
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'download.php';
            
            selectedFiles.forEach(path => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'files[]';
                input.value = path;
                form.appendChild(input);
            });
            
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);

            // Reset UI
            if (selectAllCheckbox) selectAllCheckbox.checked = false;
            getFileCheckboxes().forEach(cb => cb.checked = false);
            updateButtonStates();
        });
    }

    // --- ADD TO PROJECT (2 steps: destination -> preview -> confirm) ---
    let projectPreview = null; // {ids, projectId, newProject, groups, skipped, setups, panels}
    let projectModalIds = null; // explicit id list override (SFF modal); null = main table selection
    const projectStep1 = document.getElementById('projectStep1');
    const projectStep2 = document.getElementById('projectStep2');
    const projectStep3 = document.getElementById('projectStep3');
    const projectStep4 = document.getElementById('projectStep4');
    const projectDoneMsg = document.getElementById('projectDoneMsg');
    const projectGotoBtn = document.getElementById('projectGotoBtn');
    const projectModalClose = document.getElementById('projectModalClose');
    const projectModalReviewTree = document.getElementById('projectModalReviewTree');
    const projectModalBack2 = document.getElementById('projectModalBack2');
    const projectTreePreview = document.getElementById('projectTreePreview');
    const projectTreeSkipped = document.getElementById('projectTreeSkipped');
    const projectModalAnalyze = document.getElementById('projectModalAnalyze');
    const projectModalBack = document.getElementById('projectModalBack');
    const newProjectFields = document.getElementById('newProjectFields');
    const newProjectName = document.getElementById('newProjectName');
    const newProjectNotes = document.getElementById('newProjectNotes');
    const previewMixed = document.getElementById('projectPreviewMixed');
    const previewGroups = document.getElementById('projectPreviewGroups');
    const previewSkipped = document.getElementById('projectPreviewSkipped');
    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s ?? '';
        return d.innerHTML;
    }
    // escHtml escapes & < > only: textContent -> innerHTML leaves quotes intact,
    // so it must never be interpolated into an attribute value.
    function escAttr(s) {
        return escHtml(s).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function showProjectStep(n) {
        if (!projectStep1 || !projectStep2) return;
        projectStep1.classList.toggle('hidden', n !== 1);
        projectStep2.classList.toggle('hidden', n !== 2);
        if (projectStep3) projectStep3.classList.toggle('hidden', n !== 3);
        if (projectStep4) projectStep4.classList.toggle('hidden', n !== 4);
    }
    function setProjectMsg(text, ok) {
        if (!projectAddMsg) return;
        projectAddMsg.textContent = text;
        projectAddMsg.classList.remove('hidden');
        projectAddMsg.className = ok
            ? 'mb-4 p-3 rounded text-sm bg-green-900/50 border border-green-700 text-green-300'
            : 'mb-4 p-3 rounded text-sm bg-red-900/50 border border-red-700 text-red-300';
    }
    function openProjectModal(ids = null) {
        if (!projectModal) return;
        // Optional explicit id list (e.g. from the SFF modal); otherwise the
        // main table selection is used at analyze time.
        projectModalIds = Array.isArray(ids)
            ? ids.map(id => parseInt(id, 10)).filter(id => Number.isInteger(id) && id > 0)
            : null;
        if (projectAddMsg) {
            projectAddMsg.classList.add('hidden');
            projectAddMsg.textContent = '';
        }
        showProjectStep(1);
        projectPreview = null;
        syncNewProjectFields();
        projectModal.classList.remove('hidden');
        projectModal.classList.add('flex');
    }
    // Entry point for other UI surfaces (SFF modal) reusing this same flow.
    window.awiOpenProjectModal = openProjectModal;
    function closeProjectModal() {
        if (!projectModal) return;
        projectModal.classList.add('hidden');
        projectModal.classList.remove('flex');
    }
    function selectedFileIds() {
        return getSelectedFiles()
            .map(cb => parseInt(cb.dataset.id, 10))
            .filter(id => Number.isInteger(id) && id > 0);
    }
    function projectChoice() {
        const pid = projectSelect ? parseInt(projectSelect.value, 10) : 0;
        if (pid > 0) return { projectId: pid, newProject: null };
        const name = newProjectName ? newProjectName.value.trim() : '';
        return { projectId: 0, newProject: { name, notes: newProjectNotes ? newProjectNotes.value.trim() : '' } };
    }
    function renderPreview(data) {
        const t = window.i18n || {};
        const frozenBanner = document.getElementById('projectFrozenBanner');
        if (data.frozen && frozenBanner) {
            frozenBanner.textContent = t.project_add_frozen || 'Project is frozen.';
            frozenBanner.classList.remove('hidden');
            if (projectModalConfirm) projectModalConfirm.classList.add('hidden');
        } else {
            if (frozenBanner) frozenBanner.classList.add('hidden');
            if (projectModalConfirm) projectModalConfirm.classList.remove('hidden');
        }
        if (data.groups.length > 1 && previewMixed) {
            previewMixed.textContent = (t.project_add_mixed || 'Mixed selection: {count}').replace('{count}', data.groups.length);
            previewMixed.classList.remove('hidden');
        } else if (previewMixed) {
            previewMixed.classList.add('hidden');
        }
            const setupById = {};
            (data.setups || []).forEach(s => { setupById[s.id] = s; });
            const setupDisplay = (s) => (s.no !== null && s.no !== undefined ? 'S' + s.no + ': ' : '') + s.label;
            const groupTitle = (g) => {
                const m = setupById[g.setup_id];
                return g.setup_new
                    ? (t.project_add_setup_new || 'New setup') + ' — ' + (g.setup_label || g.fp.slice(0, 48))
                    : (m ? setupDisplay(m) : (g.setup_label || g.fp.slice(0, 48)));
            };
            let html = '';
            data.groups.forEach((g, gi) => {
                const matched = setupById[g.setup_id];
                const setupTitle = escHtml(groupTitle(g));
                const setupFp = !g.setup_new && matched && matched.fingerprint
                    ? `<div class="text-xs font-mono text-gray-500 mt-0.5">${escHtml(String(matched.fingerprint).split('|').join(' | '))}</div>` : '';
                html += `<div class="border border-gray-700 rounded p-3"><div class="font-medium mb-1">⧉${gi + 1} ${setupTitle} <span class="text-xs text-gray-400">(${g.files.length})</span>${setupFp}</div>`;
            // Override: only *other* setups are offered (the matched one would be a no-op duplicate),
            // plus creating a brand-new custom setup (e.g. two identical rigs to keep separate).
            // Always shown: even with no other setups, a custom one can be created.
            {
                const others = (data.setups || []).filter(s => s.id !== g.setup_id);
                html += `<label class="block text-xs text-gray-400 mb-1">${escHtml(t.project_add_force_setup || 'Force into setup:')} <select data-group="${gi}" class="override-select mt-1 px-2 py-1 bg-gray-700 border border-gray-600 rounded text-gray-100 text-xs"><option value="0">${escHtml(t.project_add_use_matched || 'As matched')}</option>`;
                others.forEach(s => {
                    html += `<option value="${s.id}">${escHtml(setupDisplay(s))}</option>`;
                });
                // Merge into another batch group: one-shot, resolved server-side
                // by fingerprint (never persisted to setup_overrides).
                data.groups.forEach((g2, gj) => {
                    if (gj === gi) return;
                    html += `<option value="groupfp:${escAttr(g2.fp)}">⤵ ${gj + 1} ${escHtml(groupTitle(g2))}</option>`;
                });
                html += `<option value="new">${escHtml(t.project_add_new_setup || '＋ New custom setup…')}</option>`;
                html += `</select> <span class="merge-hint text-sky-300/80" data-mergehint="${gi}"></span></label><input type="text" data-groupname="${gi}" maxlength="64" placeholder="${escAttr(t.project_add_new_setup_name || 'Custom setup name')}" class="custom-setup-name hidden mt-1 mb-2 w-full px-2 py-1 bg-gray-700 border border-gray-600 rounded text-gray-100 text-xs">`;
            }
            // File list without placement details (step 3 shows where they
            // land): collapsible, header summarizes counts per subframe type.
            const typeCounts = {};
            (g.files || []).forEach(f => {
                const k = ((f.imgtype || '?')[0] || '?').toUpperCase();
                typeCounts[k] = (typeCounts[k] || 0) + 1;
            });
            const typeSummary = Object.keys(typeCounts).sort()
                .map(k => `${k}:${typeCounts[k]}`).join(' ');
            html += `<details class="mt-1"><summary class="cursor-pointer text-xs text-gray-400">${escHtml(typeSummary)}</summary>`;
            html += '<ul class="text-xs text-gray-300 flex flex-col gap-1 mt-1">';
            g.files.forEach(f => {
                // Already linked elsewhere: still added, but flagged (⧉×n).
                let dup = '';
                if (f.dup && f.dup.length) {
                    // f.dup carries raw FITS header text (FILTER/OBJECT), so this is an
                    // attribute sink: a quote there would close title= and inject a handler.
                    const tip = escAttr((t.projects_dup_levels || 'Linked in') + ': ' + f.dup.join(', '));
                    dup = ` <span title="${tip}">⧉×${f.dup.length}</span>`;
                }
                const meta = [f.filter || '', f.night || ''].filter(Boolean).join(' · ');
                html += `<li><span class="font-medium">${escHtml(f.name)}</span> <span class="text-gray-500">${escHtml(meta)}</span>${dup}</li>`;
            });
            html += '</ul></details></div>';
        });
        if (previewGroups) previewGroups.innerHTML = html;
        if (previewSkipped) {
            previewSkipped.textContent = (data.skipped || []).map(s => `${s.name}`).join(', ');
            previewSkipped.style.display = (data.skipped || []).length ? '' : 'none';
        }
        refreshMergeHints();
        showProjectStep(2);
    }
    if (addToProjectBtn) {
        addToProjectBtn.addEventListener('click', () => {
            if (getSelectedFiles().length === 0) return;
            openProjectModal();
        });
    }
    if (projectModalCancel) {
        projectModalCancel.addEventListener('click', closeProjectModal);
    }
    if (projectModalClose) {
        projectModalClose.addEventListener('click', closeProjectModal);
    }
    if (projectModal) {
        projectModal.addEventListener('click', (e) => {
            if (e.target === projectModal) closeProjectModal();
        });
    }
    function syncNewProjectFields() {
        if (!projectSelect || !newProjectFields) return;
        // With zero existing projects the select is already on "new": sync
        // on open too, not only on change (otherwise the name fields stay
        // hidden and creation looks broken).
        const isNew = parseInt(projectSelect.value, 10) === 0;
        newProjectFields.classList.toggle('hidden', !isNew);
        newProjectFields.classList.toggle('flex', isNew);
    }
    if (projectSelect && newProjectFields) {
        projectSelect.addEventListener('change', syncNewProjectFields);
    }
    if (projectModalBack) {
        projectModalBack.addEventListener('click', () => showProjectStep(1));
    }
    if (projectModalAnalyze) {
        projectModalAnalyze.addEventListener('click', () => {
            const ids = projectModalIds ?? selectedFileIds();
            if (ids.length === 0) return;
            const choice = projectChoice();
            if (choice.projectId === 0 && !choice.newProject.name) {
                if (newProjectName) newProjectName.focus();
                return;
            }
            projectModalAnalyze.disabled = true;
            if (!projectModalAnalyze.dataset.label) projectModalAnalyze.dataset.label = projectModalAnalyze.textContent;
            projectModalAnalyze.textContent = (window.i18n || {}).project_add_analyzing || 'Analyzing…';
            fetch('/api/project_preview.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ project_id: choice.projectId, ids }),
            })
                .then(r => r.json())
                .then(data => {
                    if (data.error) throw new Error(data.error);
                    projectPreview = { ids, ...choice, groups: data.groups || [], skipped: data.skipped || [], setups: data.setups || [], panels: data.panels || {} };
                    renderPreview(projectPreview);
                })
                .catch(err => setProjectMsg(err.message, false))
                .finally(() => {
                    projectModalAnalyze.disabled = false;
                    projectModalAnalyze.textContent = document.getElementById('projectModalAnalyze')?.dataset.label || 'Analyze';
                });
        });
    }
    function refreshMergeHints() {
        if (!projectPreview || !previewGroups) return;
        const t = window.i18n || {};
        const setupById = {};
        (projectPreview.setups || []).forEach(s => { setupById[s.id] = s; });
        const titleOf = (g) => {
            const m = setupById[g.setup_id];
            return g.setup_new
                ? (t.project_add_setup_new || 'New setup') + ' — ' + (g.setup_label || g.fp.slice(0, 48))
                : (m ? ((m.no !== null && m.no !== undefined ? 'S' + m.no + ': ' : '') + m.label) : (g.setup_label || g.fp.slice(0, 48)));
        };
        previewGroups.querySelectorAll('.override-select').forEach(sel => {
            const hint = previewGroups.querySelector(`[data-mergehint="${sel.dataset.group}"]`);
            if (!hint) return;
            let text = '';
            if (sel.value.startsWith('groupfp:')) {
                const fp = sel.value.slice(8);
                const tj = (projectPreview.groups || []).findIndex(g => g.fp === fp);
                if (tj >= 0) text = '→ ⧉' + (tj + 1) + ' ' + titleOf(projectPreview.groups[tj]);
            }
            hint.textContent = text;
        });
    }
    if (previewGroups) {
        previewGroups.addEventListener('change', (e) => {
            if (!e.target.classList.contains('override-select')) return;
            const gi = e.target.dataset.group;
            const nameInput = previewGroups.querySelector(`.custom-setup-name[data-groupname="${gi}"]`);
            if (nameInput) {
                nameInput.classList.toggle('hidden', e.target.value !== 'new');
                if (e.target.value === 'new') nameInput.focus();
            }
            refreshMergeHints();
        });
    }
    function collectProjectOverrides() {
        const overrides = {};
        let missingName = false;
        document.querySelectorAll('.override-select').forEach(sel => {
            const gi = parseInt(sel.dataset.group, 10);
            const g = projectPreview.groups[gi];
            if (!g) return;
            if (sel.value === 'new') {
                const nameInput = document.querySelector(`.custom-setup-name[data-groupname="${gi}"]`);
                const name = (nameInput?.value || '').trim();
                if (!name) {
                    missingName = true;
                    if (nameInput) nameInput.focus();
                    return;
                }
                (g.files || []).forEach(f => { overrides[f.id] = 'new:' + name; });
            } else if (sel.value.startsWith('groupfp:')) {
                (g.files || []).forEach(f => { overrides[f.id] = sel.value; });
            } else {
                const sid = parseInt(sel.value, 10);
                if (sid > 0) {
                    (g.files || []).forEach(f => { overrides[f.id] = sid; });
                }
            }
        });
        return { overrides, missingName };
    }
    function projectTreePayload() {
        const { overrides, missingName } = collectProjectOverrides();
        if (missingName) return null;
        const payload = { project_id: projectPreview.projectId, ids: projectPreview.ids, overrides };
        if (projectPreview.projectId === 0 && projectPreview.newProject) {
            payload.new_project = projectPreview.newProject;
        }
        return payload;
    }
    if (projectModalReviewTree) {
        projectModalReviewTree.addEventListener('click', () => {
            if (!projectPreview) return;
            const payload = projectTreePayload();
            if (!payload) return;
            projectModalReviewTree.disabled = true;
            fetch('/api/project_tree_preview.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            })
                .then(r => r.json())
                .then(data => {
                    if (data.error) throw new Error(data.error);
                    if (data.frozen) throw new Error((window.i18n || {}).project_add_frozen || 'Project is frozen.');
                    if (projectTreePreview) projectTreePreview.innerHTML = data.html || '';
                    if (projectTreeSkipped) {
                        const skipped = (data.skipped || []).map(s => `${s.name} (${s.message})`).join(', ');
                        projectTreeSkipped.textContent = skipped;
                        projectTreeSkipped.style.display = skipped ? '' : 'none';
                    }
                    showProjectStep(3);
                })
                .catch(err => {
                    if (projectTreePreview) projectTreePreview.innerHTML = `<p class="text-red-400">Error: ${escHtml(err.message)}</p>`;
                    showProjectStep(3);
                })
                .finally(() => {
                    projectModalReviewTree.disabled = false;
                });
        });
    }
    if (projectModalBack2) {
        projectModalBack2.addEventListener('click', () => showProjectStep(2));
    }
    if (projectModalConfirm) {
        projectModalConfirm.addEventListener('click', () => {
            if (!projectPreview) return;
            const { overrides, missingName } = collectProjectOverrides();
            if (missingName) return;
            const payload = { project_id: projectPreview.projectId, ids: projectPreview.ids, overrides };
            if (projectPreview.projectId === 0 && projectPreview.newProject) {
                payload.new_project = projectPreview.newProject;
            }
            projectModalConfirm.disabled = true;
            fetch('/api/project_add.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            })
                .then(r => r.json())
                .then(data => {
                    if (data.error) throw new Error(data.error);
                    const skipped = (data.skipped || []).map(s => `${s.name} (${s.message})`).join('\n');
                    let msg = data.message + (skipped ? `\n\nSkipped:\n${skipped}` : '');
                    if (data.project_id && projectSelect && !Array.from(projectSelect.options).some(o => parseInt(o.value, 10) === data.project_id)) {
                        const opt = document.createElement('option');
                        opt.value = data.project_id;
                        opt.textContent = projectPreview.newProject?.name || ('#' + data.project_id);
                        projectSelect.appendChild(opt);
                    }
                    if (projectDoneMsg) projectDoneMsg.textContent = msg;
                    if (projectGotoBtn && data.project_id) projectGotoBtn.href = '/projects.php?id=' + data.project_id;
                    showProjectStep(4);
                    projectPreview = null;
                })
                .catch(err => setProjectMsg(err.message, false))
                .finally(() => {
                    projectModalConfirm.disabled = false;
                });
        });
    }

    // --- EXPORT ASTROBIN (shared logic in astrobin_export.js) ---
    if (exportAstroBinBtn) {
        exportAstroBinBtn.addEventListener('click', () => {
            const selectedIds = getSelectedFiles().map(cb => cb.dataset.id);
            if (selectedIds.length === 0) return;
            if (typeof window.awiExportAstroBin === 'function') {
                window.awiExportAstroBin(selectedIds, exportAstroBinBtn);
            }
        });
    }

    // --- SORTING TABLE ---
    window.sortTable = (column) => {
        const urlParams = new URLSearchParams(window.location.search);
        const currentSortBy = urlParams.get('sort_by') || 'name';
        const currentSortOrder = urlParams.get('sort_order') || 'ASC';

        let newSortOrder = 'ASC';
        if (currentSortBy === column) {
            newSortOrder = (currentSortOrder === 'ASC' ? 'DESC' : 'ASC');
        }

        urlParams.set('sort_by', column);
        urlParams.set('sort_order', newSortOrder);
        urlParams.set('page', '1');
        window.location.search = urlParams.toString();
    };

    // --- FILTRI ---
    if (filtersForm) {
        // NOTE: column-chooser checkboxes (data-col, data-group-toggle) are staged
        // and applied via the Applica button — they must NOT auto-submit the form.
        const filters = filtersForm.querySelectorAll('select, input[type="checkbox"]:not([data-col]):not([data-group-toggle])');
        filters.forEach(filter => {
            filter.addEventListener('change', () => {
                filtersForm.submit();
            });
        });
        
        // Gestione filtri data con logica di sincronizzazione
        if (dateObsFrom && dateObsTo) {
            dateObsFrom.addEventListener('change', () => {
                if (dateObsFrom.value && dateObsTo.value && dateObsFrom.value > dateObsTo.value) {
                    dateObsTo.value = dateObsFrom.value;
                }
                filtersForm.submit();
            });
            
            dateObsTo.addEventListener('change', () => {
                if (dateObsFrom.value && dateObsTo.value && dateObsTo.value < dateObsFrom.value) {
                    dateObsFrom.value = dateObsTo.value;
                }
                filtersForm.submit();
            });
        }

        // Gestione filtro tempo di esposizione con logica di sincronizzazione
        const exptimeMin = document.getElementById('exptime_min');
        const exptimeMax = document.getElementById('exptime_max');
        if (exptimeMin && exptimeMax) {
            exptimeMin.addEventListener('change', () => {
                if (exptimeMin.value && exptimeMax.value && parseFloat(exptimeMin.value) > parseFloat(exptimeMax.value)) {
                    exptimeMax.value = exptimeMin.value;
                }
                filtersForm.submit();
            });

            exptimeMax.addEventListener('change', () => {
                if (exptimeMin.value && exptimeMax.value && parseFloat(exptimeMax.value) < parseFloat(exptimeMin.value)) {
                    exptimeMin.value = exptimeMax.value;
                }
                filtersForm.submit();
            });
        }
    }

    // --- CONVERSIONE DATE UTC -> LOCAL ---
    function convertUTCDatesToLocal() {
        const dateElements = document.querySelectorAll('.utc-date');
        dateElements.forEach(el => {
            const timestamp = el.getAttribute('data-timestamp');
            if (timestamp && !isNaN(timestamp)) {
                const date = new Date(timestamp * 1000);
                const options = {
                    year: 'numeric', month: 'numeric', day: 'numeric',
                    hour: 'numeric', minute: 'numeric', second: 'numeric',
                    hour12: false
                };
                try {
                    el.textContent = date.toLocaleString(undefined, options);
                } catch {
                    el.textContent = date.toLocaleString();
                }
            }
        });
    }
    convertUTCDatesToLocal();

    // --- Stato iniziale bottoni ---
    updateButtonStates();

  // --- In-place Thumbnail Crop Preview ---
const containers = document.querySelectorAll('.list-view, .thumbnail-view');

if (containers.length > 0) {

    function toggleCrop(container, show) {
        const viewport = container.querySelector('.thumb-crop-viewport');
        if (viewport) {
            viewport.style.opacity = show ? '1' : '0';
        }
    }

    // --- Desktop hover ---
    containers.forEach(container => {
        container.addEventListener('mouseover', e => {
            const wrapper = e.target.closest('.thumb-wrapper');
            if (wrapper) toggleCrop(wrapper, true);
        });

        container.addEventListener('mouseout', e => {
            const wrapper = e.target.closest('.thumb-wrapper');
            if (wrapper) toggleCrop(wrapper, false);
        });
    });

    // --- Mobile tap ---
    let activeThumbWrapper = null;
    document.body.addEventListener('click', e => {
        const wrapper = e.target.closest('.thumb-wrapper');

        // Clicked on a thumb wrapper
        if (wrapper && (wrapper.closest('.list-view') || wrapper.closest('.thumbnail-view'))) {
            e.stopPropagation(); // Prevent bubbling

            // If it's already active, deactivate it
            if (wrapper === activeThumbWrapper) {
                toggleCrop(wrapper, false);
                activeThumbWrapper = null;
            } else {
                // If another was active, deactivate it first
                if (activeThumbWrapper) {
                    toggleCrop(activeThumbWrapper, false);
                }
                // Activate the new one
                toggleCrop(wrapper, true);
                activeThumbWrapper = wrapper;
            }
        } else {
            // Clicked outside any thumb wrapper
            if (activeThumbWrapper) {
                toggleCrop(activeThumbWrapper, false);
                activeThumbWrapper = null;
            }
        }
    });
}

});
// --- DYNAMIC FOLDER TREE LOGIC ---
    const folderTree = document.getElementById('folder-tree');
    if (folderTree) {
        folderTree.addEventListener('click', e => {
            const toggle = e.target.closest('.folder-toggle');
            if (!toggle) return;

            e.preventDefault();
            const folderItem = toggle.closest('.folder-item');
            const subfoldersDiv = folderItem.nextElementSibling;

            if (!subfoldersDiv || !subfoldersDiv.classList.contains('subfolders')) return;

            const isOpening = subfoldersDiv.classList.contains('hidden');

            // --- Accordion Logic ---
            if (isOpening) {
                const parentContainer = folderItem.parentElement;
                // Find all sibling folder items at the same level
                const siblingItems = parentContainer.querySelectorAll(':scope > .folder-item');

                // Close all other subfolders at this level
                siblingItems.forEach(sibling => {
                    if (sibling !== folderItem) {
                        const siblingSubfolders = sibling.nextElementSibling;
                        if (siblingSubfolders && siblingSubfolders.classList.contains('subfolders')) {
                            siblingSubfolders.classList.add('hidden');
                        }
                    }
                });
            }
            // --- End Accordion Logic ---

            // Finally, toggle the current subfolder div
            subfoldersDiv.classList.toggle('hidden');
        });
    }

//}); // NOTE: The final brackets are commented out as we are replacing a block of code.

