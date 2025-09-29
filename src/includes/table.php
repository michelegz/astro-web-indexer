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
                                <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('object')"><?php echo __('object') ?> <?php echoIcon('object', $sortBy, $sortOrder); ?></th>
                <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('date_obs')"><?php echo __('date_obs') ?> <?php echoIcon('date_obs', $sortBy, $sortOrder); ?></th>
                <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('exptime')"><?php echo __('exposure') ?> <?php echoIcon('exptime', $sortBy, $sortOrder); ?></th>
                <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('filter')"><?php echo __('filter') ?> <?php echoIcon('filter', $sortBy, $sortOrder); ?></th>
                                <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('imgtype')"><?php echo __('type') ?> <?php echoIcon('imgtype', $sortBy, $sortOrder); ?></th>
                <?php if ($showAdvanced): ?>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('xbinning')"><?php echo __('xbinning') ?> <?php echoIcon('xbinning', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('ybinning')"><?php echo __('ybinning') ?> <?php echoIcon('ybinning', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('egain')"><?php echo __('egain') ?> <?php echoIcon('egain', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('offset')"><?php echo __('offset') ?> <?php echoIcon('offset', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('xpixsz')"><?php echo __('xpixsz') ?> <?php echoIcon('xpixsz', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('ypixsz')"><?php echo __('ypixsz') ?> <?php echoIcon('ypixsz', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('instrume')"><?php echo __('instrume') ?> <?php echoIcon('instrume', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('set_temp')"><?php echo __('set_temp') ?> <?php echoIcon('set_temp', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('ccd_temp')"><?php echo __('ccd_temp') ?> <?php echoIcon('ccd_temp', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('telescop')"><?php echo __('telescop') ?> <?php echoIcon('telescop', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-g
                    ray-600" onclick="sortTable('focallen')"><?php echo __('focallen') ?> <?php echoIcon('focallen', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('focratio')"><?php echo __('focratio') ?> <?php echoIcon('focratio', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('ra')"><?php echo __('ra') ?> <?php echoIcon('ra', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('dec')"><?php echo __('dec') ?> <?php echoIcon('dec', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('centalt')"><?php echo __('centalt') ?> <?php echoIcon('centalt', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('centaz')"><?php echo __('centaz') ?> <?php echoIcon('centaz', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('airmass')"><?php echo __('airmass') ?> <?php echoIcon('airmass', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('pierside')"><?php echo __('pierside') ?> <?php echoIcon('pierside', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('siteelev')"><?php echo __('siteelev') ?> <?php echoIcon('siteelev', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('sitelat')"><?php echo __('sitelat') ?> <?php echoIcon('sitelat', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('sitelong')"><?php echo __('sitelong') ?> <?php echoIcon('sitelong', $sortBy, $sortOrder); ?></th>
                    <th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600" onclick="sortTable('focpos')"><?php echo __('focpos') ?> <?php echoIcon('focpos', $sortBy, $sortOrder); ?></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($files as $f): ?>
            <tr class="border-b border-gray-700 hover:bg-gray-700">
                <td class="p-3"><input type="checkbox" class="file-checkbox h-4 w-4 text-blue-600 rounded" value="<?= htmlspecialchars($f['path'] ?? '') ?>"></td>
                <td class="p-3">
                    <a href="/fits/<?= rawurlencode($f['path']) ?>" download class="text-blue-400 hover:text-blue-300">
                        <?= htmlspecialchars($f['name'] ?? '') ?>
                    </a>
                </td>
                <td class="p-3 text-sm text-gray-400"><?= htmlspecialchars(dirname($f['path'] ?? '')) ?></td>
                <td class="p-3">
                    <?php if ($f['thumb']): ?>
                        <img src="data:image/png;base64,<?= base64_encode($f['thumb']) ?>" 
                             alt="Preview" 
                             class="thumb max-w-[150px] h-auto rounded shadow-md object-cover">
                    <?php else: ?>
                        <span class="text-gray-500 text-sm">N/A</span>
                    <?php endif; ?>
                </td>
                <td class="p-3 text-gray-200"><?= htmlspecialchars($f['object'] ?? '') ?></td>
                <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['date_obs'] ?? '') ?></td>
                <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['exptime'] ?? '') ?>s</td>
                <td class="p-3 text-gray-200"><?= htmlspecialchars($f['filter'] ?? '') ?></td>
                <td class="p-3 text-gray-200"><?= htmlspecialchars($f['imgtype'] ?? '') ?></td>
                <?php if ($showAdvanced): ?>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['xbinning'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['ybinning'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['egain'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['offset'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['xpixsz'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['ypixsz'] ?? '') ?></td>
                    <td class="p-3 text-gray-200"><?= htmlspecialchars($f['instrume'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['set_temp'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['ccd_temp'] ?? '') ?></td>
                    <td class="p-3 text-gray-200"><?= htmlspecialchars($f['telescop'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['focallen'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['focratio'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['ra'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['dec'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['centalt'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['centaz'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['airmass'] ?? '') ?></td>
                    <td class="p-3 text-gray-200"><?= htmlspecialchars($f['pierside'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['siteelev'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['sitelat'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['sitelong'] ?? '') ?></td>
                    <td class="p-3 text-sm text-gray-300"><?= htmlspecialchars($f['focpos'] ?? '') ?></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
                        <?php if (empty($files)): ?>
                <tr><td colspan="<?= $showAdvanced ? '31' : '9' ?>" class="p-4 text-center text-gray-500"><?php echo __('no_files_found') ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>