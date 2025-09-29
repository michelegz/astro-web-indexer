<?php
$queryParams = $_GET;
$baseQueryParams = $queryParams;
unset($baseQueryParams['dir']);
$baseQueryString = http_build_query($baseQueryParams);
?>
<!-- Breadcrumbs -->
<nav class="mb-6 text-sm font-medium text-gray-400" aria-label="Breadcrumb">
    <ol class="list-none p-0 inline-flex flex-wrap">
        <li class="flex items-center">
            <a href="?<?= $baseQueryString ?>" class="text-blue-400 hover:text-blue-300">Home</a>
            <span class="mx-2 text-gray-500">/</span>
        </li>
        <?php
        $pathParts = explode('/', $dir);
        $currentPath = '';
        foreach ($pathParts as $part) {
            if (empty($part)) continue;
            $currentPath .= ($currentPath === '' ? '' : '/') . $part;
            $queryParams['dir'] = $currentPath;
            $queryString = http_build_query($queryParams);
        ?>
            <li class="flex items-center">
                <a href="?<?= $queryString ?>" class="text-blue-400 hover:text-blue-300"><?= htmlspecialchars($part) ?></a>
                <span class="mx-2 text-gray-500">/</span>
            </li>
        <?php
        }
        ?>
        <li class="flex items-center text-gray-300">
            <span><?= empty($dir) ? __('home') : basename($dir) ?></span>
        </li>
    </ol>
</nav>