<?php
/**
 * Renders the results table for the Smart Frame Finder modal.
 *
 * @param array $files The array of file records from the database.
 */
function render_sff_results_table(array $files): void
{
    if (empty($files)) {
        echo '<p class="text-center text-gray-400 p-8">'. __('no_matching_frames_found') .  ' </p>';
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
            <?php foreach ($files as $file):
                $isReference = isset($file['is_reference']) && $file['is_reference'];
                $rowClass = $isReference ? 'bg-blue-900 bg-opacity-50' : '';
            ?>
                <tr class="border-b border-gray-700 hover:bg-gray-600 <?= $rowClass ?>">
                    <td class="p-2">
                        <input type="checkbox" class="sff-file-checkbox" value="<?= htmlspecialchars($file['path']) ?>" data-id="<?= (int)($file['id'] ?? 0) ?>">
                    </td>
                    <td class="p-2">
                        <?php // The thumbnail is referenced, not inlined. It used to be
                        // base64'd into a data: URI, which meant every matching row shipped
                        // its bitmap: 8.3 MB of blobs became 11.4 MB of base64 and then a
                        // 12.2 MB JSON response, for 236 rows of which the text is a few
                        // tens of KB. /image.php serves the same bytes, checks
                        // canAccessPath() on the way, and the browser only fetches the
                        // ones it renders. Same pattern as file_cells.php.
                        // 'has_thumb' is the flag the query selects instead of the blob. ?>
                        <?php if (!empty($file['has_thumb'])): ?>
                            <img src="/image.php?id=<?= (int)($file['id'] ?? 0) ?>&type=thumb"
                                 alt="Preview"
                                 loading="lazy"
                                 class="thumb max-w-[100px] h-auto rounded shadow-md object-cover">
                        <?php else: ?>
                            <span class="text-gray-500 text-xs">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td class="p-2 text-blue-400 hover:text-blue-300">
                        <a href="/fits/<?= rawurlencode($file['path']) ?>" download><?= htmlspecialchars($file['name']) ?></a>
                    </td>
                    <?php // date_obs is guarded rather than cast. reindex.py stores
                    // NULL when the DATE-OBS header cannot be parsed (reindex.py, the
                    // `Unparsable DATE-OBS` branch), and substr(NULL, 0, 10) is deprecated
                    // in PHP 8.1+. The notice is printed inside the output buffer, so the
                    // JSON stayed valid but the diagnostic itself reached the browser:
                    // sff.js:152 assigns this HTML to innerHTML, so the user saw
                    // "Deprecated: substr(): Passing null..." in the panel, together with
                    // the server's absolute path and this line number. The cells below
                    // already print 'N/A' for a missing value, so this one does too. ?>
                    <td class="p-2 text-gray-300 whitespace-nowrap"><?= !empty($file['date_obs'])
                        ? htmlspecialchars(substr($file['date_obs'], 0, 10)) : 'N/A' ?></td>
                    <td class="p-2 text-gray-300"><?= isset($file['exptime']) ? htmlspecialchars((string)$file['exptime']) . 's' : 'N/A' ?></td>
                    <td class="p-2 text-gray-300"><?= isset($file['ccd_temp']) ? htmlspecialchars((string)$file['ccd_temp']) . '°' : 'N/A' ?></td>
                    <td class="p-2 text-gray-300"><?= isset($file['xbinning'], $file['ybinning']) ? htmlspecialchars($file['xbinning'] . 'x' . $file['ybinning']) : 'N/A' ?></td>
                    <td class="p-2 text-gray-300"><?= isset($file['width'], $file['height']) ? htmlspecialchars($file['width'] . 'x' . $file['height']) : 'N/A' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
}
