// Shared AstroBin CSV export, used by the main file table (selected files)
// and by the project tree card (all project files). Same logic in both
// places: fetch the CSV from the API, show it in #astrobinModal, warn about
// FITS filters with no AstroBin ID mapping. Depends only on window.i18n
// (with English fallbacks) and on the markup from includes/astrobin_modal.php.
document.addEventListener('DOMContentLoaded', () => {
    const astrobinModal = document.getElementById('astrobinModal');
    const closeAstrobinModalBtn = document.getElementById('closeAstrobinModalBtn');
    const astrobinCsvText = document.getElementById('astrobinCsvText');
    const copyAstrobinCsvBtn = document.getElementById('copyAstrobinCsvBtn');

    window.awiExportAstroBin = (ids, btn) => {
        const list = (ids || []).map(v => parseInt(v, 10)).filter(id => Number.isInteger(id) && id > 0);
        if (list.length === 0 || !astrobinModal || !astrobinCsvText) return;

        // POST, not GET: the ids went on the query string, and a table or project
        // selection runs into thousands of them. A few digits each means tens of
        // kilobytes on the request line, over the 8 KB the server accepts, so the
        // export failed with 414 before any application code ran. Both endpoints
        // still accept the GET form, so a hand-written URL keeps working.
        const postJson = url => fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: list }),
        });

        // Show loading indicator on the triggering button, if any
        const originalText = btn ? btn.innerHTML : null;
        if (btn) {
            btn.innerHTML = window.i18n?.loading || 'Loading...';
            btn.disabled = true;
        }

        postJson('/api/export_astrobin_csv.php')
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok.');
                return response.text();
            })
            .then(csvText => {
                astrobinCsvText.value = csvText;
                astrobinModal.classList.remove('hidden');
                // Warn about FITS filters with no AstroBin ID mapping
                const warnTextReset = document.getElementById('astrobinMappingWarningText');
                if (warnTextReset) warnTextReset.classList.add('hidden');
                postJson('/api/get_unmapped_filters.php')
                    .then(response => response.json())
                    .then(data => {
                        const warnText = document.getElementById('astrobinMappingWarningText');
                        const warnMsg = document.getElementById('astrobinMappingWarningMsg');
                        if (!warnText || !warnMsg) return;
                        const unmapped = (data && data.unmapped) || [];
                        if (unmapped.length === 0) return;
                        const tmpl = warnText.dataset.tmpl || '{count} unmapped filters';
                        warnMsg.textContent = tmpl.replace('{count}', unmapped.length) + ' (' + unmapped.join(', ') + ')';
                        warnText.classList.remove('hidden');
                    })
                    .catch(() => { /* non-blocking: CSV is already shown */ });
            })
            .catch(error => {
                alert((window.i18n?.error_fetching_csv_data || 'Error fetching CSV data:') + ' ' + error.message);
            })
            .finally(() => {
                // Restore button state
                if (btn) {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            });
    };

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
});
