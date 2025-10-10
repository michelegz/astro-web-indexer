document.addEventListener('DOMContentLoaded', () => {
    // --- DOM references ---
    const sidebar = document.getElementById('sidebar');
    const selectAllCheckbox = document.getElementById('selectAll');
    const tableBody = document.querySelector('table tbody'); 
    const downloadSelectedBtn = document.getElementById('downloadSelectedBtn');
    const exportAstroBinBtn = document.getElementById('exportAstroBinBtn');
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

    const contentArea = document.getElementById('content-area');

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

