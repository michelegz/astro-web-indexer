<!-- FOOTER -->
<footer class="bg-gray-700 text-gray-400 shadow-md text-sm mt-12">
    <div class="px-4 py-6 flex items-center justify-between">
        <!-- Credits text -->
        <p><?php echo __('footer_credits') ?></p>
        <!-- Version Info -->
        <?php
        $version = 'unknown'; // Default to 'unknown'
        $versionFile = '/opt/AWI_VERSION';
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
        loading: <?php echo json_encode(__('loading...')); ?>,

        // Main page
        no_files_selected: <?php echo json_encode(__('no_files_selected')); ?>,
        copied: <?php echo json_encode(__('copied')); ?>,
        copy_to_clipboard_failed: <?php echo json_encode(__('copy_to_clipboard_failed')); ?>,
        error_fetching_csv_data: <?php echo json_encode(__('error_fetching_csv_data')); ?>,

        // SFF Modal
        sff_total_exposure: <?php echo json_encode(__('sff_total_exposure')); ?>,
        sff_modal_title: <?php echo json_encode(__('sff_modal_title')); ?>,
        sff_loading_filters: <?php echo json_encode(__('sff_loading_filters')); ?>,
        sff_error_loading_filters: <?php echo json_encode(__('sff_error_loading_filters')); ?>,
        sff_searching: <?php echo json_encode(__('sff_searching')); ?>,
        sff_frames_found_js: <?php echo json_encode(__('sff_frames_found_js')); ?>,
        sff_configure_and_run: <?php echo json_encode(__('sff_configure_and_run')); ?>,

        // Duplicates Modal
        error_fetching_duplicates: <?php echo json_encode(__('error_fetching_duplicates')); ?>
    };
</script>
