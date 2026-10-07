<div class="flex flex-col sm:flex-row items-center justify-between my-6 bg-gray-800 p-4 rounded-lg shadow-md">
    <div class="text-sm text-gray-400 mb-2 sm:mb-0">
        <?php echo __('page_of', ['current' => $page, 'total' => $totalPages]) ?>
        <span class="mx-2">|</span>
        <?php echo __('total_records', ['total' => $totalRecords]) ?>
        <span class="mx-2">|</span>
        <?php echo __('total_exposure', ['total' => round($totalExposure / 3600, 2)]) ?>
    </div>
    <div class="flex flex-wrap items-center justify-center gap-2">
        <label class="flex items-center gap-2 text-sm text-gray-400">
            <?php echo __('elements_per_page') ?>:
            <select class="per-page-select appearance-none bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2 pr-8 bg-no-repeat bg-right" style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%236b7280\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 0.5rem center; background-size: 1.5em 1.5em;">
                <?php foreach(PER_PAGE_OPTIONS as $option): ?>
                    <option value="<?= $option ?>" <?= $option==$perPage?'selected':'' ?>><?= $option ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php
        $currentPage = $page;
        $total = $totalPages;
        $query = array_merge($_GET, []); // Copy current GET parameters

        // Previous
        if ($currentPage > 1) {
            echo getPaginationLink($query, $currentPage - 1, '← ' . __('previous'));
        } else {
            echo '<span class="flex items-center justify-center px-3 h-8 leading-tight text-gray-500 bg-gray-700 border border-gray-600 rounded-lg cursor-not-allowed">← ' . __('previous') . '</span>';
        }

        // Page numbers
        $start = max(1, $currentPage - 2);
        $end = min($total, $currentPage + 2);

        if ($start > 1) {
            echo getPaginationLink($query, 1, '1');
            if ($start > 2) {
                echo '<span class="px-3 h-8 leading-tight text-gray-400">...</span>';
            }
        }

        for ($p = $start; $p <= $end; $p++) {
            if ($p == $currentPage) {
                echo '<span class="flex items-center justify-center px-3 h-8 text-white bg-blue-600 border border-blue-600 rounded-lg shadow-lg">' . $p . '</span>';
            } else {
                echo getPaginationLink($query, $p, (string)$p);
            }
        }

        if ($end < $total) {
            if ($end < $total - 1) {
                echo '<span class="px-3 h-8 leading-tight text-gray-400">...</span>';
            }
            echo getPaginationLink($query, $total, (string)$total);
        }

        // Next
        if ($currentPage < $total) {
            echo getPaginationLink($query, $currentPage + 1, __('next') . ' →');
        } else {
            echo '<span class="flex items-center justify-center px-3 h-8 leading-tight text-gray-500 bg-gray-700 border border-gray-600 rounded-lg cursor-not-allowed">' . __('next') . ' →</span>';
        }
        ?>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // pagination.php is included twice (above + below the table): bind once.
    if (window.__awiPerPageInit) return;
    window.__awiPerPageInit = true;
    document.querySelectorAll('.per-page-select').forEach(function(sel) {
        sel.addEventListener('change', function() {
            var url = new URL(window.location.href);
            url.searchParams.set('per_page', sel.value);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        });
    });
});
</script>