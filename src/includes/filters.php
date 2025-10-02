<form id="filters-form" method="get" class="bg-gray-800 p-4 rounded-lg shadow-md flex flex-wrap gap-4 items-end mb-6">
    <input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>">
    <input type="hidden" name="page" value="1"> <!-- Resetta la pagina quando si applicano i filtri -->
    <input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>">
    <input type="hidden" name="sort_by" value="<?= htmlspecialchars($sortBy) ?>">
    <input type="hidden" name="sort_order" value="<?= htmlspecialchars($sortOrder) ?>">

    <?php $currentParams = $_GET; // Per i filtri interdipendenti ?>

    <div>
        <label for="object-select" class="block text-sm font-medium text-gray-300 mb-1">OBJECT:</label>
        <select id="object-select" name="object" class="bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2.5 w-40">
            <option value=""><?php echo __('all_objects') ?></option>
            <?php
            // Get OBJECT values considering other filters (except OBJECT itself)
            $availableObjects = getDistinctValues($conn, 'object', $dir, '', $filterFilter, $filterImgtype);
            foreach($availableObjects as $o): ?>
                <option value="<?= htmlspecialchars($o) ?>" <?= $o==$filterObject?'selected':'' ?>><?= htmlspecialchars($o) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label for="filter-select" class="block text-sm font-medium text-gray-300 mb-1">FILTER:</label>
        <select id="filter-select" name="filter" class="bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2.5 w-40">
            <option value=""><?php echo __('all_filters') ?></option>
            <?php
            // Get FILTER values considering other filters (except FILTER itself)
            $availableFilters = getDistinctValues($conn, 'filter', $dir, $filterObject, '', $filterImgtype);
            foreach($availableFilters as $f): ?>
                <option value="<?= htmlspecialchars($f) ?>" <?= $f==$filterFilter?'selected':'' ?>><?= htmlspecialchars($f) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label for="imgtype-select" class="block text-sm font-medium text-gray-300 mb-1">IMGTYPE:</label>
        <select id="imgtype-select" name="imgtype" class="bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2.5 w-40">
            <option value=""><?php echo __('all_types') ?></option>
            <?php
            // Get IMGTYPE values considering other filters (except IMGTYPE itself)
            $availableImgtypes = getDistinctValues($conn, 'imgtype', $dir, $filterObject, $filterFilter, '');
            foreach($availableImgtypes as $i): ?>
                <option value="<?= htmlspecialchars($i) ?>" <?= $i==$filterImgtype?'selected':'' ?>><?= htmlspecialchars($i) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Items per page -->
    <div>
        <label for="per_page-select" class="block text-sm font-medium text-gray-300 mb-1"><?php echo __('elements_per_page') ?>:</label>
        <select id="per_page-select" name="per_page" class="bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2.5 w-24">
            <?php foreach(PER_PAGE_OPTIONS as $option): ?>
                <option value="<?= $option ?>" <?= $option==$perPage?'selected':'' ?>><?= $option ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    
        <?php
        // Build a clean URL for the reset button, preserving only dir and lang
        $reset_params = [
            'dir' => $dir,
            'lang' => $lang
        ];
        $reset_href = '?' . http_build_query($reset_params);
    ?>
    <a href="<?= htmlspecialchars($reset_href) ?>" class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg transition duration-200">
        <?php echo __('reset_filters') ?>
    </a>

    <div class="flex items-center">
        <input id="show-advanced-fields" name="show_advanced" type="checkbox" value="1" class="w-4 h-4 text-blue-600 bg-gray-700 border-gray-600 rounded focus:ring-blue-600 ring-offset-gray-800 focus:ring-2" <?php if ($showAdvanced) echo 'checked'; ?>>
        <label for="show-advanced-fields" class="ml-2 text-sm font-medium text-gray-300"><?php echo __('show_advanced_fields') ?></label>
    </div>


</form>