<div class="sidebar fixed inset-y-0 left-0 w-60 bg-gray-800 p-4 overflow-y-auto z-30 transition-transform duration-300 ease-in-out transform -translate-x-full md:translate-x-0" id="sidebar">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-semibold text-white"><?php echo __('directory') ?></h2>
        <!-- Mobile close button -->
        <button class="md:hidden text-gray-400 hover:text-white" onclick="toggleMenu()">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>
    </div>
    
    <?php if ($dir !== ''):
        $parent = dirname($dir);
        if ($parent === '.' || $parent === '/') $parent = '';
    ?>
        <a href="?dir=<?= urlencode($parent) ?>" class="flex items-center gap-2 p-2 hover:bg-gray-700 rounded-md text-blue-400 mb-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12"></path></svg>
            <?php echo __('back') ?>
        </a>
    <?php endif; ?>
    <nav>
        <?php foreach($folders as $f): ?>
            <a href="?dir=<?= urlencode($dir === '' ? $f : $dir.'/'.$f) ?>" class="block p-2 text-gray-300 hover:bg-gray-700 rounded-md truncate">
                <?= htmlspecialchars($f) ?>
            </a>
        <?php endforeach; ?>
        <?php if (empty($folders) && $dir === ''): ?>
            <p class="text-gray-500 text-sm p-2"><?php echo __('no_files_found') ?></p>
        <?php endif; ?>
    </nav>
</div>