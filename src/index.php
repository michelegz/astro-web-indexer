<?php
require_once __DIR__ . '/includes/init.php';
?>

<div class="flex flex-col md:flex-row min-h-screen">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Mobile menu overlay -->
    <div id="menu-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-20 hidden md:hidden" onclick="toggleMenu()"></div>

    <div class="flex-1 transition-all duration-300 ease-in-out" id="content-area">
        <?php include __DIR__ . '/includes/header.php'; ?>
        
        <main class="p-4">
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
include __DIR__ . '/includes/sff_modal.php'; // Include the SFF modal
?>
<script src="assets/js/main.js"></script>
<script src="assets/js/preview.js"></script>
<script src="assets/js/sff.js"></script> <!-- Include the new SFF script -->

</body>
</html>