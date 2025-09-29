<?php
/**
 * Get the best matching language from the available languages
 */
function getBestLanguage(): string {
    // If language is explicitly set in the session
    if (isset($_SESSION['selected_language'])) {
        return $_SESSION['selected_language'];
    }

    // If language is set via GET parameter
    if (isset($_GET['lang']) && isValidLanguage($_GET['lang'])) {
        $_SESSION['selected_language'] = $_GET['lang'];
        return $_GET['lang'];
    }

    // Check browser preferred languages
    $available = getAvailableLanguages();
    $browserLangs = array_map(
        function($lang) { return strtok($lang, ';'); },
        explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en')
    );

    foreach ($browserLangs as $browserLang) {
        $shortLang = substr($browserLang, 0, 2);
        if (in_array($shortLang, $available)) {
            return $shortLang;
        }
    }

    // Fallback to default language
    return defined('DEFAULT_LANGUAGE') ? DEFAULT_LANGUAGE : 'en';
}

/**
 * Get list of available languages
 */
function getAvailableLanguages(): array {
    $languages = [];
    $langPath = __DIR__ . '/../languages/';
    
    foreach (glob($langPath . '*.php') as $file) {
        $lang = basename($file, '.php');
        $languages[] = $lang;
    }
    
    return $languages;
}

/**
 * Check if a language is valid
 */
function isValidLanguage(string $lang): bool {
    return in_array($lang, getAvailableLanguages());
}

/**
 * Get language name from code
 */
function getLanguageName(string $code): string {
    $names = [
        'it' => 'Italiano',
        'en' => 'English',
        // Add more languages here as needed
    ];
    
    return $names[$code] ?? $code;
}