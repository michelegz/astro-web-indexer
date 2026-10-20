<?php
// Check — a collapsible card must come back in the state the user left it in,
// and the three signals that express that state must never disagree.
//
//   index.php -> includes/filters.php       (filters-toggle / filters-body)
//             -> includes/statistics.php    (stats-toggle   / stats-body)
//             -> includes/metrics_chart.php (trend-toggle   / trend-body)
//
// Each card says whether it is open three times: aria-expanded on the toggle, the
// `hidden` class on the body, and the rotation of the chevron. PHP reads the cookie
// (awiCardOpen(), template_functions.php) and writes all three at render time; the
// inline script only ever toggles. The reason is the flash: if the body came back
// open while the chevron came back closed, the user clicks a card that looks
// collapsed, it closes, and a card they never touched has swallowed the archive.
//
// So the invariant checked here is «the three signals agree», for every cookie state
// and every card, and not «which state is correct»: the defaults are asserted
// separately because they are a product decision, not an implementation detail.
//
// The list of cards is not written twice: AWI_CARD_IDS is the one the PHP helper
// declares, so a fourth card joins this gate the moment it is registered there.
//
// Reads only: filters.php asks the DB for the dropdown values, exactly like the real
// page, and nothing is written anywhere.
//
// Usage:
//   docker cp tests/php/card_state_check.php awi-php:/tmp/harness/
//   docker exec awi-php sh -c 'cd /tmp/harness && php card_state_check.php'

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once '/var/www/html/includes/config.php';

$lang = DEFAULT_LANGUAGE;
$strings = include '/var/www/html/languages/' . $lang . '.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/columns.php';
// buildDirPermissionFilter(), which getDistinctValues() calls: without this the
// rendering of filters.php dies on an undefined function.
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/template_functions.php';

$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-54s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

/** The state a card must have when the cookie says nothing. */
$defaults = ['filters' => true, 'stats' => false, 'trend' => false, 'indexer' => false];

// The fixtures filters.php, statistics.php and metrics_chart.php read. Set once here
// and pulled into the rendering scope by renderCard(): a partial included from inside
// a function runs in THAT scope, so it never sees $GLOBALS on its own — leaving them
// global produced a page full of "Undefined variable" and nothing to assert on.
$fixture = [
    'conn' => connectDB(),
    'dir' => '',
    'lang' => $lang,
    'sortBy' => 'name',
    'sortOrder' => 'ASC',
    'filterObject' => '',
    'filterFilter' => '',
    'filterImgtype' => '',
    'filterInstrume' => '',
    'filterTelescop' => '',
    'dateObsFrom' => '',
    'dateObsTo' => '',
    'exptimeMin' => '',
    'exptimeMax' => '',
    'showStarMetrics' => true,
    'totalExposure' => 7200.0,
    // The keys statistics.php reads: cnt for the count, total for the seconds.
    'filterStats' => [
        ['filter_name' => 'Ha', 'cnt' => 12, 'total' => 3600.0],
        ['filter_name' => 'OIII', 'cnt' => 8, 'total' => 3600.0],
    ],
    'lightRecords' => 20,
    'starTrend' => [
        ['name' => 'light-001.fits', 'hfr' => 2.51, 'fwhm' => 3.14, 'hfr_sd' => 0.21,
            'eccentricity' => 0.18, 'star_count' => 1840, 'snr_weight' => 0.72, 'psf_signal' => 1234.5],
        ['name' => 'light-002.fits', 'hfr' => 2.02, 'fwhm' => 3.01, 'hfr_sd' => 0.19,
            'eccentricity' => 0.21, 'star_count' => 1910, 'snr_weight' => 0.68, 'psf_signal' => 1180.0],
    ],
    // The status card's default is dynamic (open while a run is active), so the
    // fixture pins it to "idle, no run" and therefore to the collapsed default.
    'indexerStatus' => [
        'available' => true,
        'current' => null,
        'last' => null,
        'history' => [],
        'watcher' => null,
        'archive' => ['metrics_pending' => 0],
    ],
];

$partials = [
    'filters' => 'filters.php',
    'stats' => 'statistics.php',
    'trend' => 'metrics_chart.php',
    'indexer' => 'indexer_status.php',
];

/**
 * Renders one card's partial with a given cookie and reports the three signals.
 *
 * @return array{expanded: ?string, hidden: ?bool, rotated: ?bool, diagnostics: string}
 */
function renderCard(string $card, ?string $cookie): array
{
    global $partials, $fixture;

    // Pulled into this scope: an included partial runs here, not in the global scope.
    extract($fixture, EXTR_SKIP);

    // Only this card's cookie is set: a leftover card_<other> from the previous
    // scenario would prove nothing about this one.
    foreach (array_keys($partials) as $other) {
        unset($_COOKIE['card_' . $other]);
    }
    if ($cookie !== null) {
        $_COOKIE['card_' . $card] = $cookie;
    }

    $level = ob_get_level();
    ob_start();
    try {
        include '/var/www/html/includes/' . $partials[$card];
        $html = (string)ob_get_clean();
    } finally {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
    }

    // A PHP diagnostic printed inside the HTML is read as markup by the parser
    // (same reason table_escape_check.php checks it on its own).
    $diag = preg_match('#\b(Warning|Notice|Deprecated|Fatal error|Parse error)\b\s*:[^\n]{0,240}?\bon line \d+#i',
        $html, $m) ? trim(preg_replace('/\s+/', ' ', $m[0])) : '';

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);

    $toggle = $xp->query('//*[@id="' . $card . '-toggle"]')->item(0);
    $body = $xp->query('//*[@id="' . $card . '-body"]')->item(0);
    $chevron = $xp->query('//*[@id="' . $card . '-chevron"]')->item(0);

    if (!$toggle || !$body || !$chevron) {
        return ['expanded' => null, 'hidden' => null, 'rotated' => null, 'diagnostics' => $diag];
    }

    $tokens = preg_split('/\s+/', trim($body->getAttribute('class')));
    return [
        'expanded' => $toggle->getAttribute('aria-expanded'),
        'hidden' => in_array('hidden', $tokens, true),
        // Open means the arrow points up. The rotation is an inline style, so it is
        // absent on a collapsed card: read it off the attribute, not off a default.
        'rotated' => str_contains($chevron->getAttribute('style'), 'rotate(180deg)'),
        'diagnostics' => $diag,
    ];
}

foreach (AWI_CARD_IDS as $card) {
    if (!isset($defaults[$card])) {
        check("card '$card' is registered with a default", false, 'missing from the test table');
    }
}
check('AWI_CARD_IDS and the test table describe the same cards',
    array_values(AWI_CARD_IDS) === array_keys($defaults),
    implode(', ', AWI_CARD_IDS));

// A list of [cookie value, label], NOT a map: PHP casts the array key '0' to the
// integer 0, and awiCardOpen() compares strictly on purpose (a real cookie always
// arrives as a string). With a map the cookie under test arrived as an int, was
// rejected as unreadable, and every card fell back to its default — a test that
// went green on the exact values it was written to prove.
// null = the cookie is absent, which must land on the card's own default.
$scenarios = [
    [null, 'no cookie'],
    ['0', 'card_x=0'],
    ['1', 'card_x=1'],
    ['nonsense', 'card_x=nonsense'],
    ['', 'card_x= (empty)'],
];

foreach ($defaults as $card => $defaultOpen) {
    echo "\n=== $card (default " . ($defaultOpen ? 'open' : 'collapsed') . ") ===\n";
    foreach ($scenarios as [$cookie, $label]) {
        $r = renderCard($card, $cookie);
        if ($r['expanded'] === null) {
            check("  $label: the card rendered with its three elements", false,
                'toggle, body or chevron not found');
            continue;
        }
        // A cookie nobody can produce must not be read as a state: it falls back
        // to the default like an absent one, instead of collapsing or opening the
        // card on a value we never wrote.
        $expected = ($cookie === '0') ? false : (($cookie === '1') ? true : $defaultOpen);
        $want = $expected ? 'true' : 'false';

        check("  $label: aria-expanded agrees", $r['expanded'] === $want, "aria-expanded={$r['expanded']}");
        check("  $label: the body agrees", $r['hidden'] === !$expected, $r['hidden'] ? 'hidden' : 'visible');
        check("  $label: the chevron agrees", $r['rotated'] === $expected, $r['rotated'] ? 'rotated' : 'down');
        check("  $label: no PHP diagnostic", $r['diagnostics'] === '', $r['diagnostics']);
    }
}

echo "\n=== the cards do not share one cookie ===\n";
// One card collapsed must not move the others: a single shared cookie would make
// this fail, and the user would open Filtri and find Statistiche gone too.
$_COOKIE['card_stats'] = '0';
$trend = renderCard('trend', null);
check('collapsing stats leaves trend on its default', $trend['expanded'] === 'false', "trend aria-expanded={$trend['expanded']}");
$filters = renderCard('filters', null);
check('collapsing stats leaves filters on its default', $filters['expanded'] === 'true', "filters aria-expanded={$filters['expanded']}");
unset($_COOKIE['card_stats']);

echo "\n=== the toggle survives the remembered state ===\n";
// The inline script only TOGGLES. If a card is rendered open, the first click has
// to close it — which it does, because the DOM it toggles is the DOM the server
// wrote. What must NOT happen is a partial that renders the body open while leaving
// the chevron down, since the chevron is the only thing telling the user where the
// card is. Covered above; here the script must still be wired to the shared binder.
$level = ob_get_level();
ob_start();
try {
    extract($fixture, EXTR_SKIP);
    include '/var/www/html/includes/statistics.php';
    $html = (string)ob_get_clean();
} finally {
    while (ob_get_level() > $level) {
        ob_end_clean();
    }
}
check("statistics.php binds through awiBindCard('stats')", str_contains($html, "awiBindCard('stats')"), '');

echo "\nRESULT: " . ($failed
    ? 'FAILED: ' . implode(', ', $failed)
    : 'every card comes back as the user left it, and its three signals agree')
    . "\n";
exit($failed ? 1 : 0);