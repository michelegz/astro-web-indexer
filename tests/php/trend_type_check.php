<?php
// Check — the type of the metrics trend charts: the explicit choice wins, the
// automatic one follows the number of points, and the three buttons say which
// of the two is in force.
//
//   includes/template_functions.php  awiTrendChartMode()  bar | line | auto
//                                   awiTrendChartType()  what is drawn
//                                   awiTrendAutoType()  what "auto" comes to
//   includes/metrics_chart.php      the three buttons, rendered
//   assets/js/main.js               awiSaveTrendType(), awiAdoptLegacyTrendType()
//
// The default is NOT a constant: it depends on how many points the current query
// returns (bars up to AWI_TREND_AUTO_BAR_MAX files, lines above), so it changes
// with the filters. That is the whole reason the cookie holds the EXPLICIT
// choice only, and why an unreadable cookie has to fall back to "automatic"
// instead of being taken for a choice nobody made.
//
// The invariant checked here is «the buttons and the cookie agree», for every
// cookie value and both sides of the threshold.
//
// Reads only: the charts are drawn from a synthetic $starTrend, nothing is
// written anywhere.
//
// Usage:
//   docker cp tests/php/trend_type_check.php awi-php:/tmp/harness/
//   docker exec awi-php sh -c 'cd /tmp/harness && php trend_type_check.php'

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once '/var/www/html/includes/config.php';

$lang = DEFAULT_LANGUAGE;
$strings = include '/var/www/html/languages/' . $lang . '.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
require_once '/var/www/html/includes/template_functions.php';

$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-56s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

/** A $starTrend with $n points, each with the metrics the charts read. */
function makeTrend(int $n): array
{
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $rows[] = [
            'name' => sprintf('light-%03d.fits', $i + 1),
            'hfr' => 2.5 - ($i % 7) * 0.1,
            'fwhm' => 3.1,
            'hfr_sd' => 0.2,
            'eccentricity' => 0.18,
            'star_count' => 1800 + $i,
            'snr_weight' => 0.7,
            'psf_signal' => 1200.0,
        ];
    }
    return $rows;
}

/**
 * Renders the trend card with a given cookie and a given number of points.
 *
 * @return array{buttons: array<string, array{class: string, pressed: string}>, diagnostics: string}
 */
function renderCard(?string $cookie, int $points): array
{
    $fixture = [
        'lightRecords' => $points,
        'starTrend' => makeTrend($points),
        'sortBy' => 'name',
        'sortOrder' => 'ASC',
    ];

    if ($cookie === null) {
        unset($_COOKIE['awi_trend_type']);
    } else {
        $_COOKIE['awi_trend_type'] = $cookie;
    }

    $level = ob_get_level();
    ob_start();
    try {
        // The partial included from inside this function runs in THIS scope, so
        // the fixtures have to be pulled into it explicitly.
        extract($fixture, EXTR_SKIP);
        include '/var/www/html/includes/metrics_chart.php';
        $html = (string)ob_get_clean();
    } finally {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
    }

    // A PHP diagnostic printed inside the HTML is read as markup by the parser.
    $diag = preg_match('#\b(Warning|Notice|Deprecated|Fatal error|Parse error)\b\s*:[^\n]{0,240}?\bon line \d+#i',
        $html, $m) ? trim(preg_replace('/\s+/', ' ', $m[0])) : '';

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOWARNING);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);

    $buttons = [];
    foreach (['line', 'bar', 'auto'] as $which) {
        $btn = $xp->query('//*[@id="trend-type-' . $which . '"]')->item(0);
        if (!$btn) {
            continue;
        }
        $buttons[$which] = [
            'class' => (string)$btn->getAttribute('class'),
            'pressed' => (string)$btn->getAttribute('aria-pressed'),
        ];
    }

    return ['buttons' => $buttons, 'diagnostics' => $diag, 'html' => $html];
}

echo "=== the cookie holds the explicit choice, and only that ===\n";
// A list of [cookie value, label], NOT a map: PHP casts the array key '0' to the
// integer 0, and the comparison is strict on purpose (a real cookie always
// arrives as a string). With a map the cookie under test arrived as an int, was
// rejected as unreadable, and every scenario fell back to the default - a test
// that went green on the exact values it was written to prove.
$scenarios = [
    [null, 'no cookie'],
    ['bar', 'awi_trend_type=bar'],
    ['line', 'awi_trend_type=line'],
    ['auto', 'awi_trend_type=auto'],
    ['nonsense', 'awi_trend_type=nonsense'],
    ['', 'awi_trend_type= (empty)'],
    ['BAR', 'awi_trend_type=BAR'],
];
$modes = [
    'no cookie' => 'auto',
    'awi_trend_type=bar' => 'bar',
    'awi_trend_type=line' => 'line',
    'awi_trend_type=auto' => 'auto',
    'awi_trend_type=nonsense' => 'auto',
    'awi_trend_type= (empty)' => 'auto',
    'awi_trend_type=BAR' => 'auto',
];
foreach ($scenarios as [$cookie, $label]) {
    $_COOKIE['awi_trend_type'] = $cookie;
    if ($cookie === null) {
        unset($_COOKIE['awi_trend_type']);
    }
    $mode = awiTrendChartMode();
    check("  $label: the mode is '{$modes[$label]}'", $mode === $modes[$label], "mode={$mode}");
}

echo "\n=== the automatic choice follows the number of points ===\n";
check('the threshold is a single constant', defined('AWI_TREND_AUTO_BAR_MAX'), '');
check('and it is the one the old script used', AWI_TREND_AUTO_BAR_MAX === 150, (string)AWI_TREND_AUTO_BAR_MAX);
check('few points: bars', awiTrendAutoType(3) === 'bar', awiTrendAutoType(3));
check('exactly at the threshold: bars', awiTrendAutoType(AWI_TREND_AUTO_BAR_MAX) === 'bar',
    awiTrendAutoType(AWI_TREND_AUTO_BAR_MAX));
check('one point over the threshold: lines', awiTrendAutoType(AWI_TREND_AUTO_BAR_MAX + 1) === 'line',
    awiTrendAutoType(AWI_TREND_AUTO_BAR_MAX + 1));
check('many points: lines', awiTrendAutoType(900) === 'line', awiTrendAutoType(900));

echo "\n=== an explicit choice overrides the automatic one ===\n";
$_COOKIE['awi_trend_type'] = 'bar';
check('bars, with few points', awiTrendChartType(3) === 'bar', awiTrendChartType(3));
check('bars, with many points', awiTrendChartType(900) === 'bar', awiTrendChartType(900));
$_COOKIE['awi_trend_type'] = 'line';
check('lines, with few points', awiTrendChartType(3) === 'line', awiTrendChartType(3));
check('lines, with many points', awiTrendChartType(900) === 'line', awiTrendChartType(900));
$_COOKIE['awi_trend_type'] = 'auto';
check('auto, with few points', awiTrendChartType(3) === 'bar', awiTrendChartType(3));
check('auto, with many points', awiTrendChartType(900) === 'line', awiTrendChartType(900));

echo "\n=== the buttons say what the cookie says ===\n";
$cases = [
    // [cookie, points, lit type, auto lit]
    [null, 3, 'bar', true],
    [null, 900, 'line', true],
    ['bar', 3, 'bar', false],
    ['bar', 900, 'bar', false],
    ['line', 3, 'line', false],
    ['line', 900, 'line', false],
    ['auto', 3, 'bar', true],
    ['auto', 900, 'line', true],
    ['nonsense', 900, 'line', true],
];
foreach ($cases as [$cookie, $points, $lit, $autoLit]) {
    $label = ($cookie === null ? 'no cookie' : $cookie) . ", $points points";
    $r = renderCard($cookie, $points);
    if (count($r['buttons']) !== 3) {
        check("  $label: the three buttons are rendered", false, count($r['buttons']) . ' found');
        continue;
    }
    check("  $label: no PHP diagnostic", $r['diagnostics'] === '', $r['diagnostics']);
    foreach (['line', 'bar'] as $which) {
        $isLit = str_contains($r['buttons'][$which]['class'], 'bg-blue-600');
        $pressed = $r['buttons'][$which]['pressed'] === 'true';
        check("  $label: the '$which' button is " . ($which === $lit ? 'lit' : 'unlit'),
            $isLit === ($which === $lit), $r['buttons'][$which]['class']);
        check("  $label: the '$which' button agrees with aria-pressed",
            $pressed === $isLit, 'aria-pressed=' . $r['buttons'][$which]['pressed']);
    }
    $autoIsLit = str_contains($r['buttons']['auto']['class'], 'bg-blue-600');
    check("  $label: the auto button is " . ($autoLit ? 'lit' : 'unlit'),
        $autoIsLit === $autoLit, $r['buttons']['auto']['class']);
    check("  $label: the auto button agrees with aria-pressed",
        ($r['buttons']['auto']['pressed'] === 'true') === $autoIsLit,
        'aria-pressed=' . $r['buttons']['auto']['pressed']);
    // The old localStorage value is adopted only when the cookie is not there: a
    // cookie that is there is the user's answer, and the value left over from
    // before the move must not overrule it, not even when the answer is "auto".
    $wantPresent = ($cookie !== null);
    $rendered = (bool)preg_match('/var cookiePresent = (true|false);/', $r['html'], $m);
    check("  $label: the page says whether the cookie is there",
        $m[1] === ($wantPresent ? 'true' : 'false'), 'cookiePresent=' . ($m[1] ?? '?'));
}

echo "\n=== the old home of the preference is gone ===\n";
$php = (string)file_get_contents('/var/www/html/includes/metrics_chart.php');
check('the partial no longer reads the preference from localStorage',
    !str_contains($php, "localStorage.getItem('awiTrendType')"), '');
check('and it does not write it there either',
    !str_contains($php, "localStorage.setItem('awiTrendType'"), '');
check('the partial asks the server-rendered mode', str_contains($php, 'var chartMode ='), '');
check('and the automatic type comes from the server too', str_contains($php, 'var autoType ='), '');

echo "\nRESULT: " . ($failed
    ? 'FAILED: ' . implode(', ', $failed)
    : 'the choice wins, the automatic one follows the data, and the buttons agree')
    . "\n";
exit($failed ? 1 : 0);
