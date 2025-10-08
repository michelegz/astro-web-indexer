<?php
$queryParams = $_GET;
$baseQueryParams = $queryParams;
unset($baseQueryParams['dir']);
$baseQueryString = http_build_query($baseQueryParams);
?>
<!-- Breadcrumbs -->
<nav class="mb-6 text-sm font-medium text-gray-400 p-2 bg-gray-800 rounded" aria-label="Breadcrumb">
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