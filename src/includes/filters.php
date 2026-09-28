<?php
// Leggi le preferenze di visualizzazione dai cookie per evitare il flickering
$viewMode = $_COOKIE['viewMode'] ?? 'list';
$thumbSize = $_COOKIE['thumbSize'] ?? '3';
?>
<form id="filters-form" method="get" class="bg-gray-800 p-4 rounded-lg shadow-md flex flex-wrap gap-4 items-end mb-6">
    <input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>">
    <input type="hidden" name="page" value="1"> <!-- Resetta la pagina quando si applicano i filtri -->
    <input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>">
    <input type="hidden" name="sort_by" value="<?= htmlspecialchars($sortBy) ?>">
    <input type="hidden" name="sort_order" value="<?= htmlspecialchars($sortOrder) ?>">

    <?php $currentParams = $_GET; // Per i filtri interdipendenti ?>

    <div>
                <label for="object-select" class="block text-sm font-medium text-gray-300 mb-1"><?php echo __('object') ?></label>
        <select id="object-select" name="object" class="appearance-none bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2.5 w-40 pr-8 bg-no-repeat bg-right" style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%236b7280\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 0.5rem center; background-size: 1.5em 1.5em;">
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
                <label for="filter-select" class="block text-sm font-medium text-gray-300 mb-1"><?php echo __('filter') ?></label>
        <select id="filter-select" name="filter" class="appearance-none bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2.5 w-40 pr-8 bg-no-repeat bg-right" style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%236b7280\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 0.5rem center; background-size: 1.5em 1.5em;">
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
                <label for="imgtype-select" class="block text-sm font-medium text-gray-300 mb-1"><?php echo __('type') ?></label>
        <select id="imgtype-select" name="imgtype" class="appearance-none bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2.5 w-40 pr-8 bg-no-repeat bg-right" style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%236b7280\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 0.5rem center; background-size: 1.5em 1.5em;">
            <option value=""><?php echo __('all_types') ?></option>
            <?php
            // Get IMGTYPE values considering other filters (except IMGTYPE itself)
            $availableImgtypes = getDistinctValues($conn, 'imgtype', $dir, $filterObject, $filterFilter, '');
                        foreach($availableImgtypes as $i): ?>
                <option value="<?= htmlspecialchars($i) ?>" <?= $i==$filterImgtype?'selected':'' ?>><?= htmlspecialchars($i) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

        <!-- Date OBS Filter -->
    <div class="md:border-l md:border-gray-600 md:pl-4">
        <label for="date_obs_from" class="block text-sm font-medium text-gray-300 mb-1"><?php echo __('observation_date'); ?>:</label>
        <div class="flex items-center gap-2">
            <input type="date" id="date_obs_from" name="date_obs_from" value="<?= htmlspecialchars($_GET['date_obs_from'] ?? '') ?>" class="bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2.5">
            <span class="text-gray-400">-</span>
            <input type="date" id="date_obs_to" name="date_obs_to" value="<?= htmlspecialchars($_GET['date_obs_to'] ?? '') ?>" class="bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2.5">
                </div>
    </div>

        <!-- Exposure Time Filter (seconds) -->
    <div class="md:border-l md:border-gray-600 md:pl-4">
        <label for="exptime_min" class="block text-sm font-medium text-gray-300 mb-1"><?php echo __('exposure_time'); ?>:</label>
        <div class="flex items-center gap-2">
            <input type="number" id="exptime_min" name="exptime_min" min="0" step="any" value="<?= htmlspecialchars($exptimeMin ?? '') ?>" class="bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2.5 w-28">
            <span class="text-gray-400">-</span>
            <input type="number" id="exptime_max" name="exptime_max" min="0" step="any" value="<?= htmlspecialchars($exptimeMax ?? '') ?>" class="bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2.5 w-28">
                </div>
    </div>

    <!-- Items per page -->
        <div class="md:border-l md:border-gray-600 md:pl-4">
        <label for="per_page-select" class="block text-sm font-medium text-gray-300 mb-1"><?php echo __('elements_per_page') ?>:</label>
        <select id="per_page-select" name="per_page" class="appearance-none bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2.5 w-24 pr-8 bg-no-repeat bg-right" style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%236b7280\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 0.5rem center; background-size: 1.5em 1.5em;">
            <?php foreach(PER_PAGE_OPTIONS as $option): ?>
                <option value="<?= $option ?>" <?= $option==$perPage?'selected':'' ?>><?= $option ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    
<div class="md:border-l md:border-gray-600 md:pl-4 flex flex-wrap items-center gap-4">
    <!-- View Toggle and Size Slider -->
    <div class="flex items-center gap-4 md:pr-4 md:border-r md:border-gray-600">
        <div class="flex items-center bg-gray-700 rounded-lg">
            <button id="list-view-btn" class="flex items-center justify-center p-2 rounded-l-lg <?php echo $viewMode === 'list' ? 'bg-blue-600' : ''; ?> hover:bg-blue-700 transition-colors" title="<?php echo __('list_view') ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
            <button id="thumbnail-view-btn" class="flex items-center justify-center p-2 rounded-r-lg <?php echo $viewMode === 'thumbnail' ? 'bg-blue-600' : ''; ?> hover:bg-blue-700 transition-colors" title="<?php echo __('thumbnail_view') ?>">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                </svg>
            </button>
        </div>
        
        <div class="flex flex-col">
            <label for="thumbnail-size-slider" class="text-sm font-medium text-gray-300 mb-1"><?php echo __('thumbnail_size') ?></label>
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-400">S</span>
                <input type="range" id="thumbnail-size-slider" min="1" max="5" value="<?php echo htmlspecialchars($thumbSize); ?>" class="w-24 h-2 bg-gray-700 rounded-lg appearance-none cursor-pointer">
                <span class="text-sm text-gray-400">L</span>
            </div>
        </div>
    </div>
    
    <?php
        // Build a clean URL for the reset button, preserving only dir and lang
        $reset_params = [
            'dir' => $dir,
            'lang' => $lang
        ];
        $reset_href = '?' . http_build_query($reset_params);
    ?>
    <a href="<?= htmlspecialchars($reset_href) ?>" 
       class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg transition duration-200">
        <?php echo __('reset_filters') ?>
    </a>

    <div>
        <button type="button" id="columns-btn"
           class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg transition duration-200">
            <?php echo __('columns') ?>
        </button>
    </div>
</div>

<!-- Columns Modal -->
<div id="columns-modal" class="fixed inset-0 bg-gray-900 bg-opacity-75 flex items-center justify-center hidden z-50">
    <div class="bg-gray-800 rounded-lg shadow-xl p-6 w-full max-w-6xl transform transition-all">
        <div class="flex justify-between items-center border-b border-gray-700 pb-3">
            <h3 class="text-xl font-semibold text-white"><?php echo __('columns') ?></h3>
            <button type="button" id="columns-close" class="text-gray-400 hover:text-white text-2xl">&times;</button>
        </div>
        <div id="columns-panel" class="mt-4 max-h-[75vh] overflow-y-auto">
            <div class="flex flex-wrap gap-6">
            <?php foreach (getColumnGroups() as $groupKey => $group): ?>
                <?php if ($groupKey === 'star' && !$showStarMetrics) continue; ?>
                <div style="flex: 1 1 14rem;">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-400 mt-2 mb-1"><?php echo __($group['label']); ?></div>
                <?php foreach ($group['columns'] as $sortKey => [$labelKey, $_]): ?>
                    <label class="flex items-center gap-2 py-1 text-sm text-gray-200 cursor-pointer hover:bg-gray-600 rounded px-1">
                        <input type="checkbox" data-col="<?= htmlspecialchars($sortKey) ?>" data-group="<?= htmlspecialchars($groupKey) ?>"
                               <?= showCol($sortKey) ? 'checked' : '' ?>
                               class="w-4 h-4 text-blue-600 bg-gray-700 border-gray-600 rounded focus:ring-blue-600 ring-offset-gray-800 focus:ring-2">
                        <?php echo __($labelKey); ?>
                    </label>
                <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <div class="flex gap-2 pt-4 border-t border-gray-700 mt-4">
            <button type="button" id="columns-all"
               class="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold py-2 px-4 rounded transition duration-200">
                <?php echo __('columns_show_all') ?>
            </button>
            <button type="button" id="columns-reset"
               class="flex-1 bg-gray-600 hover:bg-gray-500 text-white text-sm font-bold py-2 px-4 rounded transition duration-200">
                <?php echo __('columns_reset') ?>
            </button>
            <button type="button" id="columns-apply"
               class="flex-1 bg-green-600 hover:bg-green-700 text-white text-sm font-bold py-2 px-4 rounded transition duration-200">
                <?php echo __('columns_apply') ?>
            </button>
        </div>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('columns-btn');
    var panel = document.getElementById('columns-panel');
    if (!btn || !panel) return;

    var modal = document.getElementById('columns-modal');
    var closeBtn = document.getElementById('columns-close');
    btn.addEventListener('click', function() {
        if (modal) modal.classList.remove('hidden');
    });
    if (closeBtn) closeBtn.addEventListener('click', function() {
        if (modal) modal.classList.add('hidden');
    });
    if (modal) modal.addEventListener('click', function(event) {
        if (event.target === modal) modal.classList.add('hidden');
    });

    function currentUrlWithoutAdvanced() {
        var url = new URL(window.location.href);
        url.searchParams.delete('show_advanced'); // legacy param, superseded by the cookie
        return url.toString();
    }
    function saveAndReload(hidden) {
        document.cookie = 'hiddenCols=' + hidden.join(',') + ';path=/;max-age=31536000;samesite=Lax';
        window.location.href = currentUrlWithoutAdvanced();
    }
    function readHiddenCols() {
        var m = document.cookie.match(/(?:^|;\s*)hiddenCols=([^;]*)/);
        return m ? m[1].split(',').filter(Boolean) : null; // null = no cookie yet
    }
    function stagedHidden() {
        var hidden = [];
        var listed = {};
        panel.querySelectorAll('input[data-col]').forEach(function(box) {
            var k = box.getAttribute('data-col');
            listed[k] = true;
            if (!box.checked) hidden.push(k);
        });
        // Preserve hidden keys not currently listed (e.g. star group while metrics are off)
        var current = readHiddenCols();
        if (current !== null) {
            current.forEach(function(k) {
                if (!listed[k] && hidden.indexOf(k) === -1) hidden.push(k);
            });
        }
        return hidden;
    }

    document.getElementById('columns-apply').addEventListener('click', function() {
        saveAndReload(stagedHidden());
    });

    document.getElementById('columns-all').addEventListener('click', function() {
        panel.querySelectorAll('input[data-col]').forEach(function(box) {
            box.checked = true;
        });
    });
    document.getElementById('columns-reset').addEventListener('click', function() {
        // Default: base columns visible, everything else hidden
        panel.querySelectorAll('input[data-col]').forEach(function(box) {
            box.checked = (box.getAttribute('data-group') === 'base');
        });
    });
});
</script>
</form>
