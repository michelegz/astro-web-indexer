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
 */
function getMoonPhaseMarkup(?float $angle, ?float $phase): string {
    if ($angle === null || $phase === null) return '<span>N/A</span>';

    if ($angle >= 337.5 || $angle < 22.5) $emoji = '🌑';
    elseif ($angle < 67.5) $emoji = '🌒';
    elseif ($angle < 112.5) $emoji = '🌓';
    elseif ($angle < 157.5) $emoji = '🌔';
    elseif ($angle < 202.5) $emoji = '🌕';
    elseif ($angle < 247.5) $emoji = '🌖';
    elseif ($angle < 292.5) $emoji = '🌗';
    else $emoji = '🌘';
    
    return "<div class=\"flex flex-col items-center gap-2\"><span title=\"" . number_format($angle, 0) . "°\">{$emoji}</span><span class=\"text-xs\">" . number_format($phase, 0) . "%</span></div>";
}

/**
 * Renders a table header with a translated label and a tooltip.
 *
 * @param string $sortKey The key for sorting (DB column name).
 * @param string $labelKey The key for translation.
 * @param string $sortBy Current sort column.
 * @param string $sortOrder Current sort order.
 * @param bool|null $isCalculated True for calculated, false for FITS header, null for general.
 */
function render_header_with_tooltip(string $sortKey, string $labelKey, string $sortBy, string $sortOrder, ?bool $isCalculated = null): void {
    $label = __($labelKey);
    $tooltip = '';

    if ($isCalculated === true) {
        $tooltip = __('calculated_by_app');
    } elseif ($isCalculated === false) {
        $tooltip = '[ ' . strtoupper($sortKey) . ' ]';
    }

    $titleAttribute = $tooltip ? ' title="' . htmlspecialchars($tooltip) . '"' : '';

    echo '<th class="p-3 whitespace-nowrap cursor-pointer hover:bg-gray-600"' . $titleAttribute . ' onclick="sortTable(\'' . htmlspecialchars($sortKey) . '\')">';
    echo htmlspecialchars($label);
    echoIcon($sortKey, $sortBy, $sortOrder);
    echo '</th>';
}

?>