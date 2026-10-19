<?php
// Card collapsible con l'andamento delle metriche stellari sulle immagini
// attualmente filtrate, nello stesso ordinamento della tabella
// (asse X = posizione nel risultato ordinato). $starTrend da init.php.
$trendKeys = ['hfr', 'fwhm', 'hfr_sd', 'eccentricity', 'star_count', 'snr_weight', 'psf_signal'];
$hasTrendData = false;
foreach ($starTrend as $row) {
    foreach ($trendKeys as $k) {
        if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) { $hasTrendData = true; break 2; }
    }
}
if ($hasTrendData):
    $trendCount = $lightRecords ?? count($starTrend);
    $trendConfig = [
        ['key' => 'hfr',          'label' => __('hfr') . ' (px)',     'color' => '#60a5fa'],
        ['key' => 'fwhm',         'label' => __('fwhm') . ' (arcsec)', 'color' => '#34d399'],
        ['key' => 'hfr_sd',       'label' => __('hfr_sd') . ' (px)',  'color' => '#a78bfa'],
        ['key' => 'eccentricity', 'label' => __('eccentricity'),       'color' => '#fbbf24'],
        ['key' => 'star_count',   'label' => __('star_count'),         'color' => '#f472b6'],
        ['key' => 'snr_weight',   'label' => __('snr_weight'),         'color' => '#22d3ee'],
        ['key' => 'psf_signal',   'label' => __('psf_signal'),         'color' => '#fb7185'],
    ];
    $trendLabels = [];
    foreach ($trendConfig as &$cfg) {
        $vals = [];
        foreach ($starTrend as $row) {
            $v = $row[$cfg['key']] ?? null;
            $vals[] = ($v === null || $v === '') ? null : (float)$v;
        }
        $cfg['values'] = $vals;
    }
    unset($cfg);
    foreach ($starTrend as $row) {
        $trendLabels[] = $row['name'] ?? '';
    }
    $trendPayload = ['labels' => $trendLabels, 'series' => $trendConfig];
    // Collapsed by the user on a previous visit? Default: collapsed, seven charts
    // is a lot of pixels above the table.
    $cardOpen = awiCardOpen('trend', false);
    // The chart type: the explicit choice from the cookie, or the automatic one
    // (bars up to AWI_TREND_AUTO_BAR_MAX points, lines beyond). Both the mode and
    // the type it comes to are rendered here, so the page comes back drawn the way
    // the user left it and the script below never has to know the threshold.
    $trendPoints = count($starTrend);
    $trendMode = awiTrendChartMode();
    $trendType = awiTrendChartType($trendPoints);
    $trendAutoType = awiTrendAutoType($trendPoints);
    // Whether the cookie is there at all. An absent cookie means the preference
    // was never expressed in one, and the script below may still find it in the
    // old localStorage; a cookie that is there (even a broken one) is the user's
    // answer, and the old value must not be allowed to overrule it.
    $trendCookiePresent = isset($_COOKIE['awi_trend_type']);
?>
<script src="assets/js/vendor/chart.umd.min.js"></script>
<div class="bg-gray-800 rounded-lg shadow-md mb-6 overflow-hidden">
    <div class="w-full flex flex-wrap items-center justify-between gap-2 p-4">
    <button type="button"
            id="trend-toggle"
            aria-expanded="<?= $cardOpen ? 'true' : 'false' ?>"
            aria-controls="trend-body"
            class="flex-1 flex flex-wrap items-center justify-between gap-2 hover:bg-gray-700/50 transition-colors text-left rounded">
        <span class="flex items-center gap-2 font-semibold text-gray-100">
            <span aria-hidden="true">📈</span>
            <span><?php echo __('metrics_trend'); ?></span>
            <span class="text-sm font-normal text-gray-400">
                <?php echo __('metrics_trend_summary', ['count' => $trendCount]); ?>
            </span>
        </span>
        <svg id="trend-chevron" class="w-5 h-5 text-gray-400 transition-transform duration-200"<?= $cardOpen ? ' style="transform: rotate(180deg)"' : '' ?> fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
    </button>
    <div class="flex items-center bg-gray-700 rounded-lg" role="group" aria-label="<?php echo __('trend_chart_type'); ?>">
        <button type="button" id="trend-type-line" class="px-2 py-1 text-sm rounded-l-lg hover:bg-blue-700 transition-colors<?= $trendType === 'line' ? ' bg-blue-600' : '' ?>" title="<?php echo __('trend_lines'); ?>" aria-label="<?php echo __('trend_lines'); ?>" aria-pressed="<?= $trendType === 'line' ? 'true' : 'false' ?>">📈</button>
        <button type="button" id="trend-type-bar" class="px-2 py-1 text-sm hover:bg-blue-700 transition-colors<?= $trendType === 'bar' ? ' bg-blue-600' : '' ?>" title="<?php echo __('trend_bars'); ?>" aria-label="<?php echo __('trend_bars'); ?>" aria-pressed="<?= $trendType === 'bar' ? 'true' : 'false' ?>">📊</button>
        <?php // The third button is not a shape: it is the absence of a choice. It
        // puts the type back under the rule of the data (bars up to the threshold,
        // lines beyond), which a stored choice can only override. Without it the
        // first click on "lines" would follow the user forever, including on the
        // queries where lines are the wrong shape. The letter A says "automatic"
        // more plainly than an icon would next to the two chart shapes. ?>
        <button type="button" id="trend-type-auto" class="px-2 py-1 text-sm font-semibold rounded-r-lg hover:bg-blue-700 transition-colors<?= $trendMode === 'auto' ? ' bg-blue-600' : '' ?>" title="<?php echo __('trend_auto_hint', ['n' => AWI_TREND_AUTO_BAR_MAX]); ?>" aria-label="<?php echo __('trend_auto'); ?>" aria-pressed="<?= $trendMode === 'auto' ? 'true' : 'false' ?>">A</button>
    </div>
    </div>

    <div id="trend-body" class="border-t border-gray-700 p-4 flex flex-col gap-6<?= $cardOpen ? '' : ' hidden' ?>">
        <?php foreach ($trendConfig as $i => $cfg): ?>
        <?php // data-scroll-anchor: the ▲▼ buttons of this block are usually clicked
        // with the table still off screen, so this is what the scroll memory anchors
        // on. Without it the anchor would be the first table row, thousands of
        // pixels below the fold, and the restore would aim at nothing. See
        // awiAnchorInView() in main.js. ?>
        <div data-scroll-anchor="trend-<?= $i ?>">
            <?php // The metric name moves out of the canvas and into this header so
            // the ▲▼ buttons can sit next to it, exactly like the integration
            // group charts on the projects page. Clicking one re-sorts the file
            // table (same path as clicking the column header), and the next
            // render repaints every chart in that new order: the card's promise
            // is "charts follow the table order", so sorting one chart behind
            // the table's back would break it. ?>
            <div class="flex items-center justify-between mb-1">
                <span class="text-xs font-bold" style="color: #e5e7eb;"><?php echo htmlspecialchars($cfg['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="flex gap-1">
                    <button type="button"
                            class="trend-sort text-gray-400 hover:text-white text-xs px-1<?= $sortBy === $cfg['key'] && strtoupper($sortOrder) === 'ASC' ? ' text-white' : '' ?>"
                            onclick="sortTable('<?php echo htmlspecialchars($cfg['key'], ENT_QUOTES, 'UTF-8'); ?>', 'ASC')"
                            title="<?php echo __('trend_sort_asc'); ?>">▲</button>
                    <button type="button"
                            class="trend-sort text-gray-400 hover:text-white text-xs px-1<?= $sortBy === $cfg['key'] && strtoupper($sortOrder) === 'DESC' ? ' text-white' : '' ?>"
                            onclick="sortTable('<?php echo htmlspecialchars($cfg['key'], ENT_QUOTES, 'UTF-8'); ?>', 'DESC')"
                            title="<?php echo __('trend_sort_desc'); ?>">▼</button>
                </span>
            </div>
            <div style="height: 210px"><canvas id="trend-chart-<?= $i ?>"></canvas></div>
        </div>
        <?php endforeach; ?>
        <p id="trend-nolibs" class="hidden text-sm text-gray-400"></p>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var body = document.getElementById('trend-body');
    var lineBtn = document.getElementById('trend-type-line');
    var barBtn = document.getElementById('trend-type-bar');
    var autoBtn = document.getElementById('trend-type-auto');
    if (!body) return;
    // Rendered by PHP from the same cookie the server read: the mode is the
    // explicit choice (bar, line) or 'auto', and autoType is what 'auto' comes to
    // with this many points. The threshold itself lives in PHP only.
    var chartMode = <?= json_encode($trendMode, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    var chartType = <?= json_encode($trendType, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    var autoType = <?= json_encode($trendAutoType, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    // Whether the cookie is there at all. The old localStorage value is adopted
    // only when it is not: a cookie that is there is the user's answer, and the
    // value left over from before the move must not overrule it - not even when
    // the answer is "automatic".
    var cookiePresent = <?= $trendCookiePresent ? 'true' : 'false' ?>;
    var chartsBuilt = false;
    var trendCharts = [];

    // A user who had already chosen a type before it moved to the cookie keeps it:
    // the old value is adopted once, on the first load after the change.
    if (!cookiePresent && typeof awiAdoptLegacyTrendType === 'function') {
        var legacy = awiAdoptLegacyTrendType();
        if (legacy) {
            chartMode = legacy;
            chartType = legacy;
            if (typeof awiSaveTrendType === 'function') awiSaveTrendType(legacy);
        }
    }

    function refreshTypeButtons() {
        // Two buttons can be lit at once, and they mean different things: the type
        // button says which shape is on the screen, the auto button says that the
        // shape was derived from the data instead of being chosen.
        if (lineBtn) {
            lineBtn.classList.toggle('bg-blue-600', chartType === 'line');
            lineBtn.setAttribute('aria-pressed', chartType === 'line' ? 'true' : 'false');
        }
        if (barBtn) {
            barBtn.classList.toggle('bg-blue-600', chartType === 'bar');
            barBtn.setAttribute('aria-pressed', chartType === 'bar' ? 'true' : 'false');
        }
        if (autoBtn) {
            autoBtn.classList.toggle('bg-blue-600', chartMode === 'auto');
            autoBtn.setAttribute('aria-pressed', chartMode === 'auto' ? 'true' : 'false');
        }
    }
    function setChartType(mode) {
        chartMode = (mode === 'bar' || mode === 'line') ? mode : 'auto';
        chartType = chartMode === 'auto' ? autoType : chartMode;
        if (typeof awiSaveTrendType === 'function') awiSaveTrendType(chartMode);
        refreshTypeButtons();
        if (!body.classList.contains('hidden')) buildTrendCharts();
    }
    if (lineBtn) lineBtn.addEventListener('click', function() { setChartType('line'); });
    if (barBtn) barBtn.addEventListener('click', function() { setChartType('bar'); });
    if (autoBtn) autoBtn.addEventListener('click', function() { setChartType('auto'); });
    refreshTypeButtons();

    // The charts are drawn on the first reveal, not at load: the card is closed by
    // default and Chart.js on seven canvases nobody is looking at is wasted work.
    function buildOnce() {
        if (chartsBuilt) return;
        chartsBuilt = true;
        buildTrendCharts();
    }
    awiBindCard('trend', buildOnce);
    // ...but a card the user left open comes back OPEN from the cookie and nobody
    // clicks it: without this the card would open onto seven blank canvases, and
    // buildTrendCharts() would never run until the first collapse/reopen.
    if (!body.classList.contains('hidden')) buildOnce();

    function median(values) {
        var nums = values.filter(function(v) { return typeof v === 'number' && isFinite(v); }).sort(function(a, b) { return a - b; });
        if (nums.length === 0) return null;
        var mid = Math.floor(nums.length / 2);
        return nums.length % 2 ? nums[mid] : (nums[mid - 1] + nums[mid]) / 2;
    }

    function fmtMedian(v) {
        if (v === null) return '';
        return Math.abs(v) >= 0.01 ? v.toFixed(2) : v.toPrecision(4);
    }

    function buildTrendCharts() {
        if (typeof Chart === 'undefined') {
            var msg = document.getElementById('trend-nolibs');
            if (msg) {
                msg.textContent = 'Chart.js not loaded';
                msg.classList.remove('hidden');
            }
            return;
        }
        trendCharts.forEach(function(c) { try { c.destroy(); } catch (e) {} });
        trendCharts = [];
        var payload = <?= json_encode($trendPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        var medianLabel = <?= json_encode(__('metrics_median'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        var gridColor = 'rgba(255,255,255,0.08)';
        var tickColor = '#9ca3af';

        payload.series.forEach(function(s, i) {
            var canvas = document.getElementById('trend-chart-' + i);
            if (!canvas) return;
            var med = median(s.values);
            var datasets = [{
                label: s.label,
                data: s.values,
                borderColor: s.color,
                backgroundColor: s.color,
                borderWidth: chartType === 'bar' ? 0 : 1.5,
                pointRadius: 0,
                pointHoverRadius: 5,
                tension: 0,
                spanGaps: true
            }];
            if (med !== null) {
                datasets.push({
                    label: medianLabel + ' (' + fmtMedian(med) + ')',
                    type: 'line',
                    data: new Array(s.values.length).fill(med),
                    borderColor: '#9ca3af',
                    borderWidth: 1.5,
                    borderDash: [6, 4],
                    pointRadius: 0,
                    pointHoverRadius: 0,
                    tension: 0,
                    spanGaps: true
                });
            }
            trendCharts.push(new Chart(canvas, {
                type: chartType,
                data: {
                    labels: payload.labels.map(function(_, idx) { return idx + 1; }),
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'nearest', intersect: false },
                    plugins: {
                        // The metric name is rendered by the header above the
                        // canvas (it hosts the ▲▼ sort buttons), so the title
                        // plugin stays off to avoid printing it twice.
                        title: { display: false },
                        legend: { labels: { color: tickColor, boxWidth: 20 } },
                        tooltip: {
                            callbacks: {
                                title: function(items) {
                                    if (!items.length) return '';
                                    var idx = items[0].dataIndex;
                                    return '#' + (idx + 1) + ' ' + (payload.labels[idx] || '');
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            title: { display: false },
                            grid: { color: gridColor },
                            ticks: { color: tickColor, maxTicksLimit: 10 }
                        },
                        y: {
                            grid: { color: gridColor },
                            ticks: { color: tickColor, maxTicksLimit: 6 },
                            grace: '5%'
                        }
                    }
                }
            }));
        });
    }
});
</script>
<?php endif; ?>
