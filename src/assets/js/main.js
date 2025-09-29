document.addEventListener('DOMContentLoaded', () => {
    // DOM element references
    const sidebar = document.getElementById('sidebar');
    const menuOverlay = document.getElementById('menu-overlay');
    const selectAllCheckbox = document.getElementById('selectAll');
    const tableBody = document.querySelector('table tbody'); 
    const downloadSelectedBtn = document.getElementById('downloadSelectedBtn');

    /**
     * Handles opening/closing of sidebar menu on mobile devices.
     * Makes the overlay visible/invisible.
     */
    window.toggleMenu = () => {
        sidebar.classList.toggle('-translate-x-full');
        sidebar.classList.toggle('translate-x-0');
        // Handle 'hidden' class to show/hide overlay
        menuOverlay.classList.toggle('hidden');
    };

    // Close menu when clicking on overlay (mobile only)
    if (menuOverlay) {
        menuOverlay.addEventListener('click', toggleMenu);
    }

    /**
     * Updates download button state (enabled/disabled)
     * based on the number of selected checkboxes.
     */
    function updateDownloadButtonState() {
        const fileCheckboxes = document.querySelectorAll('.file-checkbox');
        const anyChecked = Array.from(fileCheckboxes).some(checkbox => checkbox.checked);
        if (downloadSelectedBtn) {
            downloadSelectedBtn.disabled = !anyChecked;
        }
    }

    /**
     * Handles the "Select all" checkbox.
     * Selects or deselects all file checkboxes.
     */
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', () => {
            const fileCheckboxes = document.querySelectorAll('.file-checkbox');
            fileCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });
            updateDownloadButtonState();
        });
    }

    /**
     * Handles individual file checkboxes.
     * If one is deselected, the "Select all" checkbox is deselected.
     * If all are selected, the "Select all" checkbox is selected.
     * Uses event delegation to support dynamically loaded rows.
     */
    if (tableBody) {
        tableBody.addEventListener('change', (event) => {
            if (event.target.classList.contains('file-checkbox')) {
                const fileCheckboxes = document.querySelectorAll('.file-checkbox');
                if (!event.target.checked && selectAllCheckbox) {
                    selectAllCheckbox.checked = false;
                } else if (selectAllCheckbox) {
                    const allChecked = Array.from(fileCheckboxes).every(cb => cb.checked);
                    selectAllCheckbox.checked = allChecked;
                }
                updateDownloadButtonState();
            }
        });
    }

    /**
     * Handles click on "Download Selected" button.
     * Collects selected file paths and initiates ZIP file download.
     */
    if (downloadSelectedBtn) {
        downloadSelectedBtn.addEventListener('click', () => {
            const fileCheckboxes = document.querySelectorAll('.file-checkbox');
            const selectedFiles = Array.from(fileCheckboxes)
                                   .filter(checkbox => checkbox.checked)
                                   .map(checkbox => checkbox.value); // Value is the relative path

            if (selectedFiles.length > 0) {
                const params = new URLSearchParams();
                selectedFiles.forEach(file => params.append('files[]', file));
                
                // Create a temporary link and simulate click to start download
                const downloadLink = document.createElement('a');
                downloadLink.href = 'download.php?' + params.toString();
                downloadLink.download = 'selected_fits_files.zip'; // Suggested name for downloaded file
                document.body.appendChild(downloadLink);
                downloadLink.click();
                document.body.removeChild(downloadLink);

                // Optional: reset checkboxes and button state after download
                if (selectAllCheckbox) selectAllCheckbox.checked = false;
                fileCheckboxes.forEach(checkbox => checkbox.checked = false);
                updateDownloadButtonState();
            } else {
                alert('Please select at least one file to download.');
            }
        });
    }

    /**
     * Global function for sorting table columns.
     * Reloads the page with new sorting parameters.
     * @param {string} column - The name of the column to sort by.
     */
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
        urlParams.set('page', '1'); // Return to first page after sorting
        window.location.search = urlParams.toString();
    };

    // Call function to set initial state of download button
    updateDownloadButtonState();

    const filtersForm = document.getElementById('filters-form');
    if (filtersForm) {
        const filters = filtersForm.querySelectorAll('select, input[type="checkbox"]');
        filters.forEach(filter => {
            filter.addEventListener('change', () => {
                filtersForm.submit();
            });
        });
    }
});