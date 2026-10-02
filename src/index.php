<?php
require_once __DIR__ . '/includes/init.php';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

    <div class="flex flex-col md:flex-row min-h-screen">

        <div class="flex-1 min-w-0 transition-all duration-300 ease-in-out">        
            <main class="p-4">
                <?php 
                include __DIR__ . '/includes/breadcrumbs.php';
                include __DIR__ . '/includes/filters.php';
                include __DIR__ . '/includes/statistics.php';
                include __DIR__ . '/includes/metrics_chart.php';
                include __DIR__ . '/includes/pagination.php';
                include __DIR__ . '/includes/table.php';
                include __DIR__ . '/includes/pagination.php';
                ?>
            </main>
            <?php include __DIR__ . '/includes/footer.php'; ?>
        </div>
    </div>
</div> <!-- End of content-area -->
<?php 
include __DIR__ . '/includes/sff_modal.php'; // Include the SFF modal
?>
<?php
// Cache-busting for JS bundles: browsers cache /assets/js/* aggressively and
// a stale main.js (e.g. old auto-submit logic) breaks new UI. The query string
// changes on every image build, forcing a fresh download.
$jsVersion = max(
    @filemtime(__DIR__ . '/assets/js/main.js') ?: 0,
    @filemtime(__DIR__ . '/assets/js/sff.js') ?: 0
);
?>
<script src="assets/js/main.js?v=<?= $jsVersion ?>"></script>
<script src="assets/js/astrobin_export.js?v=<?= @filemtime(__DIR__ . '/assets/js/astrobin_export.js') ?: $jsVersion ?>"></script>
<script src="assets/js/sff.js?v=<?= $jsVersion ?>"></script> <!-- Include the new SFF script -->

</body>
</html>