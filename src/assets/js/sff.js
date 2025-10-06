document.addEventListener('DOMContentLoaded', () => {
    
    // --- SFF Modal Elements ---
    const sffModal = document.getElementById('sffModal');
    const sffModalTitle = document.getElementById('sffModalTitle');
    const sffCloseModalBtn = document.getElementById('sffCloseModalBtn');
    const sffFiltersPanel = document.getElementById('sffFiltersPanel');
    const sffResultsPanel = document.getElementById('sffResultsPanel');
    const sffFindBtn = document.getElementById('sffFindBtn');
    const sffDownloadBtn = document.getElementById('sffDownloadBtn');
    const sffResultCount = document.getElementById('sffResultCount');

    // Store reference file ID and search type when modal is opened
    let currentFileId = null;
    let currentSearchType = null;

    // --- Modal Basic Functions ---
    
    function openSffModal(fileId, searchType) {
        if (!sffModal) return;

        currentFileId = fileId;
        currentSearchType = searchType;

        // Set title and reset state




        const titleSearchType = (currentSearchType || '').replace(/-/g, ' ');
        sffModalTitle.textContent = `${window.i18n.sff_modal_title}: ${titleSearchType}`;
        sffFiltersPanel.innerHTML = `<div class="text-center p-4 text-gray-400">${window.i18n.sff_loading_filters}</div>`;
        sffResultsPanel.innerHTML = `<div class="text-center p-4 text-gray-400">${window.i18n.sff_configure_and_run}</div>`;
        sffResultCount.textContent = '';
        sffDownloadBtn.disabled = true;

        // Fetch the filters panel HTML from the backend
        fetch(`/api/sff_get_filters.php?id=${fileId}&type=${searchType}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text();
            })
            .then(html => {
                sffFiltersPanel.innerHTML = html;
            })
            .catch(error => {

                sffFiltersPanel.innerHTML = `<div class="text-center p-4 text-red-500">${window.i18n.sff_error_loading_filters} ${error.message}</div>`;
            });

        // Show the modal
        sffModal.classList.remove('hidden');
    }

    function closeSffModal() {
        if (!sffModal) return;
        sffModal.classList.add('hidden');
    }

    // --- Event Handlers ---

    // Open modal by clicking [L], [B], etc. buttons
    document.body.addEventListener('click', function(event) {
        if (event.target.classList.contains('sff-button')) {
            const fileId = event.target.dataset.fileId;
            const searchType = event.target.dataset.searchType;
            openSffModal(fileId, searchType);
        }
    });

    // Close modal
    if (sffCloseModalBtn) {
        sffCloseModalBtn.addEventListener('click', closeSffModal);
    }
    if (sffModal) {
        sffModal.addEventListener('click', (event) => {
            if (event.target === sffModal) {
                closeSffModal();
            }
        });
    }

    // --- SFF Modal Interactivity ---

    // Handle "Find Frames" button click
    if(sffFindBtn) {
        sffFindBtn.addEventListener('click', () => {
            const filters = [];
            document.querySelectorAll('#sffFiltersPanel .sff-filter-row').forEach(row => {
                const toggle = row.querySelector('.sff-filter-toggle');
                if (toggle.checked) {
                    const filterId = row.dataset.filterId;
                    const refValueInput = row.querySelector('.sff-reference-value');
                    const slider = row.querySelector('.sff-filter-slider');
                    const valueSpan = row.querySelector('.sff-slider-value');
                    
                    let filterType = 'toggle';
                    if (slider) {
                        const unit = valueSpan.dataset.unit || '';
                        if (unit === '%') filterType = 'slider_percent';
                        else if (unit === '°') filterType = 'slider_degrees';
                        else if (unit === 'd') filterType = 'slider_days';
                    }

                    const filterData = {
                        id: filterId,
                        type: filterType,
                        ref_value: refValueInput ? refValueInput.value : null,
                        tolerance: slider ? slider.value : null
                    };
                    filters.push(filterData);
                }
            });

            const payload = {
                file_id: currentFileId,
                search_type: currentSearchType,
                filters: filters
            };


            sffResultsPanel.innerHTML = `<div class="text-center p-4 text-gray-400">${window.i18n.sff_searching}</div>`;
            
            fetch('/api/find_calibration_files.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(response => {
                if (!response.ok) {
                    return response.text().then(text => { throw new Error(text || `Server error: ${response.statusText}`) });
                }
                return response.text(); // Expect HTML now
            })
            .then(html => {
                sffResultsPanel.innerHTML = html;
                const resultCount = sffResultsPanel.querySelectorAll('tbody tr').length;

                sffResultCount.textContent = (window.i18n.sff_frames_found_js).replace('{count}', resultCount);
                updateSffDownloadButtonState();
            })
            .catch(error => {
                sffResultsPanel.innerHTML = `<div class="text-center p-4 text-red-500">Error: ${error.message}</div>`;
            });
        });
    }

    // --- Results Panel Interactivity ---

    function updateSffDownloadButtonState() {
        const anySelected = sffResultsPanel.querySelector('.sff-file-checkbox:checked');
        sffDownloadBtn.disabled = !anySelected;
    }

    // Handle "Select All" checkbox
    sffResultsPanel.addEventListener('change', function(event) {
        if (event.target.classList.contains('sff-select-all-checkbox')) {
            const checkboxes = sffResultsPanel.querySelectorAll('.sff-file-checkbox');
            checkboxes.forEach(cb => cb.checked = event.target.checked);
            updateSffDownloadButtonState();
        }
        if (event.target.classList.contains('sff-file-checkbox')) {
            updateSffDownloadButtonState();
        }
    });

    // Handle Download button click
    if (sffDownloadBtn) {
        sffDownloadBtn.addEventListener('click', () => {
            const selectedFiles = [];
            sffResultsPanel.querySelectorAll('.sff-file-checkbox:checked').forEach(cb => {
                selectedFiles.push(cb.value);
            });

            if (selectedFiles.length === 0) return;

            // Use the same form-submission technique as the main page download
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
        });
    }

    // Handle enabling/disabling sliders via checkbox
    sffFiltersPanel.addEventListener('change', function(event) {
        if (event.target.classList.contains('sff-filter-toggle')) {
            const row = event.target.closest('.sff-filter-row');
            const sliderContainer = row.querySelector('.sff-slider-container');
            if (sliderContainer) {
                const slider = sliderContainer.querySelector('input[type="range"]');
                if (event.target.checked) {
                    sliderContainer.classList.remove('opacity-50');
                    slider.disabled = false;
                } else {
                    sliderContainer.classList.add('opacity-50');
                    slider.disabled = true;
                }
            }
        }
    });

    // Handle slider value display
    sffFiltersPanel.addEventListener('input', function(event) {
        if (event.target.classList.contains('sff-filter-slider')) {
            const row = event.target.closest('.sff-filter-row');
            const valueSpan = row.querySelector('.sff-slider-value');
            const unit = valueSpan.dataset.unit || '';
            valueSpan.textContent = `±${event.target.value}${unit}`;
        }
    });

});
