<?php
/**
 * Renders the results table for the Smart Frame Finder modal.
 *
 * @param array $files The array of file records from the database.
 */
function render_sff_results_table(array $files): void
{
    if (empty($files)) {
        echo '<p class="text-center text-gray-400 p-8">No matching frames found.</p>';
        return;
    }
?>
<div class="overflow-y-auto h-full">
    <table class="w-full text-left text-sm">
        <thead class="bg-gray-700 text-gray-200 sticky top-0">
            <tr>
                <th class="p-2"><input type="checkbox" class="sff-select-all-checkbox"></th>
                <th class="p-2"><?php echo __('preview'); ?></th>
                <th class="p-2"><?php echo __('file_name'); ?></th>
                <th class="p-2"><?php echo __('date'); ?></th>
                <th class="p-2"><?php echo __('exposure'); ?></th>
                <th class="p-2"><?php echo __('ccd_temp'); ?></th>
                <th class="p-2"><?php echo __('binning'); ?></th>
                <th class="p-2"><?php echo __('dimensions'); ?></th>
            </tr>
        </thead>
        <tbody class="bg-gray-800">
            <?php foreach ($files as $file): ?>
                <tr class="border-b border-gray-700 hover:bg-gray-600">
                    <td class="p-2">
                        <input type="checkbox" class="sff-file-checkbox" value="<?= htmlspecialchars($file['path']) ?>">
                    </td>
                    <td class="p-2">
                        <?php if ($file['thumb']): ?>
                            <img src="data:image/png;base64,<?= base64_encode($file['thumb']) ?>" 
                                 alt="Preview" 
                                 class="thumb max-w-[100px] h-auto rounded shadow-md object-cover">
                        <?php else: ?>
                            <span class="text-gray-500 text-xs">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td class="p-2 text-blue-400 hover:text-blue-300">
                        <a href="/fits/<?= rawurlencode($file['path']) ?>" download><?= htmlspecialchars($file['name']) ?></a>
                    </td>
                    <td class="p-2 text-gray-300 whitespace-nowrap"><?= htmlspecialchars(substr($file['date_obs'], 0, 10)) ?></td>
                    <td class="p-2 text-gray-300"><?= htmlspecialchars($file['exptime']) ?>s</td>
                    <td class="p-2 text-gray-300"><?= htmlspecialchars($file['ccd_temp']) ?>°</td>
                    <td class="p-2 text-gray-300"><?= htmlspecialchars($file['xbinning'] . 'x' . $file['ybinning']) ?></td>
                    <td class="p-2 text-gray-300"><?= htmlspecialchars($file['width'] . 'x' . $file['height']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
}
