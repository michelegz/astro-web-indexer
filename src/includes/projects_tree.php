<?php
// Project tree partial. Expects $projectTree (getProjectTree) and $projectDiag (diagnoseProjectTree).
// Every linked file row carries a checkbox (value "fileId:level:nodeId") for the
// bulk form in projects.php; group checkboxes sit OUTSIDE <summary> elements
// (clicks inside <summary> are swallowed by the details toggle in browsers)
// inside a .tnode wrapper that scopes their subtree.
function fmtExp(float $seconds): string
{
    if ($seconds >= 3600) {
        return number_format($seconds, 0, '.', '') . ' s (' . number_format($seconds / 3600, 1, '.', '') . ' h)';
    }
    return number_format($seconds, 0, '.', '') . ' s';
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
    return '<span title="' . htmlspecialchars($title) . '" class="inline-block w-5 text-center text-xs font-bold text-white rounded ' . $bg . '">'
        . htmlspecialchars($letter) . '</span>';
}

function tallyFileRows(array $rows, array &$s): void
{
    foreach ($rows as $r) {
        if (!empty($r['pending'])) {
            $s['pend']++;
            continue;
        }
        if (empty($r['enabled'])) {
            $s['off']++;
            continue;
        }
        switch (strtoupper((string)($r['imgtype'] ?? ''))) {
            case 'LIGHT': $s['L']++; break;
            case 'DARK': $s['D']++; break;
            case 'FLAT': $s['F']++; break;
            case 'BIAS': $s['B']++; break;
        }
    }
}

/**
 * Recursive file counts of a setup/panel/session node: LIGHT/DARK/FLAT/BIAS
 * links of the node itself plus every level below it.
 */
function subtreeSummaryCounts(array $node): array
{
    $s = ['L' => 0, 'D' => 0, 'F' => 0, 'B' => 0, 'pend' => 0, 'off' => 0];
    tallyFileRows($node['calibrations'] ?? [], $s);
    tallyFileRows($node['lights'] ?? [], $s);
    foreach (['panels', 'sessions', 'filters'] as $childKey) {
        foreach ($node[$childKey] ?? [] as $child) {
            $sub = subtreeSummaryCounts($child);
            foreach (['L', 'D', 'F', 'B', 'pend', 'off'] as $k) {
                $s[$k] += $sub[$k];
            }
        }
    }
    return $s;
}

function subtreeSummaryText(array $node): string
{
    $s = subtreeSummaryCounts($node);
    $t = 'L:' . $s['L'] . ' D:' . $s['D'] . ' F:' . $s['F'] . ' B:' . $s['B'];
    if ($s['pend'] > 0) {
        $t .= ' (+' . $s['pend'] . ' ⏳)';
    }
    if ($s['off'] > 0) {
        $t .= ' (' . $s['off'] . ' ' . __('projects_link_off') . ')';
    }
    return $t;
}

function renderCalRows(array $cals, string $level, int $node, array $moveCtx = []): void
{
    $rows = array_values(array_filter($cals, fn($c) => isset($c['file_id'])));
    if (empty($rows)) {
        return;
    }
    $dup = $moveCtx['dup'] ?? [];
    $tols = $moveCtx['tols'] ?? ['exp' => '1%', 'temp' => '2C'];
    $groups = groupCalibrations(
        $rows,
        (string)($tols['exp'] ?? '1%'),
        (string)($tols['temp'] ?? '2C')
    );
    ?>
    <?php foreach ($groups as $g): ?>
        <?php
        $gReal = array_values(array_filter($g['rows'], fn($r) => empty($r['pending'])));
        ?>
        <div class="cal-group-wrap flex items-start gap-2 ml-4">
            <?php if ($gReal !== []): ?>
                <input type="checkbox" class="cgroup-check mt-2 rounded bg-gray-600 border-gray-500"
                       title="<?= __('projects_select_group') ?>">
            <?php endif; ?>
        <details class="cal-group flex-1 min-w-0 mb-1">
            <summary class="cursor-pointer px-3 py-1.5 hover:bg-gray-700/40 rounded text-sm font-medium">
                <span class="cal-arrow">▶︎</span>
                <span><?= htmlspecialchars(calGroupTitle($g)) ?></span>
            </summary>
            <ul class="flex flex-col gap-0.5 mb-1 px-3">
        <?php foreach ($g['rows'] as $c): ?>
            <?php $isPend = !empty($c['pending']); ?>
            <?php $isOff = !$isPend && empty($c['enabled']); ?>
            <?php $cDup = $dup[(int)$c['file_id']] ?? []; ?>
            <?php $cScope = $c['scope_nights'] ?? []; ?>
            <li class="flex items-center gap-2 text-xs border-b border-gray-700/40 py-0.5<?= ($isPend || $isOff) ? ' opacity-60' : '' ?>">
                <?php if (!$isPend): ?>
                    <input type="checkbox" name="link_keys[]" value="<?= htmlspecialchars(linkKey($c, $level, $node)) ?>" class="pfl-check rounded bg-gray-600 border-gray-500"<?= !empty($c['scope_sessions']) ? ' data-scope="' . htmlspecialchars(implode(',', $c['scope_sessions'])) . '"' : '' ?><?= isset($moveCtx['setup_id']) ? ' data-setup="' . (int)$moveCtx['setup_id'] . '"' : '' ?>>
                <?php endif; ?>
                <span class="text-gray-500">[<?= htmlspecialchars($c['imgtype']) ?>]</span>
                <span><?= htmlspecialchars($c['name']) ?></span>
                <?php if ($isPend): ?><span title="<?= __('projects_pending_hypo') ?>">⏳</span><?php endif; ?>
                <?php if ($isOff): ?><span class="text-gray-500">(<?= __('projects_link_off') ?>)</span><?php endif; ?>
                <?php if (count($cDup) > 1): ?><span title="<?= htmlspecialchars(__('projects_dup_levels') . ': ' . implode(', ', $cDup)) ?>">⧉×<?= count($cDup) ?></span><?php endif; ?>
                <?php if (!empty($cScope)): ?><span title="<?= htmlspecialchars(__('projects_scope_title') . ': ' . implode(', ', $cScope)) ?>">◈×<?= count($cScope) ?></span><?php endif; ?>
            </li>
        <?php endforeach; ?>
            </ul>
        </details>
        </div>
    <?php endforeach; ?>
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
                        <?= __('projects_setup') ?> S<?= (int)($setup['setup_no'] ?? $setup['id']) ?>: <?= htmlspecialchars($setup['label'] !== null && $setup['label'] !== '' ? $setup['label'] : substr((string)$setup['fingerprint'], 0, 48)) ?>
                        <button type="button" class="setup-rename ml-1 text-gray-400 hover:text-white text-sm leading-none align-baseline" title="<?= __('projects_setup_rename') ?>" data-setup-id="<?= (int)$setup['id'] ?>" data-setup-name="<?= htmlspecialchars($setup['label'] ?? '') ?>">✏️</button>
                        <div class="text-xs font-mono font-normal text-gray-500 mt-0.5"><?= htmlspecialchars(str_replace('|', ' | ', (string)($setup['fingerprint'] ?? ''))) ?></div>
                        <div class="text-xs font-normal text-gray-400 mt-0.5"><?= htmlspecialchars(subtreeSummaryText($setup)) ?></div>
                    </summary>
                    <div class="px-4 py-2">
                        <?php renderCalRows($setup['calibrations'], 'setup', (int)$setup['id'], ['setup_id' => (int)$setup['id']] + $calCtxBase); ?>
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
                                    <details open class="flex-1 min-w-0 border border-gray-700/60 rounded">
                                        <summary class="cursor-pointer px-3 py-1.5 hover:bg-gray-700/40 rounded font-medium">
                                            <?= __('projects_panel') ?> <?= htmlspecialchars($plabel) ?>
                                            <span class="ml-2 text-xs font-normal text-gray-400"><?= htmlspecialchars(subtreeSummaryText($panel)) ?></span>
                                        </summary>
                                        <div class="px-3 py-2">
                                            <?php renderCalRows($panel['calibrations'], 'panel', (int)$panel['id'], ['setup_id' => (int)$setup['id']] + $calCtxBase); ?>
                                            <?php foreach ($panel['sessions'] as $session): ?>
                                                <div class="tnode mb-2">
                                                    <div class="flex items-start gap-2">
                                                        <input type="checkbox" class="pgroup-check mt-2 rounded bg-gray-600 border-gray-500" title="<?= __('projects_select_group') ?>">
                                                        <details class="flex-1 min-w-0 border border-gray-700/40 rounded">
                                                            <summary class="cursor-pointer px-3 py-1.5 hover:bg-gray-700/40 rounded text-sm">
                                                                <?= __('projects_session') ?> <?= htmlspecialchars(sessionShortLabel($session)) ?>
                                                                <span class="ml-2 text-xs text-gray-400"><?= htmlspecialchars(subtreeSummaryText($session)) ?></span>
                                                            </summary>
                                                            <div class="px-3 py-2">
                                                                <?php renderCalRows($session['calibrations'], 'session', (int)$session['id'], ['setup_id' => (int)$setup['id']] + $calCtxBase); ?>
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
                                                                    $expGroups = clusterExposures($filter['lights'], $tolExpRaw ?? '1%');
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
                                                                                <?php renderCalRows($filter['calibrations'], 'filter', (int)$session['id'], $calCtxBase); ?>
                                                                                <?php foreach ($expGroups as $eg): ?>
                                                                                    <?php
                                                                                    $egReal = array_values(array_filter($eg['lights'], fn($li) => empty($li['pending']) && !empty($li['enabled'])));
                                                                                    $egWorstB = $egWorstD = $egWorstF = null;
                                                                                    foreach ($egReal as $li) {
                                                                                        $d = $projectDiag[(int)$li['file_id']] ?? ['dark' => 'red', 'flat' => 'red', 'bias' => 'red'];
                                                                                        $egWorstB = $egWorstB === null ? $d['bias'] : diagWorst($egWorstB, $d['bias']);
                                                                                        $egWorstD = $egWorstD === null ? $d['dark'] : diagWorst($egWorstD, $d['dark']);
                                                                                        $egWorstF = $egWorstF === null ? $d['flat'] : diagWorst($egWorstF, $d['flat']);
                                                                                    }
                                                                                    $egExp = 0.0;
                                                                                    foreach ($egReal as $li) {
                                                                                        $egExp += (float)($li['exptime'] ?? 0);
                                                                                    }
                                                                                    ?>
                                                                                    <div class="tnode mb-1 ml-4">
                                                                                        <div class="flex items-start gap-2">
                                                                                            <input type="checkbox" class="pgroup-check mt-1 rounded bg-gray-600 border-gray-500" title="<?= __('projects_select_group') ?>">
                                                                                            <div class="flex-1 min-w-0">
                                                                                                 <div class="text-xs font-medium text-gray-300 mb-1">
                                                                                                     <?php if ($egWorstB !== null): ?><?= diagBox($egWorstB, 'B', __('projects_cal_bias')) ?><?= diagBox($egWorstD, 'D', __('projects_cal_dark')) ?><?= diagBox($egWorstF, 'F', __('projects_cal_flat')) ?> <?php endif; ?><?= __('projects_exposure') ?> <?= htmlspecialchars(fmtExpShort($eg['exptime'])) ?><?php $egTempMed = projectMedian(array_column($egReal, 'ccd_temp')); ?><?php if ($egTempMed !== null): ?> · <?= htmlspecialchars(repTempDisplay($egTempMed)) ?><?php endif; ?>
                                                                                                     <span class="ml-2 font-normal text-gray-500"><?= count($egReal) ?> · <?= htmlspecialchars(fmtExp($egExp)) ?></span>
                                                                                                 </div>
                                                                                <div class="overflow-x-auto">
                                                                                    <table class="w-full text-xs text-left">
                                                                                        <tbody>
                                                                                             <?php foreach ($eg['lights'] as $li): ?>
                                                                                                 <?php $isPend = !empty($li['pending']); ?>
                                                                                                 <?php $isOff = !$isPend && empty($li['enabled']); ?>
                                                                                                 <?php $isAuto = !$isPend && !$isOff && !empty($li['auto_off']); ?>
                                                                                                 <?php $d = ($isPend || $isOff) ? null : ($projectDiag[(int)$li['file_id']] ?? ['dark' => 'red', 'flat' => 'red', 'bias' => 'red']); ?>
                                                                                                 <tr class="border-b border-gray-700/40<?= ($isPend || $isOff) ? ' opacity-60' : '' ?>">
                                                                                                     <td class="py-1 px-2<?= $isAuto ? ' text-red-400 font-medium' : '' ?>">
                                                                                                         <?php if (!$isPend): ?>
                                                                                                             <input type="checkbox" name="link_keys[]" value="<?= htmlspecialchars(linkKey($li, 'filter', (int)$session['id'])) ?>" class="pfl-check rounded bg-gray-600 border-gray-500 mr-1">
                                                                                                         <?php endif; ?>
                                                                                                          <?= htmlspecialchars($li['name']) ?><?php if ($isPend): ?> <span title="<?= __('projects_pending_hypo') ?>">⏳</span><?php endif; ?><?php if ($isOff): ?> <span class="text-gray-500">(<?= __('projects_link_off') ?>)</span><?php endif; ?><?php if ($isAuto): ?> <span class="text-red-400">(<?= __('projects_auto_off') ?>)</span><?php endif; ?><?php $liDup = ($dupLinks ?? [])[(int)$li['file_id']] ?? []; ?><?php if (count($liDup) > 1): ?> <span title="<?= htmlspecialchars(__('projects_dup_levels') . ': ' . implode(', ', $liDup)) ?>">⧉×<?= count($liDup) ?></span><?php endif; ?>
                                                                                                    </td>
                                                                                                    <td class="py-1 px-2 text-right text-gray-400"><?= htmlspecialchars((string)($li['exptime'] ?? '')) ?>s</td>
                                                                                                    <td class="py-1 px-2 whitespace-nowrap"><?= $isPend ? '⏳' : ($isOff ? '—' : '') ?></td>
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
