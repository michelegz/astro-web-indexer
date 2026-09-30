<?php
// Pending-only hierarchical preview for the wizard modal.
// Expects $pendingTree (getProjectTree with $includePending = true).
// Shows where suggestions WOULD land if accepted; selection happens here
// via checkboxes (suggestion_ids[]), accept/discard buttons live in the modal form.
$__ptree = $pendingTree ?? ['setups' => []];
function pendFileRows(array $lights): array
{
    return array_values(array_filter($lights, fn($li) => !empty($li['pending'])));
}

function pendCalCount(array $cals): int
{
    $n = 0;
    foreach ($cals as $c) {
        if (!empty($c['pending'])) {
            $n++;
        }
    }
    return $n;
}

function sessionHasPend(array $session): bool
{
    if (pendCalCount($session['calibrations']) > 0) {
        return true;
    }
    foreach ($session['filters'] as $f) {
        if (!empty(pendFileRows($f['lights'])) || pendCalCount($f['calibrations']) > 0) {
            return true;
        }
    }
    return false;
}

function panelHasPend(array $panel): bool
{
    if (pendCalCount($panel['calibrations']) > 0) {
        return true;
    }
    foreach ($panel['sessions'] as $s) {
        if (sessionHasPend($s)) {
            return true;
        }
    }
    return false;
}

function setupHasPend(array $setup): bool
{
    if (pendCalCount($setup['calibrations']) > 0) {
        return true;
    }
    foreach ($setup['panels'] as $p) {
        if (panelHasPend($p)) {
            return true;
        }
    }
    return false;
}

function renderPendCals(array $cals): void
{
    foreach ($cals as $cal) {
        if (empty($cal['pending'])) {
            continue;
        }
        ?>
        <li class="flex items-start gap-2 text-xs border-b border-gray-700/40 py-1 opacity-70">
            <input type="checkbox" name="suggestion_ids[]" value="<?= (int)$cal['suggestion_id'] ?>" class="sug-check mt-0.5 rounded bg-gray-600 border-gray-500">
            <span>
                <span class="font-medium"><?= htmlspecialchars($cal['name']) ?></span>
                <span class="ml-2 text-gray-500">[<?= htmlspecialchars($cal['imgtype']) ?>]</span>
            </span>
        </li>
        <?php
    }
}

$__pendTotal = 0;
foreach (($__ptree['setups'] ?? []) as $__s) {
    foreach ($__s['panels'] as $__p) {
        foreach ($__p['sessions'] as $__sess) {
            foreach ($__sess['filters'] as $__f) {
                $__pendTotal += count(pendFileRows($__f['lights'])) + pendCalCount($__f['calibrations']);
            }
            $__pendTotal += pendCalCount($__sess['calibrations']);
        }
        $__pendTotal += pendCalCount($__p['calibrations']);
    }
    $__pendTotal += pendCalCount($__s['calibrations']);
}
unset($__s, $__p, $__sess, $__f);
?>
<?php if ($__pendTotal === 0): ?>
    <p class="text-gray-500 text-sm"><?= __('projects_no_pending') ?></p>
<?php else: ?>
    <?php foreach ($__ptree['setups'] as $setup): ?>
        <?php if (!setupHasPend($setup)) continue; ?>
        <details open class="mb-3 border border-gray-700 rounded-lg">
            <summary class="cursor-pointer px-4 py-2 bg-gray-700/50 rounded-t-lg font-semibold text-sm">
                <?= __('projects_setup') ?>: <?= htmlspecialchars($setup['label'] !== null && $setup['label'] !== '' ? $setup['label'] : substr((string)$setup['fingerprint'], 0, 48)) ?>
            </summary>
            <div class="px-4 py-2">
                <?php $setupPendCals = array_values(array_filter($setup['calibrations'], fn($c) => !empty($c['pending']))); ?>
                <?php if (!empty($setupPendCals)): ?>
                    <ul class="flex flex-col gap-1 mb-2">
                        <?php renderPendCals($setup['calibrations']); ?>
                    </ul>
                <?php endif; ?>
                <?php foreach ($setup['panels'] as $panel): ?>
                    <?php if (!panelHasPend($panel)) continue; ?>
                    <?php
                    $coords = ($panel['ra'] !== null && $panel['dec'] !== null)
                        ? number_format((float)$panel['ra'], 3) . ' / ' . number_format((float)$panel['dec'], 3) : '?';
                    $plabel = 'P' . (int)($panel['panel_no'] ?? $panel['id']) . ' (' . $coords . ')';
                    if ($panel['label_object'] !== null && $panel['label_object'] !== '') {
                        $plabel .= ' ' . $panel['label_object'];
                    }
                    ?>
                    <details open class="mb-2 border border-gray-700/60 rounded">
                        <summary class="cursor-pointer px-3 py-1.5 hover:bg-gray-700/40 rounded font-medium text-sm">
                            <?= __('projects_panel') ?> <?= htmlspecialchars($plabel) ?>
                        </summary>
                        <div class="px-3 py-2">
                            <?php $panelPendCals = array_values(array_filter($panel['calibrations'], fn($c) => !empty($c['pending']))); ?>
                            <?php if (!empty($panelPendCals)): ?>
                                <ul class="flex flex-col gap-1 mb-2">
                                    <?php renderPendCals($panel['calibrations']); ?>
                                </ul>
                            <?php endif; ?>
                            <?php foreach ($panel['sessions'] as $session): ?>
                                <?php if (!sessionHasPend($session)) continue; ?>
                                <details open class="mb-2 border border-gray-700/40 rounded">
                                    <summary class="cursor-pointer px-3 py-1.5 hover:bg-gray-700/40 rounded text-sm">
                                        <?= __('projects_session') ?> <?= htmlspecialchars((string)$session['astro_night']) ?>
                                    </summary>
                                    <div class="px-3 py-2">
                                        <?php foreach ($session['filters'] as $filter): ?>
                                            <?php $prows = pendFileRows($filter['lights']); ?>
                                            <?php $pcalN = pendCalCount($filter['calibrations']); ?>
                                            <?php if (empty($prows) && $pcalN === 0) continue; ?>
                                            <div class="mb-2">
                                                <div class="text-sm font-medium mb-1">
                                                    ⏳ <?= __('projects_filter') ?> <?= htmlspecialchars($filter['name'] !== '' ? $filter['name'] : '—') ?>
                                                    <span class="ml-2 text-xs font-normal text-gray-400"><?= count($prows) ?><?= $pcalN > 0 ? ' (+' . $pcalN . ' cal)' : '' ?></span>
                                                </div>
                                                <ul class="flex flex-col gap-1">
                                                    <?php foreach ($prows as $li): ?>
                                                        <li class="flex items-start gap-2 text-xs border-b border-gray-700/40 py-1">
                                                            <input type="checkbox" name="suggestion_ids[]" value="<?= (int)$li['suggestion_id'] ?>" class="sug-check mt-0.5 rounded bg-gray-600 border-gray-500">
                                                            <span>
                                                                <span class="font-medium"><?= htmlspecialchars($li['name']) ?></span>
                                                                <span class="ml-2 text-gray-500"><?= htmlspecialchars($li['imgtype']) ?><?= $li['filter'] !== null && $li['filter'] !== '' ? ' · ' . htmlspecialchars($li['filter']) : '' ?></span>
                                                                <br><span class="text-gray-500"><?= htmlspecialchars((string)($li['reason'] ?? '')) ?></span>
                                                            </span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                    <?php foreach ($filter['calibrations'] as $cal): ?>
                                                        <?php if (empty($cal['pending'])) continue; ?>
                                                        <li class="flex items-start gap-2 text-xs border-b border-gray-700/40 py-1 opacity-70">
                                                            <input type="checkbox" name="suggestion_ids[]" value="<?= (int)$cal['suggestion_id'] ?>" class="sug-check mt-0.5 rounded bg-gray-600 border-gray-500">
                                                            <span>
                                                                <span class="font-medium"><?= htmlspecialchars($cal['name']) ?></span>
                                                                <span class="ml-2 text-gray-500">[<?= htmlspecialchars($cal['imgtype']) ?>]</span>
                                                            </span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php $sessPendCals = array_values(array_filter($session['calibrations'], fn($c) => !empty($c['pending']))); ?>
                                        <?php if (!empty($sessPendCals)): ?>
                                            <ul class="flex flex-col gap-1 mb-1">
                                                <?php renderPendCals($session['calibrations']); ?>
                                            </ul>
                                        <?php endif; ?>
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
