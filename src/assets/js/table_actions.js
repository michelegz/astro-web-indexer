// This script should be placed at the end of table.php

document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const fileCheckboxes = document.querySelectorAll('.file-checkbox');
    const downloadSelectedBtn = document.getElementById('downloadSelectedBtn');
    const exportAstroBinBtn = document.getElementById('exportAstroBinBtn');

    function updateButtonStates() {
        const selectedCheckboxes = document.querySelectorAll('.file-checkbox:checked');
        const hasSelection = selectedCheckboxes.length > 0;
        
        if (downloadSelectedBtn) {
            downloadSelectedBtn.disabled = !hasSelection;
        }
        if (exportAstroBinBtn) {
            exportAstroBinBtn.disabled = !hasSelection;
        }
    }

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            fileCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });
            updateButtonStates();
        });
    }

    fileCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateButtonStates);
    });

    if (downloadSelectedBtn) {
        downloadSelectedBtn.addEventListener('click', function() {
            const selectedFiles = Array.from(document.querySelectorAll('.file-checkbox:checked')).map(cb => cb.value);
            
            if (selectedFiles.length === 0) {
                alert(window.i18n.no_files_selected || 'No files selected for download.');
                return;
            }
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'api/download_zip.php';
            
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
        });
    }

    if (exportAstroBinBtn) {
        exportAstroBinBtn.addEventListener('click', function() {
            const selectedIds = Array.from(document.querySelectorAll('.file-checkbox:checked'))
                                     .map(cb => cb.dataset.id);

            if (selectedIds.length === 0) {
                return;
            }

            const idsQueryString = selectedIds.join(',');
            const exportUrl = `/api/export_astrobin_csv.php?ids=${idsQueryString}`;
            
            // Fetch the CSV data from the API
            fetch(exportUrl)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok.');
                    }
                    return response.text();
                })
                .then(csvText => {
                    // Copy text to clipboard
                    navigator.clipboard.writeText(csvText).then(() => {
                        // Success feedback
                        const originalText = exportAstroBinBtn.innerHTML;
                        exportAstroBinBtn.innerHTML = window.i18n.copied || 'Copied!';
                        exportAstroBinBtn.classList.add('bg-green-600', 'hover:bg-green-700');
                        exportAstroBinBtn.classList.remove('bg-sky-600', 'hover:bg-sky-700');
                        
                        setTimeout(() => {
                            exportAstroBinBtn.innerHTML = originalText;
                            exportAstroBinBtn.classList.remove('bg-green-600', 'hover:bg-green-700');
                            exportAstroBinBtn.classList.add('bg-sky-600', 'hover:bg-sky-700');
                        }, 2000); // Revert after 2 seconds
                    }).catch(err => {
                        // Error feedback
                        alert((window.i18n.copy_to_clipboard_failed || 'Failed to copy to clipboard:') + ' ' + err);
                    });
                })
                .catch(error => {
                    alert((window.i18n.error_fetching_csv_data || 'Error fetching CSV data:') + ' ' + error.message);
                });
        });
    }

    // Initial check
    updateButtonStates();
});
