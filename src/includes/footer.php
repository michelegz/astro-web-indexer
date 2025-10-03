<!-- FOOTER -->
<footer class="bg-gray-700 text-gray-400 shadow-md text-sm mt-12">
    <div class="px-4 py-6 flex items-center justify-between">
        <!-- Credits text -->
        <p><?php echo __('footer_credits') ?></p>
        <!-- Version Info -->
        <?php
        $version = 'dev'; // Default to 'dev'
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
        no_files_selected: '<?php echo __('no_files_selected'); ?>',
        copied: '<?php echo __('copied'); ?>',
        copy_to_clipboard_failed: '<?php echo __('copy_to_clipboard_failed'); ?>',
        error_fetching_csv_data: '<?php echo __('error_fetching_csv_data'); ?>',
        loading: '<?php echo __('loading...'); ?>',
        error_fetching_duplicates: '<?php echo __('error_fetching_duplicates'); ?>'
    };
</script>
