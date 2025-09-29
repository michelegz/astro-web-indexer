
<!DOCTYPE html>
<html lang="<?php echo $lang ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo __('site_title') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
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


    </style>
</head>

    <header class="bg-gray-700 shadow-md">
        <div class="max-w-7xl mx-auto px-4 py-4 flex items-center justify-between">
            <!-- Title -->
            <h1 class="text-2xl font-bold tracking-wide"><?php echo __('header_title') ?></h1>
            <!-- Language selector -->
            <?php include __DIR__ . '/language_selector.php'; ?>
        </div>
    </header>
    
    
<body class="flex flex-col min-h-screen bg-gray-900 text-gray-100 font-sans">