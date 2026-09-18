<?php
// Card collapsible con esposizione totale + percentuale per filtro,
// calcolate sulle immagini attualmente filtrate ($filterStats da init.php).
// Non renderizzare nulla se non ci sono dati.
if (!empty($filterStats) && $totalExposure > 0):
    $statsCount = count($filterStats);
?>
<div class="bg-gray-800 rounded-lg shadow-md mb-6 overflow-hidden">
    <button type="button"
            id="stats-toggle"
            aria-expanded="false"
            aria-controls="stats-body"
            class="w-full flex items-center justify-between p-4 hover:bg-gray-700/50 transition-colors text-left">
        <span class="flex items-center gap-2 font-semibold text-gray-100">
            <span aria-hidden="true">📊</span>
            <span><?php echo __('statistics_by_filter'); ?></span>
            <span class="text-sm font-normal text-gray-400">
                <?php echo __('statistics_summary', ['count' => $statsCount]); ?>
            </span>
        </span>
        <svg id="stats-chevron" class="w-5 h-5 text-gray-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
    </button>

    <div id="stats-body" class="hidden border-t border-gray-700">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-700/50 text-gray-300">
                    <tr>
                        <th class="p-3"><?php echo __('filter'); ?></th>
                        <th class="p-3 text-right"><?php echo __('statistics_images'); ?></th>
                        <th class="p-3 text-right"><?php echo __('statistics_exposure'); ?></th>
                        <th class="p-3 text-right w-24"><?php echo __('statistics_percentage'); ?></th>
                        <th class="p-3 w-1/3 min-w-[160px]"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $barColors = ['bg-blue-500', 'bg-emerald-500', 'bg-amber-500', 'bg-fuchsia-500', 'bg-cyan-500', 'bg-rose-500', 'bg-lime-500', 'bg-orange-500'];
                    foreach ($filterStats as $i => $s):
                        $pct = $totalExposure > 0 ? ($s['total'] / $totalExposure * 100) : 0;
                        $hours = round($s['total'] / 3600, 2);
                        $label = trim((string)($s['filter_name'] ?? ''));
                        if ($label === '') $label = __('statistics_no_filter');
                        $barColor = $barColors[$i % count($barColors)];
                    ?>
                    <tr class="border-b border-gray-700/50 last:border-0 hover:bg-gray-700/30">
                        <td class="p-3 font-medium text-gray-100">
                            <span class="inline-block w-2.5 h-2.5 rounded-full <?= $barColor ?> mr-2 align-middle" aria-hidden="true"></span><?= htmlspecialchars($label) ?>
                        </td>
                        <td class="p-3 text-right text-gray-300"><?= (int)$s['cnt'] ?></td>
                        <td class="p-3 text-right text-gray-300" title="<?= (int)$s['total'] ?>s"><?= htmlspecialchars((string)$hours) ?>h</td>
                        <td class="p-3 text-right text-gray-300 font-semibold"><?= number_format($pct, 1) ?>%</td>
                        <td class="p-3">
                            <div class="w-full bg-gray-700 rounded-full h-3.5 overflow-hidden" role="progressbar" aria-valuenow="<?= round($pct, 1) ?>" aria-valuemin="0" aria-valuemax="100" title="<?= htmlspecialchars($label) ?>: <?= number_format($pct, 1) ?>%">
                                <div class="<?= $barColor ?> h-3.5 rounded-full transition-all" style="width: <?= max(0, min(100, $pct)) ?>%"></div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var toggle = document.getElementById('stats-toggle');
    var body = document.getElementById('stats-body');
    var chevron = document.getElementById('stats-chevron');
    if (!toggle || !body) return;
    toggle.addEventListener('click', function() {
        var isHidden = body.classList.toggle('hidden');
        toggle.setAttribute('aria-expanded', isHidden ? 'false' : 'true');
        if (chevron) chevron.style.transform = isHidden ? '' : 'rotate(180deg)';
    });
});
</script>
<?php endif; ?>
