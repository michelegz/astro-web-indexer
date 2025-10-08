
<!DOCTYPE html>
<html lang="<?php echo $lang ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo __('site_title') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="/assets/css/output.css" rel="stylesheet">
    <style>

        /* Stili per la scrollbar del sidebar */
        .sidebar {
            scrollbar-width: thin; /* Firefox */
            scrollbar-color: #4b5563 #1f2937; /* thumb e track */
        }
        .sidebar::-webkit-scrollbar {
            width: 8px;
        }
        .sidebar::-webkit-scrollbar-track {
            background: #1f2937; /* gray-800 */
        }
        .sidebar::-webkit-scrollbar-thumb {
            background-color: #4b5563; /* gray-600 */
            border-radius: 4px;
            border: 2px solid #1f2937;
        }
        /* Stili per l'overlay del menu mobile */
        #menu-overlay {
            transition: opacity 0.3s ease-in-out;
        }
        #menu-overlay.hidden {
            opacity: 0;
            pointer-events: none;
        }
        
        #preview-overlay {
            position: fixed;
        }

        /* View Mode Styles */
        .view-container {
            transition: all 0.3s ease;
        }
        
        /* Thumbnail View */
        .thumbnail-view {
            display: none; /* Hidden by default */
        }
        
        .thumbnail-view:not(.hidden) {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 16px;
        }
        
        .thumbnail-view .thumb-card {
            background-color: #1f2937;
            border-radius: 8px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            height: 100%;
        }
        
        .thumbnail-view .thumb-image-container {
            position: relative;
            background-color: #111827;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 12px;
        }
        
        .thumbnail-view .thumb-checkbox {
            position: absolute;
            top: 8px;
            left: 8px;
            z-index: 5;
        }
        
        .thumbnail-view .thumb-details {
            padding: 12px;
        }
        
        .thumbnail-view .thumb-title {
            font-weight: 600;
            color: #e5e7eb;
            margin-bottom: 8px;
            word-break: break-all;
        }
        
        .thumbnail-view .thumb-meta {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            font-size: 0.8rem;
        }
        
        .thumbnail-view .meta-item {
            display: flex;
            flex-direction: column;
        }
        
        .thumbnail-view .meta-label {
            color: #9ca3af;
            font-size: 0.7rem;
        }
        
        .thumbnail-view .meta-value {
            color: #d1d5db;
        }
        
        /* Thumbnail size variations */
        .thumb-size-1 .thumbnail-view {
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        }
        
        .thumb-size-2 .thumbnail-view {
            grid-template-columns: repeat(auto-fill, minmax(225px, 1fr));
        }
        
        .thumb-size-3 .thumbnail-view {
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        }
        
        .thumb-size-4 .thumbnail-view {
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        }
        
        .thumb-size-5 .thumbnail-view {
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        }
        
        /* List view thumbnail sizes */
        .list-view .thumb {
            transition: all 0.3s ease;
        }
        
        .thumb-size-1 .list-view .thumb {
            max-width: 80px;
        }
        
        .thumb-size-2 .list-view .thumb {
            max-width: 120px;
        }
        
        .thumb-size-3 .list-view .thumb {
            max-width: 150px;
        }
        
        .thumb-size-4 .list-view .thumb {
            max-width: 200px;
        }
        
        .thumb-size-5 .list-view .thumb {
            max-width: 250px;
        }


    </style>
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
