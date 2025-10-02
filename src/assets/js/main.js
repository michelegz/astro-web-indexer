document.addEventListener('DOMContentLoaded', () => {
    // --- DOM references ---
    const sidebar = document.getElementById('sidebar');
    const menuOverlay = document.getElementById('menu-overlay');
    const selectAllCheckbox = document.getElementById('selectAll');
    const tableBody = document.querySelector('table tbody'); 
    const downloadSelectedBtn = document.getElementById('downloadSelectedBtn');
    const exportAstroBinBtn = document.getElementById('exportAstroBinBtn');
    const filtersForm = document.getElementById('filters-form');

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

            fetch(exportUrl)
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok.');
                    return response.text();
                })
                .then(csvText => {
                    navigator.clipboard.writeText(csvText).then(() => {
                        const originalText = exportAstroBinBtn.innerHTML;
                        exportAstroBinBtn.innerHTML = window.i18n?.copied || 'Copied!';
                        exportAstroBinBtn.classList.add('bg-green-600', 'hover:bg-green-700');
                        exportAstroBinBtn.classList.remove('bg-sky-600', 'hover:bg-sky-700');
                        
                        setTimeout(() => {
                            exportAstroBinBtn.innerHTML = originalText;
                            exportAstroBinBtn.classList.remove('bg-green-600', 'hover:bg-green-700');
                            exportAstroBinBtn.classList.add('bg-sky-600', 'hover:bg-sky-700');
                        }, 2000);
                    }).catch(err => {
                        alert((window.i18n?.copy_to_clipboard_failed || 'Failed to copy to clipboard:') + ' ' + err);
                    });
                })
                .catch(error => {
                    alert((window.i18n?.error_fetching_csv_data || 'Error fetching CSV data:') + ' ' + error.message);
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
