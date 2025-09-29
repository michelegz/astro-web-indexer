<?php
// Get the best matching language
$lang = getBestLanguage();

// Load language file
$langFile = __DIR__ . '/../languages/' . $lang . '.php';
if (!file_exists($langFile)) {
    die("Language file not found: " . $langFile);
}

// Load strings
$strings = include($langFile);

/**
 * Get a localized string by its key
 * 
 * @param string $key The string identifier
 * @param array $params Optional parameters for string interpolation
 * @return string The localized string
 */
function __($key, $params = []) {
    global $strings;
    
    if (!isset($strings[$key])) {
        return "[[" . $key . "]]";
    }
    
    $text = $strings[$key];
    
    // Replace parameters in format {param}
    if (!empty($params)) {
        foreach ($params as $param => $value) {
            $text = str_replace('{' . $param . '}', $value, $text);
        }
    }
    
    return $text;
}
