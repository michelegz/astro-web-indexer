
<!DOCTYPE html>
<html lang="<?php echo $lang ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo __('site_title') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="/assets/css/output.css" rel="stylesheet">
</head>

<body class="flex flex-col min-h-screen bg-gray-900 text-gray-100 font-sans">
    <header class="bg-gray-700 shadow-md">
        <div class="px-4 py-4 flex items-center justify-between">
                        <div class="flex items-center gap-4">
                <?php
                $customLogoPath = '/var/www/html/assets/logo/custom_logo.svg';
                $defaultLogoPath = 'assets/logo/default_logo.svg';
                $logoPath = file_exists($customLogoPath) ? 'assets/logo/custom_logo.svg' : $defaultLogoPath;
                ?>
                <img src="<?= $logoPath ?>" alt="Logo" class="h-16 w-16">
                <h1 class="text-2xl font-bold tracking-wide"><?php echo __('header_title') ?></h1>
            </div>
            <!-- Language selector -->
            <?php include __DIR__ . '/language_selector.php'; ?>
        </div>
    </header>
