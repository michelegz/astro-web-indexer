<!-- Breadcrumbs -->
<nav class="mb-6 text-sm font-medium text-gray-400" aria-label="Breadcrumb">
    <ol class="list-none p-0 inline-flex flex-wrap">
        <li class="flex items-center">
            <a href="?" class="text-blue-400 hover:text-blue-300">Home</a>
            <span class="mx-2 text-gray-500">/</span>
        </li>
        <?php
        $pathParts = explode('/', $dir);
        $currentPath = '';
        foreach ($pathParts as $part) {
            if (empty($part)) continue;
            $currentPath .= ($currentPath === '' ? '' : '/') . $part;
        ?>
            <li class="flex items-center">
                <a href="?dir=<?= urlencode($currentPath) ?>" class="text-blue-400 hover:text-blue-300"><?= htmlspecialchars($part) ?></a>
                <span class="mx-2 text-gray-500">/</span>
            </li>
        <?php
        }
        ?>
        <li class="flex items-center text-gray-300">
            <span><?= empty($dir) ? __('home') : __('current_directory') ?></span>
        </li>
    </ol>
</nav>