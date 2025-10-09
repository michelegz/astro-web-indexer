<?php
// Helper function for table sorting icon
function echoIcon(string $column, string $sortBy, string $sortOrder): void {
    if ($sortBy === $column) {
        echo $sortOrder === 'ASC' ? ' ▲' : ' ▼';
    }
}

// Helper function for generating pagination links
function getPaginationLink(array $query, int $pageNum, string $text, string $classes = ''): string {
    $query['page'] = $pageNum;
    $queryString = http_build_query($query);
    return '<a href="?' . $queryString . '" class="flex items-center justify-center px-3 h-8 leading-tight text-blue-300 bg-gray-700 border border-gray-600 rounded-lg hover:bg-gray-600 hover:text-white ' . $classes . '">' . $text . '</a>';
}

/**
 * Generates HTML markup for moon phase emoji with a tooltip.
 *
 * @param float|null $angle Moon phase angle in degrees (0-360).
 * @param float|null $phase Moon illumination percentage.
 * @return string The HTML span element.
 */
function getMoonPhaseMarkup(?float $angle, ?float $phase): string {
    if ($angle === null || $phase === null) {
        return '<span>N/A</span>';
    }

    // Determine the correct emoji based on the angle
    if ($angle >= 337.5 || $angle < 22.5) {
        $emoji = '🌑'; // New Moon - centrato a 0°
    } elseif ($angle < 67.5) {
        $emoji = '🌒'; // Waxing Crescent - centrato a 45°
    } elseif ($angle < 112.5) {
        $emoji = '🌓'; // First Quarter - centrato a 90°
    } elseif ($angle < 157.5) {
        $emoji = '🌔'; // Waxing Gibbous - centrato a 135°
    } elseif ($angle < 202.5) {
        $emoji = '🌕'; // Full Moon - centrato a 180°
    } elseif ($angle < 247.5) {
        $emoji = '🌖'; // Waning Gibbous - centrato a 225°
    } elseif ($angle < 292.5) {
        $emoji = '🌗'; // Last Quarter - centrato a 270°
    } else {
        $emoji = '🌘'; // Waning Crescent - centrato a 315°
    }
   
    
    //return "<div class=\"flex flex-col items-center \"></span><span>{$emoji}</span><span class=\"text-xs\">"  . number_format($phase, 0) . "% - " . number_format($angle, 0) . "°</span></div>";
    return "<div class=\"flex flex-col items-center gap-2\"></span><span>{$emoji}</span><span class=\"text-xs \">"  . number_format($phase, 0) . "%</span></div>";
}

?>