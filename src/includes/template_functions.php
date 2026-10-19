<?php

// Set of collapsible cards and their cookie ids.
define('AWI_CARD_IDS', ['filters', 'stats', 'trend']);

/**
 * Whether a collapsible card has to be rendered OPEN.
 *
 * The state comes from the cookie instead of being hardcoded because the inline
 * script of each card only knows how to TOGGLE: without the cookie a card collapsed
 * by the user would come back expanded and snap shut on the first click, which is a
 * flash of the wrong layout on every single page load.
 *
 * One cookie per card (`card_filters=0`), written by awiSaveCardState() in main.js.
 * A cookie that is missing, empty or unreadable falls back to $defaultOpen, so a card
 * introduced by a later release still starts from its own default instead of
 * inheriting the state of another one.
 *
 * @param string $cardId One of AWI_CARD_IDS.
 * @param bool $defaultOpen State to use when the cookie says nothing.
 */
function awiCardOpen(string $cardId, bool $defaultOpen): bool
{
    $raw = $_COOKIE['card_' . $cardId] ?? null;
    if ($raw === '1') {
        return true;
    }
    if ($raw === '0') {
        return false;
    }
    return $defaultOpen;
}

// The type of the charts of the metrics trend card: 'bar' or 'line'.
//
// The cookie (awi_trend_type) holds the EXPLICIT choice only. Its absence means
// "automatic", and the automatic choice is not a value a cookie could hold: it
// depends on how many points the current query returns (bars up to
// AWI_TREND_AUTO_BAR_MAX files, lines beyond), so it changes with the filters.
//
// That is also why the card offers an "auto" button. A stored choice OVERRIDES
// the automatic one, it does not replace it: without a way back, one click on
// "lines" on a query with 30 files would follow the user forever, including on
// the queries where 900 files make lines the wrong shape.
define('AWI_TREND_AUTO_BAR_MAX', 150);

/**
 * The chart type the user asked for: 'bar', 'line' or 'auto'.
 *
 * A cookie nobody can produce is not read as a choice: it falls back to 'auto',
 * the same as an absent one.
 */
function awiTrendChartMode(): string
{
    $raw = (string)($_COOKIE['awi_trend_type'] ?? '');
    return ($raw === 'bar' || $raw === 'line') ? $raw : 'auto';
}

/**
 * The chart type the automatic choice comes to, for this many points.
 *
 * Bars under ~150 files, lines above: below that threshold the bars are readable,
 * above it they turn into unreadable slivers.
 */
function awiTrendAutoType(int $pointCount): string
{
    return $pointCount <= AWI_TREND_AUTO_BAR_MAX ? 'bar' : 'line';
}

/**
 * The chart type the trend card has to be drawn with.
 *
 * @param int $pointCount How many points the charts are about to draw.
 */
function awiTrendChartType(int $pointCount): string
{
    $mode = awiTrendChartMode();
    return $mode === 'auto' ? awiTrendAutoType($pointCount) : $mode;
}

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