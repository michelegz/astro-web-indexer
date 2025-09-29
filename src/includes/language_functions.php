<?php
/**
 * Get the best matching language from the available languages
 */
function getBestLanguage(): string {
    // 1. Se è settata via GET → priorità assoluta
    if (isset($_GET['lang']) && isValidLanguage($_GET['lang'])) {
        $_SESSION['selected_language'] = $_GET['lang'];
        return $_GET['lang'];
    }

    // 2. Altrimenti se in sessione
    if (isset($_SESSION['selected_language'])) {
        return $_SESSION['selected_language'];
    }

    // 3. Provo a matchare la lingua del browser
    $available = getAvailableLanguages();
    $browserLangs = array_map(
        fn($lang) => strtok($lang, ';'),
        explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en')
    );

    foreach ($browserLangs as $browserLang) {
        $shortLang = substr($browserLang, 0, 2);
        if (in_array($shortLang, $available)) {
            return $shortLang;
        }
    }

    // 4. Fallback
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
        'de' => 'Deutsch',
        'fr' => 'Français',
        'es' => 'Español',
    ];
    
    return $names[$code] ?? $code;
}