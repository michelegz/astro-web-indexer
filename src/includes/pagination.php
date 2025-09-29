<div class="flex flex-col sm:flex-row items-center justify-between my-6 bg-gray-800 p-4 rounded-lg shadow-md">
    <div class="text-sm text-gray-400 mb-2 sm:mb-0">
        <?php echo __('page_of', ['current' => $page, 'total' => $totalPages]) ?>
    </div>
    <div class="flex flex-wrap justify-center gap-2">
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