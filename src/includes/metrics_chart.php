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
?>
<script src="assets/js/vendor/chart.umd.min.js"></script>
<div class="bg-gray-800 rounded-lg shadow-md mb-6 overflow-hidden">
    <button type="button"
            id="trend-toggle"
            aria-expanded="false"
            aria-controls="trend-body"
            class="w-full flex items-center justify-between p-4 hover:bg-gray-700/50 transition-colors text-left">
        <span class="flex items-center gap-2 font-semibold text-gray-100">
            <span aria-hidden="true">📈</span>
            <span><?php echo __('metrics_trend'); ?></span>
            <span class="text-sm font-normal text-gray-400">
                <?php echo __('metrics_trend_summary', ['count' => $trendCount]); ?>
            </span>
        </span>
        <svg id="trend-chevron" class="w-5 h-5 text-gray-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
    </button>

    <div id="trend-body" class="hidden border-t border-gray-700 p-4 flex flex-col gap-6">
        <?php foreach ($trendConfig as $i => $cfg): ?>
        <div>
            <div style="height: 210px"><canvas id="trend-chart-<?= $i ?>"></canvas></div>
        </div>
        <?php endforeach; ?>
        <p id="trend-nolibs" class="hidden text-sm text-gray-400"></p>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var toggle = document.getElementById('trend-toggle');
    var body = document.getElementById('trend-body');
    var chevron = document.getElementById('trend-chevron');
    if (!toggle || !body) return;
    var chartsBuilt = false;
    toggle.addEventListener('click', function() {
        var isHidden = body.classList.toggle('hidden');
        toggle.setAttribute('aria-expanded', isHidden ? 'false' : 'true');
        if (chevron) chevron.style.transform = isHidden ? '' : 'rotate(180deg)';
        if (!isHidden && !chartsBuilt) {
            chartsBuilt = true;
            buildTrendCharts();
        }
    });

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
                borderWidth: 1.5,
                pointRadius: 0,
                pointHoverRadius: 5,
                tension: 0,
                spanGaps: true
            }];
            if (med !== null) {
                datasets.push({
                    label: medianLabel + ' (' + fmtMedian(med) + ')',
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
            new Chart(canvas, {
                type: 'line',
                data: {
                    labels: payload.labels.map(function(_, idx) { return idx + 1; }),
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'nearest', intersect: false },
                    plugins: {
                        title: { display: true, text: s.label, color: '#e5e7eb', font: { size: 13, weight: 'bold' } },
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
            });
        });
    }
});
</script>
<?php endif; ?>
