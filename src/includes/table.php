<div class="mb-4 flex justify-end">
    <button id="downloadSelectedBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded disabled:opacity-50" disabled>
        <?php echo __('download_selected') ?>
    </button>
</div>

<div class="overflow-x-auto bg-gray-800 rounded-lg shadow-lg">
    <table class="w-full text-left">
        <thead class="bg-gray-700 text-gray-200">
            <tr>
                <th class="p-3 whitespace-nowrap"><input type="checkbox" id="selectAll" class="form-checkbox h-4 w-4 text-blue-600 rounded"></th>
                <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('name')"><?php echo __('file_name') ?> <?php echoIcon('name', $sortBy, $sortOrder); ?></th>
                <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('path')"><?php echo __('path') ?> <?php echoIcon('path', $sortBy, $sortOrder); ?></th>
                <th class="p-3 whitespace-nowrap"><?php echo __('preview') ?></th>
                <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('object')">OBJECT <?php echoIcon('object', $sortBy, $sortOrder); ?></th>
                <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('date_obs')">DATE-OBS <?php echoIcon('date_obs', $sortBy, $sortOrder); ?></th>
                <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('exptime')">EXPTIME <?php echoIcon('exptime', $sortBy, $sortOrder); ?></th>
                <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('filter')">FILTER <?php echoIcon('filter', $sortBy, $sortOrder); ?></th>
                <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('imgtype')">IMGTYPE <?php echoIcon('imgtype', $sortBy, $sortOrder); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($files as $f): ?>
            <tr class="border-b border-gray-700 hover:bg-gray-700">
                <td class="p-3"><input type="checkbox" class="file-checkbox h-4 w-4 text-blue-600 rounded" value="<?= htmlspecialchars($f['path']) ?>"></td>
                <td class="p-3">
                    <a href="/fits/<?= rawurlencode($f['path']) ?>" download class="text-blue-400 hover:text-blue-300">
                        <?= htmlspecialchars($f['name']) ?>
                    </a>
                </td>
                <td class="p-3 text-sm text-gray-400"><?= htmlspecialchars(dirname($f['path'])) ?></td>
                <td class="p-3">
                    <?php if ($f['thumb']): ?>
                        <img src="data:image/png;base64,<?= base64_encode($f['thumb']) ?>" 
                             alt="Preview" 
                             class="thumb max-w-[150px] h-auto rounded shadow-md object-cover">
                    <?php else: ?>
                        <span class="text-gray-500 text-sm">N/A</span>
                    <?php endif; ?>
                </td>
                <td class="p-3 text-gray-200"><?= htmlspecialchars($f['object']) ?></td>
                <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['date_obs']) ?></td>
                <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['exptime']) ?>s</td>
                <td class="p-3 text-gray-200"><?= htmlspecialchars($f['filter']) ?></td>
                <td class="p-3 text-gray-200"><?= htmlspecialchars($f['imgtype']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($files)): ?>
                <tr><td colspan="9" class="p-4 text-center text-gray-500"><?php echo __('no_files_found') ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>