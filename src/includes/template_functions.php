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
    if ($angle >= 355 || $angle < 5) {
        $emoji = '🌑'; // New Moon
    } elseif ($angle < 85) {
        $emoji = '🌒'; // Waxing Crescent
    } elseif ($angle < 95) {
        $emoji = '🌓'; // First Quarter
    } elseif ($angle < 175) {
        $emoji = '🌔'; // Waxing Gibbous
    } elseif ($angle < 185) {
        $emoji = '🌕'; // Full Moon
    } elseif ($angle < 265) {
        $emoji = '🌖'; // Waning Gibbous
    } elseif ($angle < 275) {
        $emoji = '🌗'; // Last Quarter
    } else {
        $emoji = '🌘'; // Waning Crescent
    }

    $tooltip = __('moon_phase_tooltip', ['phase' => number_format($phase, 1), 'angle' => number_format($angle, 1)]);
    
    return "<span title=\"{$tooltip}\">{$emoji}</span>";
}

?>