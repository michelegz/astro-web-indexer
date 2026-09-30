<?php
// Project tree partial. Expects $projectTree (getProjectTree) and $projectDiag (diagnoseProjectTree).
function fmtExp(float $seconds): string
{
    if ($seconds >= 3600) {
        return number_format($seconds, 0) . ' s (' . number_format($seconds / 3600, 1) . ' h)';
    }
    return number_format($seconds, 0) . ' s';
}

function calibSummary(array $cals): string
{
    $n = ['DARK' => 0, 'FLAT' => 0, 'BIAS' => 0];
    $pend = 0;
    foreach ($cals as $c) {
        if (!empty($c['pending'])) {
            $pend++;
            continue;
        }
        $t = strtoupper((string)$c['imgtype']);
        if (isset($n[$t])) {
            $n[$t]++;
        }
    }
    $s = 'D:' . $n['DARK'] . ' F:' . $n['FLAT'] . ' B:' . $n['BIAS'];
    if ($pend > 0) {
        $s .= ' (+' . $pend . ' ⏳)';
    }
    return $s;
}

if (empty($projectTree['setups'])): ?>
    <p class="text-sm text-gray-500"><?= __('projects_no_tree') ?></p>
<?php else: ?>
    <?php foreach ($projectTree['setups'] as $setup): ?>
        <details open class="mb-3 border border-gray-700 rounded-lg">
            <summary class="cursor-pointer px-4 py-2 bg-gray-700/50 rounded-t-lg font-semibold">
                <?= __('projects_setup') ?>: <?= htmlspecialchars($setup['label'] !== null && $setup['label'] !== '' ? $setup['label'] : substr((string)$setup['fingerprint'], 0, 48)) ?>
                <span class="ml-2 text-xs font-normal text-gray-400"><?= htmlspecialchars(calibSummary($setup['calibrations'])) ?></span>
            </summary>
            <div class="px-4 py-2">
                <?php foreach ($setup['panels'] as $panel): ?>
                    <?php
                    $coords = ($panel['ra'] !== null && $panel['dec'] !== null)
                        ? number_format((float)$panel['ra'], 3) . ' / ' . number_format((float)$panel['dec'], 3) : '?';
                    $plabel = 'P' . (int)($panel['panel_no'] ?? $panel['id']) . ' (' . $coords . ')';
                    if ($panel['label_object'] !== null && $panel['label_object'] !== '') {
                        $plabel .= ' ' . $panel['label_object'];
                    }
                    ?>
                    <details class="mb-2 border border-gray-700/60 rounded">
                        <summary class="cursor-pointer px-3 py-1.5 hover:bg-gray-700/40 rounded font-medium">
                            <?= __('projects_panel') ?> <?= htmlspecialchars($plabel) ?>
                            <span class="ml-2 text-xs font-normal text-gray-400"><?= htmlspecialchars(calibSummary($panel['calibrations'])) ?></span>
                        </summary>
                        <div class="px-3 py-2">
                            <?php foreach ($panel['sessions'] as $session): ?>
                                <details class="mb-2 border border-gray-700/40 rounded">
                                    <summary class="cursor-pointer px-3 py-1.5 hover:bg-gray-700/40 rounded text-sm">
                                        <?= __('projects_session') ?> <?= htmlspecialchars((string)$session['astro_night']) ?>
                                        <span class="ml-2 text-xs text-gray-400"><?= htmlspecialchars(calibSummary($session['calibrations'])) ?></span>
                                    </summary>
                                    <div class="px-3 py-2">
                                        <?php foreach ($session['filters'] as $filter): ?>
                                            <?php
                                            $realLights = array_values(array_filter($filter['lights'], fn($li) => empty($li['pending'])));
                                            $pendLights = array_values(array_filter($filter['lights'], fn($li) => !empty($li['pending'])));
                                            $worst = null;
                                            foreach ($realLights as $li) {
                                                $d = $projectDiag[(int)$li['file_id']] ?? ['dark' => 'red', 'flat' => 'red', 'bias' => 'red'];
                                                $w = diagWorst($d['dark'], diagWorst($d['flat'], $d['bias']));
                                                $worst = $worst === null ? $w : diagWorst($worst, $w);
                                            }
                                            $realExp = 0.0;
                                            foreach ($realLights as $li) {
                                                $realExp += (float)($li['exptime'] ?? 0);
                                            }
                                            ?>
                                            <div class="mb-2">
                                                <div class="text-sm font-medium mb-1">
                                                    <?= $worst === null ? '⏳' : diagDot($worst) ?> <?= __('projects_filter') ?> <?= htmlspecialchars($filter['name'] !== '' ? $filter['name'] : '—') ?>
                                                    <span class="ml-2 text-xs font-normal text-gray-400">
                                                        <?= htmlspecialchars(__('projects_lights_count', ['count' => count($realLights)])) ?> · <?= htmlspecialchars(fmtExp($realExp)) ?><?php if (!empty($pendLights)): ?> · <?= htmlspecialchars('+' . count($pendLights) . ' ⏳') ?><?php endif; ?>
                                                    </span>
                                                </div>
                                                <?php if (!empty($filter['calibrations'])): ?>
                                                    <div class="text-xs text-gray-500 mb-1"><?= htmlspecialchars(calibSummary($filter['calibrations'])) ?> (<?= __('projects_filter') ?>)</div>
                                                <?php endif; ?>
                                                <div class="overflow-x-auto">
                                                    <table class="w-full text-xs text-left">
                                                        <tbody>
                                                            <?php foreach ($filter['lights'] as $li): ?>
                                                                <?php $isPend = !empty($li['pending']); ?>
                                                                <?php $d = $isPend ? null : ($projectDiag[(int)$li['file_id']] ?? ['dark' => 'red', 'flat' => 'red', 'bias' => 'red']); ?>
                                                                <tr class="border-b border-gray-700/40<?= $isPend ? ' opacity-60' : '' ?>">
                                                                    <td class="py-1 px-2"><?= htmlspecialchars($li['name']) ?><?php if ($isPend): ?> <span title="<?= __('projects_pending_hypo') ?>">⏳</span><?php endif; ?></td>
                                                                    <td class="py-1 px-2 text-right text-gray-400"><?= htmlspecialchars((string)($li['exptime'] ?? '')) ?>s</td>
                                                                    <td class="py-1 px-2 whitespace-nowrap" title="dark/flat/bias"><?= $isPend ? '⏳' : (diagDot($d['dark']) . diagDot($d['flat']) . diagDot($d['bias'])) ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endforeach; ?>
<?php endif; ?>
