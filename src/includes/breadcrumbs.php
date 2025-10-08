<?php
$queryParams = $_GET;
$baseQueryParams = $queryParams;
unset($baseQueryParams['dir']);
$baseQueryString = http_build_query($baseQueryParams);
?>
<!-- Breadcrumbs -->
<div class="flex items-center gap-4 mb-6 bg-gray-800 p-2 rounded-lg">
    <button class="p-2 bg-gray-700 hover:bg-gray-600 rounded text-gray-100 flex items-center gap-2 flex-shrink-0" onclick="toggleMenu()">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>
        <span><?php echo __('directory'); ?></span>
    </button>
    <nav class="text-sm font-medium text-gray-400" aria-label="Breadcrumb">
    <ol class="list-none p-0 inline-flex flex-wrap">
                <li class="flex items-center">
            <a href="?<?= $baseQueryString ?>" class="text-blue-400 hover:text-blue-300">Home</a>
            <?php if (!empty($dir)): ?>
                <span class="mx-2 text-gray-500">/</span>
            <?php endif; ?>
        </li>
                        <?php
        $pathParts = array_filter(explode('/', $dir)); // Use array_filter to remove empty parts
        $currentPath = '';
        $lastPart = end($pathParts);

        foreach ($pathParts as $part) {
            $currentPath .= ($currentPath === '' ? '' : '/') . $part;

            // Only create a link if it's not the last part of the path
            if ($part !== $lastPart) {
                $queryParams['dir'] = $currentPath;
                $queryString = http_build_query($queryParams);
        ?>
            <li class="flex items-center">
                <a href="?<?= $queryString ?>" class="text-blue-400 hover:text-blue-300"><?= htmlspecialchars($part) ?></a>
                <span class="mx-2 text-gray-500">/</span>
            </li>
        <?php
            }
        }
        ?>
        <?php if (!empty($dir)): ?>
        <li class="flex items-center text-gray-300" aria-current="page">
            <span><?= htmlspecialchars($lastPart) ?></span>
        </li>
        <?php endif; ?>
        </ol>
    </nav>
</div>
