<?php
// Shared AstroBin CSV export modal, used by the main file table and by the
// project tree card. The export logic lives in assets/js/astrobin_export.js
// (window.awiExportAstroBin); this partial is markup only.
?>
<!-- Modal for AstroBin CSV Export -->
<div id="astrobinModal" class="fixed inset-0 bg-gray-900 bg-opacity-75 flex items-center justify-center hidden z-50">
    <div class="bg-gray-800 rounded-lg shadow-xl p-6 w-full max-w-6xl transform transition-all">
        <div class="flex justify-between items-center border-b border-gray-700 pb-3">
            <h3 class="text-xl font-semibold text-white"><?php echo __('export_astrobin_csv') ?></h3>
            <button id="closeAstrobinModalBtn" class="text-gray-400 hover:text-white text-2xl">&times;</button>
        </div>

        <div class="mt-4">
            <p class="text-sm text-gray-300 mb-4">
                <?php echo __('astrobin_modal_explanation'); ?>
            </p>
            <p class="text-sm mb-2">
                <span id="astrobinMappingWarningText" data-tmpl="<?= htmlspecialchars(__('filter_mapping_unmapped')) ?>" class="hidden text-yellow-300 font-semibold"><span aria-hidden="true">⚠️ </span><span id="astrobinMappingWarningMsg"></span></span>
            </p>
            <textarea id="astrobinCsvText" readonly class="w-full h-64 bg-gray-900 text-gray-300 font-mono text-sm p-3 rounded-md border border-gray-600 focus:ring-2 focus:ring-blue-500 focus:outline-none"></textarea>
        </div>

        <div class="flex justify-end gap-2 pt-4 border-t border-gray-700 mt-4">
            <?php if (!isAuthEnabled() || isAdmin()): ?>
                <a href="/filter_mapping.php" target="_blank" rel="noopener" class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded"><?php echo __('filter_mapping_manage'); ?></a>
            <?php endif; ?>
            <button id="copyAstrobinCsvBtn" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded"><?php echo __('copy_to_clipboard') ?></button>
        </div>
    </div>
</div>
