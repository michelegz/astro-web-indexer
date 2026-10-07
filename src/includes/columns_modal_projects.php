<?php
// Independent column chooser for the project integration-group tables.
// Same groups/labels as the main chooser, but reads/writes the
// `hiddenColsProjects` cookie, so preferences never clash with the main page.
$projectsDefaultVisible = getProjectsDefaultVisible();
?>
<div class="flex flex-wrap justify-end gap-2 mb-3">
    <button type="button" id="pcols-btn"
       class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg transition duration-200">
        <?php echo __('columns') ?>
    </button>
</div>

<div id="pcols-modal" class="fixed inset-0 bg-gray-900 bg-opacity-75 flex items-center justify-center hidden z-50">
    <div class="bg-gray-800 rounded-lg shadow-xl p-6 w-full max-w-6xl transform transition-all">
        <div class="flex justify-between items-center border-b border-gray-700 pb-3">
            <h3 class="text-xl font-semibold text-white"><?php echo __('columns') ?></h3>
            <button type="button" id="pcols-close" class="text-gray-400 hover:text-white text-2xl">&times;</button>
        </div>
        <div id="pcols-panel" class="mt-4 max-h-[75vh] overflow-y-auto" data-default-visible="<?= htmlspecialchars(implode(',', $projectsDefaultVisible)) ?>">
            <div class="flex flex-wrap gap-6">
            <?php foreach (getColumnGroups() as $groupKey => $group): ?>
                <?php if ($groupKey === 'star' && empty($showStarMetrics ?? true)) continue; ?>
                <div style="flex: 1 1 14rem;">
                <div class="flex items-center gap-2 mt-2 mb-1">
                    <input type="checkbox" data-group-toggle="<?= htmlspecialchars($groupKey) ?>"
                           class="w-4 h-4 text-blue-600 bg-gray-700 border-gray-600 rounded focus:ring-blue-600 ring-offset-gray-800 focus:ring-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-400"><?php echo __($group['label']); ?></span>
                </div>
                <?php foreach ($group['columns'] as $sortKey => [$labelKey, $_]): ?>
                    <label class="flex items-center gap-2 py-1 text-sm text-gray-200 cursor-pointer hover:bg-gray-600 rounded px-1">
                        <input type="checkbox" data-col="<?= htmlspecialchars($sortKey) ?>" data-group="<?= htmlspecialchars($groupKey) ?>"
                               <?= showColFor($sortKey, 'project') ? 'checked' : '' ?>
                               class="w-4 h-4 text-blue-600 bg-gray-700 border-gray-600 rounded focus:ring-blue-600 ring-offset-gray-800 focus:ring-2">
                        <?php echo __($labelKey); ?>
                    </label>
                <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <div class="flex gap-2 pt-4 border-t border-gray-700 mt-4">
            <button type="button" id="pcols-all"
               class="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold py-2 px-4 rounded transition duration-200">
                <?php echo __('columns_show_all') ?>
            </button>
            <button type="button" id="pcols-reset"
               class="flex-1 bg-gray-600 hover:bg-gray-500 text-white text-sm font-bold py-2 px-4 rounded transition duration-200">
                <?php echo __('columns_reset') ?>
            </button>
            <button type="button" id="pcols-apply"
               class="flex-1 bg-green-600 hover:bg-green-700 text-white text-sm font-bold py-2 px-4 rounded transition duration-200">
                <?php echo __('columns_apply') ?>
            </button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('pcols-btn');
    var panel = document.getElementById('pcols-panel');
    if (!btn || !panel) return;

    var modal = document.getElementById('pcols-modal');
    var closeBtn = document.getElementById('pcols-close');
    btn.addEventListener('click', function() {
        if (modal) modal.classList.remove('hidden');
    });
    if (closeBtn) closeBtn.addEventListener('click', function() {
        if (modal) modal.classList.add('hidden');
    });
    if (modal) modal.addEventListener('click', function(event) {
        if (event.target === modal) modal.classList.add('hidden');
    });

    function saveAndReload(hidden) {
        document.cookie = 'hiddenColsProjects=' + hidden.join(',') + ';path=/;max-age=31536000;samesite=Lax';
        window.location.reload();
    }
    function groupBoxes(group) {
        return panel.querySelectorAll('input[data-col][data-group="' + group + '"]');
    }
    function refreshGroupToggles() {
        panel.querySelectorAll('input[data-group-toggle]').forEach(function(toggle) {
            var boxes = groupBoxes(toggle.getAttribute('data-group-toggle'));
            var checked = 0;
            boxes.forEach(function(box) { if (box.checked) checked++; });
            toggle.checked = boxes.length > 0 && checked === boxes.length;
            toggle.indeterminate = checked > 0 && checked < boxes.length;
        });
    }
    panel.querySelectorAll('input[data-group-toggle]').forEach(function(toggle) {
        toggle.addEventListener('change', function() {
            var on = toggle.checked;
            groupBoxes(toggle.getAttribute('data-group-toggle')).forEach(function(box) {
                box.checked = on;
            });
            toggle.indeterminate = false;
        });
    });
    panel.querySelectorAll('input[data-col]').forEach(function(box) {
        box.addEventListener('change', refreshGroupToggles);
    });
    refreshGroupToggles();
    function stagedHidden() {
        var hidden = [];
        panel.querySelectorAll('input[data-col]').forEach(function(box) {
            if (!box.checked) hidden.push(box.getAttribute('data-col'));
        });
        return hidden;
    }

    document.getElementById('pcols-apply').addEventListener('click', function() {
        saveAndReload(stagedHidden());
    });

    document.getElementById('pcols-all').addEventListener('click', function() {
        panel.querySelectorAll('input[data-col]').forEach(function(box) {
            box.checked = true;
        });
        refreshGroupToggles();
    });
    document.getElementById('pcols-reset').addEventListener('click', function() {
        // Default: the old fixed igroup columns (preview + date + star metrics).
        var def = (panel.getAttribute('data-default-visible') || '').split(',').filter(Boolean);
        panel.querySelectorAll('input[data-col]').forEach(function(box) {
            box.checked = def.indexOf(box.getAttribute('data-col')) !== -1;
        });
        refreshGroupToggles();
    });
});
</script>
