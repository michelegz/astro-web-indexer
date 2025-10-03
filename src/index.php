<?php
require_once __DIR__ . '/includes/init.php';
?>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Mobile menu overlay -->
    <div id="menu-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-20 hidden md:hidden" onclick="toggleMenu()"></div>

    <div class="flex-1 md:ml-60 transition-all duration-300 ease-in-out" id="content-area">
        <?php include __DIR__ . '/includes/header.php'; ?>
        
        <main class="p-4">
            <!-- Mobile toggle -->
            <button class="md:hidden p-2 bg-gray-700 hover:bg-gray-600 rounded text-gray-100 mb-4" onclick="toggleMenu()">
                ☰ <?php echo __('menu_directory'); ?>
            </button>

            <?php 
            include __DIR__ . '/includes/breadcrumbs.php';
            include __DIR__ . '/includes/filters.php';
            include __DIR__ . '/includes/pagination.php';
            include __DIR__ . '/includes/table.php';
            include __DIR__ . '/includes/pagination.php';
            ?>
        </main>
        <?php include __DIR__ . '/includes/footer.php'; ?>
    </div>
</div>

<?php 
include __DIR__ . '/includes/preview_overlay.php';
?>
<script src="assets/js/main.js"></script>
<script src="assets/js/preview.js"></script>

</body>
</html>