document.addEventListener('DOMContentLoaded', () => {
    // --- DOM references ---
    const sidebar = document.getElementById('sidebar');
    const menuOverlay = document.getElementById('menu-overlay');
    const selectAllCheckbox = document.getElementById('selectAll');
    const tableBody = document.querySelector('table tbody'); 
    const downloadSelectedBtn = document.getElementById('downloadSelectedBtn');
    const exportAstroBinBtn = document.getElementById('exportAstroBinBtn');
    const filtersForm = document.getElementById('filters-form');
    const viewContainer = document.querySelector('.view-container');
    const listView = document.querySelector('.list-view');
    const thumbnailView = document.querySelector('.thumbnail-view');
    const listViewBtn = document.getElementById('list-view-btn');
    const thumbnailViewBtn = document.getElementById('thumbnail-view-btn');
    const thumbnailSizeSlider = document.getElementById('thumbnail-size-slider');

    // --- MODAL ASTROBIN ---
    const astrobinModal = document.getElementById('astrobinModal');
    const closeAstrobinModalBtn = document.getElementById('closeAstrobinModalBtn');
    const astrobinCsvText = document.getElementById('astrobinCsvText');
    const copyAstrobinCsvBtn = document.getElementById('copyAstrobinCsvBtn');

    // --- VIEW MODE & SIZE ---
    function loadViewPreferences() {
        const viewMode = localStorage.getItem('viewMode') || 'list';
        const thumbSize = localStorage.getItem('thumbSize') || '3';
        return { viewMode, thumbSize };
    }

    function saveViewPreferences(viewMode, thumbSize) {
        localStorage.setItem('viewMode', viewMode);
        localStorage.setItem('thumbSize', thumbSize);
    }

    function setViewMode(mode) {
        if (mode === 'list') {
            listView.classList.remove('hidden');
            thumbnailView.classList.add('hidden');
            listViewBtn.classList.add('bg-blue-600');
            thumbnailViewBtn.classList.remove('bg-blue-600');
        } else {
            listView.classList.add('hidden');
            thumbnailView.classList.remove('hidden');
            listViewBtn.classList.remove('bg-blue-600');
            thumbnailViewBtn.classList.add('bg-blue-600');
        }
    }

    function setThumbnailSize(size) {
        // Remove all existing size classes
        viewContainer.classList.remove('thumb-size-1', 'thumb-size-2', 'thumb-size-3', 'thumb-size-4', 'thumb-size-5');
        // Add new size class
        viewContainer.classList.add(`thumb-size-${size}`);
        // Update slider
        if (thumbnailSizeSlider) {
            thumbnailSizeSlider.value = size;
        }
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

    // --- UTILS ---
    function getFileCheckboxes() {
        return document.querySelectorAll('.file-checkbox');
    }
    function getSelectedFiles() {
        return Array.from(getFileCheckboxes()).filter(cb => cb.checked);
    }
    function updateButtonStates() {
        const hasSelection = getSelectedFiles().length > 0;
        if (downloadSelectedBtn) downloadSelectedBtn.disabled = !hasSelection;
        if (exportAstroBinBtn) exportAstroBinBtn.disabled = !hasSelection;
    }

    // --- MENU MOBILE ---
    window.toggleMenu = () => {
        sidebar?.classList.toggle('-translate-x-full');
        sidebar?.classList.toggle('translate-x-0');
        menuOverlay?.classList.toggle('hidden');
    };
    if (menuOverlay) {
        menuOverlay.addEventListener('click', toggleMenu);
    }

    // --- SELECT ALL CHECKBOX ---
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', () => {
            getFileCheckboxes().forEach(cb => cb.checked = selectAllCheckbox.checked);
            updateButtonStates();
        });
    }

    // --- FILE CHECKBOXES (delegation) ---
    if (tableBody) {
        tableBody.addEventListener('change', (event) => {
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

    // --- EXPORT ASTROBIN ---
    if (exportAstroBinBtn) {
        exportAstroBinBtn.addEventListener('click', () => {
            const selectedIds = getSelectedFiles().map(cb => cb.dataset.id);
            if (selectedIds.length === 0) return;

            const idsQueryString = selectedIds.join(',');
            const exportUrl = `/api/export_astrobin_csv.php?ids=${idsQueryString}`;

            // Show loading indicator
            const originalText = exportAstroBinBtn.innerHTML;
            exportAstroBinBtn.innerHTML = window.i18n?.loading || 'Loading...';
            exportAstroBinBtn.disabled = true;

            fetch(exportUrl)
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok.');
                    return response.text();
                })
                .then(csvText => {
                    if (astrobinCsvText) astrobinCsvText.value = csvText;
                    if (astrobinModal) astrobinModal.classList.remove('hidden');
                })
                .catch(error => {
                    alert((window.i18n?.error_fetching_csv_data || 'Error fetching CSV data:') + ' ' + error.message);
                })
                .finally(() => {
                    // Restore button state
                    exportAstroBinBtn.innerHTML = originalText;
                    exportAstroBinBtn.disabled = false;
                });
        });
    }

    // --- ASTROBIN MODAL ACTIONS ---
    if (closeAstrobinModalBtn && astrobinModal) {
        closeAstrobinModalBtn.addEventListener('click', () => astrobinModal.classList.add('hidden'));
    }
    if (astrobinModal) {
        astrobinModal.addEventListener('click', (e) => {
            if (e.target === astrobinModal) {
                astrobinModal.classList.add('hidden');
            }
        });
    }
    if (copyAstrobinCsvBtn && astrobinCsvText) {
        copyAstrobinCsvBtn.addEventListener('click', () => {
            // Prima controlla se l'API della clipboard è disponibile
            if (!navigator.clipboard) {
                alert((window.i18n?.copy_to_clipboard_failed || 'Failed to copy to clipboard.') + '\n' + 'This feature is only available on secure (HTTPS) sites.');
                astrobinCsvText.select(); // Seleziona il testo per la copia manuale
                return; // Interrompi l'esecuzione
            }

            navigator.clipboard.writeText(astrobinCsvText.value).then(() => {
                const originalText = copyAstrobinCsvBtn.innerHTML;
                copyAstrobinCsvBtn.innerHTML = window.i18n?.copied || 'Copied!';
                copyAstrobinCsvBtn.classList.add('bg-green-600');
                copyAstrobinCsvBtn.classList.remove('bg-blue-600');
                
                setTimeout(() => {
                    copyAstrobinCsvBtn.innerHTML = originalText;
                    copyAstrobinCsvBtn.classList.remove('bg-green-600');
                    copyAstrobinCsvBtn.classList.add('bg-blue-600');
                }, 2000);
            }).catch(err => {
                alert((window.i18n?.copy_to_clipboard_failed || 'Failed to copy to clipboard.') + '\n' + (window.i18n?.astrobin_modal_explanation || 'Please copy the text manually from the text area.'));
                astrobinCsvText.select(); // Seleziona il testo per la copia manuale
            });
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
        const filters = filtersForm.querySelectorAll('select, input[type="checkbox"]');
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
});
