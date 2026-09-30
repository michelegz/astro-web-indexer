<?php
// Project tree partial. Expects $projectTree (getProjectTree) and $projectDiag (diagnoseProjectTree).
// Every linked file row carries a checkbox (value "fileId:level:nodeId") for the
// bulk form in projects.php; group checkboxes sit OUTSIDE <summary> elements
// (clicks inside <summary> are swallowed by the details toggle in browsers)
// inside a .tnode wrapper that scopes their subtree.
function fmtExp(float $seconds): string
{
    if ($seconds >= 3600) {
        return number_format($seconds, 0) . ' s (' . number_format($seconds / 3600, 1) . ' h)';
    }
    return number_format($seconds, 0) . ' s';
}

function linkKey(array $li, string $level, int $node): string
{
    return (int)$li['file_id'] . ':' . $level . ':' . $node;
}

/**
 * Calibration status box: letter (B/D/F) on green/yellow/red background.
 */
function diagBox(string $status, string $letter, string $title): string
{
    $bg = $status === 'green' ? 'bg-green-700' : ($status === 'yellow' ? 'bg-yellow-700' : 'bg-red-700');
    return '<span title="' . htmlspecialchars($title) . '" class="inline-block w-5 text-center text-[11px] font-bold text-white rounded ' . $bg . '">'
        . htmlspecialchars($letter) . '</span>';
}

function calibSummary(array $cals): string
{
    $n = ['DARK' => 0, 'FLAT' => 0, 'BIAS' => 0];
    $pend = 0;
    $off = 0;
    foreach ($cals as $c) {
        if (!empty($c['pending'])) {
            $pend++;
            continue;
        }
        if (empty($c['enabled'])) {
            $off++;
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
    if ($off > 0) {
        $s .= ' (+' . $off . ' ' . __('projects_link_off') . ')';
    }
    return $s;
}

function renderCalRows(array $cals, string $level, int $node): void
{
    $rows = array_values(array_filter($cals, fn($c) => isset($c['file_id'])));
    if (empty($rows)) {
        return;
    }
    ?>
    <ul class="flex flex-col gap-0.5 mb-1">
        <?php foreach ($rows as $c): ?>
            <?php $isPend = !empty($c['pending']); ?>
            <?php $isOff = !$isPend && empty($c['enabled']); ?>
            <li class="flex items-center gap-2 text-xs border-b border-gray-700/40 py-0.5<?= ($isPend || $isOff) ? ' opacity-60' : '' ?>">
                <?php if (!$isPend): ?>
                    <input type="checkbox" name="link_keys[]" value="<?= htmlspecialchars(linkKey($c, $level, $node)) ?>" class="pfl-check rounded bg-gray-600 border-gray-500">
                <?php endif; ?>
                <span class="text-gray-500">[<?= htmlspecialchars($c['imgtype']) ?>]</span>
                <span><?= htmlspecialchars($c['name']) ?></span>
                <?php if ($isPend): ?><span title="<?= __('projects_pending_hypo') ?>">⏳</span><?php endif; ?>
                <?php if ($isOff): ?><span class="text-gray-500">(<?= __('projects_link_off') ?>)</span><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
}

if (empty($projectTree['setups'])): ?>
    <p class="text-sm text-gray-500"><?= __('projects_no_tree') ?></p>
<?php else: ?>
    <?php foreach ($projectTree['setups'] as $setup): ?>
        <div class="tnode mb-3">
            <div class="flex items-start gap-2">
                <input type="checkbox" class="pgroup-check mt-3 rounded bg-gray-600 border-gray-500" title="<?= __('projects_select_group') ?>">
                <details open class="flex-1 min-w-0 border border-gray-700 rounded-lg">
                    <summary class="cursor-pointer px-4 py-2 bg-gray-700/50 rounded-t-lg font-semibold">
                        <?= __('projects_setup') ?>: <?= htmlspecialchars($setup['label'] !== null && $setup['label'] !== '' ? $setup['label'] : substr((string)$setup['fingerprint'], 0, 48)) ?>
                        <span class="ml-2 text-xs font-normal text-gray-400"><?= htmlspecialchars(calibSummary($setup['calibrations'])) ?></span>
                    </summary>
                    <div class="px-4 py-2">
                        <?php renderCalRows($setup['calibrations'], 'setup', (int)$setup['id']); ?>
                        <?php foreach ($setup['panels'] as $panel): ?>
                            <?php
                            $coords = ($panel['ra'] !== null && $panel['dec'] !== null)
                                ? number_format((float)$panel['ra'], 3) . ' / ' . number_format((float)$panel['dec'], 3) : '?';
                            $plabel = 'P' . (int)($panel['panel_no'] ?? $panel['id']) . ' (' . $coords . ')';
                            if ($panel['label_object'] !== null && $panel['label_object'] !== '') {
                                $plabel .= ' ' . $panel['label_object'];
                            }
                            ?>
                            <div class="tnode mb-2">
                                <div class="flex items-start gap-2">
                                    <input type="checkbox" class="pgroup-check mt-2 rounded bg-gray-600 border-gray-500" title="<?= __('projects_select_group') ?>">
                                    <details class="flex-1 min-w-0 border border-gray-700/60 rounded">
                                        <summary class="cursor-pointer px-3 py-1.5 hover:bg-gray-700/40 rounded font-medium">
                                            <?= __('projects_panel') ?> <?= htmlspecialchars($plabel) ?>
                                            <span class="ml-2 text-xs font-normal text-gray-400"><?= htmlspecialchars(calibSummary($panel['calibrations'])) ?></span>
                                        </summary>
                                        <div class="px-3 py-2">
                                            <?php renderCalRows($panel['calibrations'], 'panel', (int)$panel['id']); ?>
                                            <?php foreach ($panel['sessions'] as $session): ?>
                                                <div class="tnode mb-2">
                                                    <div class="flex items-start gap-2">
                                                        <input type="checkbox" class="pgroup-check mt-2 rounded bg-gray-600 border-gray-500" title="<?= __('projects_select_group') ?>">
                                                        <details class="flex-1 min-w-0 border border-gray-700/40 rounded">
                                                            <summary class="cursor-pointer px-3 py-1.5 hover:bg-gray-700/40 rounded text-sm">
                                                                <?= __('projects_session') ?> <?= htmlspecialchars((string)$session['astro_night']) ?>
                                                                <span class="ml-2 text-xs text-gray-400"><?= htmlspecialchars(calibSummary($session['calibrations'])) ?></span>
                                                            </summary>
                                                            <div class="px-3 py-2">
                                                                <?php renderCalRows($session['calibrations'], 'session', (int)$session['id']); ?>
                                                                <?php foreach ($session['filters'] as $filter): ?>
                                            <?php
                                            $realLights = array_values(array_filter($filter['lights'], fn($li) => empty($li['pending']) && !empty($li['enabled'])));
                                            $pendLights = array_values(array_filter($filter['lights'], fn($li) => !empty($li['pending'])));
                                            $offLights = array_values(array_filter($filter['lights'], fn($li) => empty($li['pending']) && empty($li['enabled'])));
                                            $worstB = $worstD = $worstF = null;
                                            foreach ($realLights as $li) {
                                                $d = $projectDiag[(int)$li['file_id']] ?? ['dark' => 'red', 'flat' => 'red', 'bias' => 'red'];
                                                $worstB = $worstB === null ? $d['bias'] : diagWorst($worstB, $d['bias']);
                                                $worstD = $worstD === null ? $d['dark'] : diagWorst($worstD, $d['dark']);
                                                $worstF = $worstF === null ? $d['flat'] : diagWorst($worstF, $d['flat']);
                                            }
                                                                    $realExp = 0.0;
                                                                    foreach ($realLights as $li) {
                                                                        $realExp += (float)($li['exptime'] ?? 0);
                                                                    }
                                                                    ?>
                                                                    <div class="tnode mb-2">
                                                                        <div class="flex items-start gap-2">
                                                                            <input type="checkbox" class="pgroup-check mt-1 rounded bg-gray-600 border-gray-500" title="<?= __('projects_select_group') ?>">
                                                                            <div class="flex-1 min-w-0">
                                                <div class="text-sm font-medium mb-1">
                                                    <?php if ($worstB === null): ?><?= empty($pendLights) ? '' : '⏳' ?><?php else: ?><?= diagBox($worstB, 'B', __('projects_cal_bias')) ?><?= diagBox($worstD, 'D', __('projects_cal_dark')) ?><?= diagBox($worstF, 'F', __('projects_cal_flat')) ?><?php endif; ?> <?= __('projects_filter') ?> <?= htmlspecialchars($filter['name'] !== '' ? $filter['name'] : '—') ?>
                                                                                    <span class="ml-2 text-xs font-normal text-gray-400">
                                                                                        <?= htmlspecialchars(__('projects_lights_count', ['count' => count($realLights)])) ?> · <?= htmlspecialchars(fmtExp($realExp)) ?><?php if (!empty($pendLights)): ?> · <?= htmlspecialchars('+' . count($pendLights) . ' ⏳') ?><?php endif; ?><?php if (!empty($offLights)): ?> · <?= htmlspecialchars('+' . count($offLights) . ' ' . __('projects_link_off')) ?><?php endif; ?>
                                                                                    </span>
                                                                                </div>
                                                                                <?php renderCalRows($filter['calibrations'], 'filter', (int)$session['id']); ?>
                                                                                <div class="overflow-x-auto">
                                                                                    <table class="w-full text-xs text-left">
                                                                                        <tbody>
                                                                                            <?php foreach ($filter['lights'] as $li): ?>
                                                                                                <?php $isPend = !empty($li['pending']); ?>
                                                                                                <?php $isOff = !$isPend && empty($li['enabled']); ?>
                                                                                                <?php $d = ($isPend || $isOff) ? null : ($projectDiag[(int)$li['file_id']] ?? ['dark' => 'red', 'flat' => 'red', 'bias' => 'red']); ?>
                                                                                                <tr class="border-b border-gray-700/40<?= ($isPend || $isOff) ? ' opacity-60' : '' ?>">
                                                                                                    <td class="py-1 px-2">
                                                                                                        <?php if (!$isPend): ?>
                                                                                                            <input type="checkbox" name="link_keys[]" value="<?= htmlspecialchars(linkKey($li, 'filter', (int)$session['id'])) ?>" class="pfl-check rounded bg-gray-600 border-gray-500 mr-1">
                                                                                                        <?php endif; ?>
                                                                                                        <?= htmlspecialchars($li['name']) ?><?php if ($isPend): ?> <span title="<?= __('projects_pending_hypo') ?>">⏳</span><?php endif; ?><?php if ($isOff): ?> <span class="text-gray-500">(<?= __('projects_link_off') ?>)</span><?php endif; ?>
                                                                                                    </td>
                                                                                                    <td class="py-1 px-2 text-right text-gray-400"><?= htmlspecialchars((string)($li['exptime'] ?? '')) ?>s</td>
                                                                                                    <td class="py-1 px-2 whitespace-nowrap"><?= $isPend ? '⏳' : ($isOff ? '—' : (diagBox($d['bias'], 'B', __('projects_cal_bias')) . diagBox($d['dark'], 'D', __('projects_cal_dark')) . diagBox($d['flat'], 'F', __('projects_cal_flat')))) ?></td>
                                                                                                </tr>
                                                                                            <?php endforeach; ?>
                                                                                        </tbody>
                                                                                    </table>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </details>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
                </div>
            </div>
    <?php endforeach; ?>
<?php endif; ?>
