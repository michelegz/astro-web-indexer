<div class="sidebar fixed inset-y-0 left-0 w-60 bg-gray-800 p-4 overflow-y-auto z-30 transition-transform duration-300 ease-in-out transform -translate-x-full" id="sidebar">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-semibold text-white"><?php echo __('directory') ?></h2>
        <!-- Mobile close button -->
        <button class="md:hidden text-gray-400 hover:text-white" onclick="toggleMenu()">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>
    </div>
    
    
                    <nav id="folder-tree">
        <?php
        /**
         * Renders the folder tree recursively.
         *
         * @param array $folderTree The nested array of folders.
         * @param string $activeDir The currently active directory filter.
         * @param string $basePath The path prefix for the current recursion level.
         */
        function render_folder_tree(array $folderTree, string $activeDir, string $basePath = ''): void
        {
            foreach ($folderTree as $folderName => $subfolders) {
                $fullPath = ($basePath === '') ? $folderName : $basePath . '/' . $folderName;

                // Determine the state of the folder
                $isActive = ($fullPath === $activeDir);
                // An ancestor is a folder whose path is a prefix of the active directory's path.
                // The check ensures `FILTER` is not considered an ancestor of `FILTER_B`.
                $isAncestor = str_starts_with($activeDir, $fullPath . '/');
                $isOpen = $isActive || $isAncestor;

                // Build query params for the filter link
                $queryParams = $_GET;
                $queryParams['dir'] = $fullPath;
                $queryParams['page'] = 1;
                $filterHref = '?' . http_build_query($queryParams);

                // Define CSS classes based on state
                $folderToggleClasses = 'folder-toggle flex-grow cursor-pointer p-2 hover:bg-gray-700 rounded-l-md truncate';
                if ($isActive) {
                    $folderToggleClasses .= ' bg-blue-800 font-semibold text-white';
                }
        ?>
                <div class="folder-item flex justify-between items-center text-gray-300 rounded-md">
                    <span class="<?= $folderToggleClasses ?>">
                        <?= htmlspecialchars($folderName) ?>
                    </span>
                    <a href="<?= htmlspecialchars($filterHref) ?>" class="filter-link p-2 hover:bg-gray-600 rounded-r-md text-white" title="<?= __('filter_by_folder') ?>">
                    ▶️
                    </a>
                </div>
                                <div class="subfolders ml-4 <?= !$isOpen ? 'hidden' : '' ?>">
                    <?php
                    // Recursive call for subfolders
                    if (!empty($subfolders)) {
                        render_folder_tree($subfolders, $activeDir, $fullPath);
                    } else {
                        // Show a message if there are no subfolders to display.
                        echo '<span class="p-2 text-gray-500 text-sm">' . __('no_subfolders') . '</span>';
                    }
                    ?>
                </div>
        <?php
            }
        }

        if (!empty($folders)) {
            render_folder_tree($folders, $dir ?? '');
        } elseif (($dir ?? '') === '') {
            echo '<p class="text-gray-500 text-sm p-2">' . __('no_files_found') . '</p>';
        }
        ?>
    </nav>
</div>