<!-- FOOTER -->
<footer class="bg-gray-700 text-gray-400 shadow-md text-sm mt-12">
    <div class="px-4 py-6 flex items-center justify-between">
        <!-- Credits text -->
        <p><?php echo __('footer_credits') ?></p>
        <!-- Version Info -->
        <?php
        $version = 'unknown'; // Default to 'unknown'
        $versionFile = __DIR__ . '/../VERSION';
        if (file_exists($versionFile)) {
            $versionContent = trim(file_get_contents($versionFile));
            if (!empty($versionContent)) {
                $version = $versionContent;
            }
        }
        ?>
        <p class="text-xs font-mono bg-gray-800 px-2 py-1 rounded"><?php echo htmlspecialchars($version); ?></p>
    </div>
</footer>

<script type="text/javascript">
    window.i18n = {
        // General
        loading: '<?php echo addslashes(__('loading...')); ?>',

        // Main page
        no_files_selected: '<?php echo addslashes(__('no_files_selected')); ?>',
        copied: '<?php echo addslashes(__('copied')); ?>',
        copy_to_clipboard_failed: '<?php echo addslashes(__('copy_to_clipboard_failed')); ?>',
        error_fetching_csv_data: '<?php echo addslashes(__('error_fetching_csv_data')); ?>',
        
        // SFF Modal
        sff_loading_filters: '<?php echo addslashes(__('sff_loading_filters', 'Loading filters...')); ?>',
        sff_error_loading_filters: '<?php echo addslashes(__('sff_error_loading_filters', 'Error loading filters:')); ?>',
        sff_searching: '<?php echo addslashes(__('sff_searching', 'Searching...')); ?>',
        sff_frames_found_js: '<?php echo addslashes(__('sff_frames_found_js', '{count} frames found.')); ?>',
        sff_configure_and_run: '<?php echo addslashes(__('sff_configure_and_run', 'Configure and run a search.')); ?>',

        // Duplicates Modal
        error_fetching_duplicates: '<?php echo addslashes(__('error_fetching_duplicates')); ?>'
    };
</script>
