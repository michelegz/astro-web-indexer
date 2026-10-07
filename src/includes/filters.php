<?php
// Leggi le preferenze di visualizzazione dai cookie per evitare il flickering
$viewMode = $_COOKIE['viewMode'] ?? 'list';
$thumbSize = $_COOKIE['thumbSize'] ?? '3';
?>
<div class="bg-gray-800 rounded-lg shadow-md mb-6 overflow-hidden">
    <button type="button"
            id="filters-toggle"
            aria-expanded="true"
            aria-controls="filters-body"
            class="w-full flex flex-wrap items-center justify-between gap-2 p-4 hover:bg-gray-700/50 transition-colors text-left">
        <span class="flex items-center gap-2 font-semibold text-gray-100">
            <span aria-hidden="true">🔍</span>
            <span><?php echo __('filters'); ?></span>
        </span>
        <svg id="filters-chevron" class="w-5 h-5 text-gray-400 transition-transform duration-200" style="transform: rotate(180deg)" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
    </button>

    <div id="filters-body" class="border-t border-gray-700">
<form id="filters-form" method="get" class="flex flex-wrap gap-4 items-end p-4">
    <input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>">
    <input type="hidden" name="page" value="1"> <!-- Resetta la pagina quando si applicano i filtri -->
    <input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>">
    <input type="hidden" name="sort_by" value="<?= htmlspecialchars($sortBy) ?>">
    <input type="hidden" name="sort_order" value="<?= htmlspecialchars($sortOrder) ?>">

    <?php $currentParams = $_GET; // Per i filtri interdipendenti ?>

    <div>
                <label for="object-select" class="block text-sm font-medium text-gray-300 mb-1"><?php echo __('object') ?></label>
        <select id="object-select" name="object" class="appearance-none bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2.5 w-40 pr-8 bg-no-repeat bg-right" style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%236b7280\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 0.5rem center; background-size: 1.5em 1.5em;">
            <option value=""><?php echo __('all_objects') ?></option>
            <?php
            // Get OBJECT values considering other filters (except OBJECT itself)
            $availableObjects = getDistinctValues($conn, 'object', $dir, '', $filterFilter, $filterImgtype);
            // Stale filter from another folder: no <option> would match, so the
            // browser falls back to displaying "All objects" while the query
            // still filters by it (empty table, misleading). Show it explicitly.
            if ($filterObject !== '' && !in_array($filterObject, $availableObjects, true)): ?>
                <option value="<?= htmlspecialchars($filterObject) ?>" selected><?= htmlspecialchars($filterObject) ?> — <?= __('filter_not_in_folder') ?></option>
            <?php endif;
            foreach($availableObjects as $o): ?>
                <option value="<?= htmlspecialchars($o) ?>" <?= $o==$filterObject?'selected':'' ?>><?= htmlspecialchars($o) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
                <label for="filter-select" class="block text-sm font-medium text-gray-300 mb-1"><?php echo __('filter') ?></label>
        <select id="filter-select" name="filter" class="appearance-none bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2.5 w-40 pr-8 bg-no-repeat bg-right" style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%236b7280\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 0.5rem center; background-size: 1.5em 1.5em;">
            <option value=""><?php echo __('all_filters') ?></option>
            <?php
            // Get FILTER values considering other filters (except FILTER itself)
            $availableFilters = getDistinctValues($conn, 'filter', $dir, $filterObject, '', $filterImgtype);
            if ($filterFilter !== '' && !in_array($filterFilter, $availableFilters, true)): ?>
                <option value="<?= htmlspecialchars($filterFilter) ?>" selected><?= htmlspecialchars($filterFilter) ?> — <?= __('filter_not_in_folder') ?></option>
            <?php endif;
            foreach($availableFilters as $f): ?>
                <option value="<?= htmlspecialchars($f) ?>" <?= $f==$filterFilter?'selected':'' ?>><?= htmlspecialchars($f) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
                <label for="imgtype-select" class="block text-sm font-medium text-gray-300 mb-1"><?php echo __('type') ?></label>
        <select id="imgtype-select" name="imgtype" class="appearance-none bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2.5 w-40 pr-8 bg-no-repeat bg-right" style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%236b7280\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 0.5rem center; background-size: 1.5em 1.5em;">
            <option value=""><?php echo __('all_types') ?></option>
            <?php
            // Get IMGTYPE values considering other filters (except IMGTYPE itself)
            $availableImgtypes = getDistinctValues($conn, 'imgtype', $dir, $filterObject, $filterFilter, '');
            if ($filterImgtype !== '' && !in_array($filterImgtype, $availableImgtypes, true)): ?>
                <option value="<?= htmlspecialchars($filterImgtype) ?>" selected><?= htmlspecialchars($filterImgtype) ?> — <?= __('filter_not_in_folder') ?></option>
            <?php endif;
                        foreach($availableImgtypes as $i): ?>
                <option value="<?= htmlspecialchars($i) ?>" <?= $i==$filterImgtype?'selected':'' ?>><?= htmlspecialchars($i) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

        <!-- Date OBS Filter (datetime: supports noon-to-noon night sessions) -->
    <div class="md:border-l md:border-gray-600 md:pl-4">
        <label for="date_obs_from" class="block text-sm font-medium text-gray-300 mb-1"><?php echo __('observation_date'); ?>:</label>
        <div class="flex flex-wrap items-center gap-2">
            <?php
            $dateObsFromVal = formatForDatetimeInput($_GET['date_obs_from'] ?? '', false);
            $dateObsToVal = formatForDatetimeInput($_GET['date_obs_to'] ?? '', true);
            ?>
            <div class="relative">
                <input type="text" id="date_obs_from" name="date_obs_from" value="<?= htmlspecialchars($dateObsFromVal) ?>" placeholder="YYYY-MM-DD HH:MM" autocomplete="off" title="<?php echo __('observation_calendar_title'); ?>" class="bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2.5 cursor-pointer">
                <div id="date_obs_from_popup" class="hidden absolute z-50 mt-1 bg-gray-800 border border-gray-600 rounded-lg shadow-xl p-3 w-72"></div>
            </div>
            <span class="text-gray-400">-</span>
            <div class="relative">
                <input type="text" id="date_obs_to" name="date_obs_to" value="<?= htmlspecialchars($dateObsToVal) ?>" placeholder="YYYY-MM-DD HH:MM" autocomplete="off" title="<?php echo __('observation_calendar_title'); ?>" class="bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2.5 cursor-pointer">
                <div id="date_obs_to_popup" class="hidden absolute z-50 mt-1 bg-gray-800 border border-gray-600 rounded-lg shadow-xl p-3 w-72"></div>
            </div>
        </div>
    </div>
<style>
.obs-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
.obs-cal-day { position: relative; padding: 6px 0; font-size: 0.8rem; border-radius: 0.375rem; color: #e5e7eb; }
.obs-cal-day:hover:not(:disabled) { background-color: #374151; }
.obs-cal-day.has-session { font-weight: 700; }
.obs-cal-day.has-session::after { content: ''; position: absolute; left: 50%; bottom: 2px; transform: translateX(-50%); width: 6px; height: 6px; border-radius: 9999px; background-color: #ef4444; }
.obs-cal-day.is-selected { background-color: #2563eb; color: #fff; }
.obs-cal-day.is-selected::after { background-color: #fff; }
.obs-cal-day.is-today { outline: 1px solid #6b7280; }
.obs-cal-day:disabled { opacity: 0.25; cursor: default; }
.obs-cal-dow { font-size: 0.7rem; color: #9ca3af; text-align: center; padding: 2px 0; }
</style>

        <!-- Exposure Time Filter (seconds) -->
    <div class="md:border-l md:border-gray-600 md:pl-4">
        <label for="exptime_min" class="block text-sm font-medium text-gray-300 mb-1"><?php echo __('exposure_time'); ?>:</label>
        <div class="flex flex-wrap items-center gap-2">
            <input type="number" id="exptime_min" name="exptime_min" min="0" step="any" value="<?= htmlspecialchars($exptimeMin ?? '') ?>" class="bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2.5 w-28">
            <span class="text-gray-400">-</span>
            <input type="number" id="exptime_max" name="exptime_max" min="0" step="any" value="<?= htmlspecialchars($exptimeMax ?? '') ?>" class="bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2.5 w-28">
                </div>
    </div>

    <!-- Items per page -->
        <div class="md:border-l md:border-gray-600 md:pl-4">
        <label for="per_page-select" class="block text-sm font-medium text-gray-300 mb-1"><?php echo __('elements_per_page') ?>:</label>
        <select id="per_page-select" name="per_page" class="appearance-none bg-gray-700 border border-gray-600 text-gray-100 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 p-2.5 w-24 pr-8 bg-no-repeat bg-right" style="background-image: url('data:image/svg+xml,%3csvg xmlns=\'http://www.w3.org/2000/svg\' fill=\'none\' viewBox=\'0 0 20 20\'%3e%3cpath stroke=\'%236b7280\' stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'1.5\' d=\'M6 8l4 4 4-4\'/%3e%3c/svg%3e'); background-position: right 0.5rem center; background-size: 1.5em 1.5em;">
            <?php foreach(PER_PAGE_OPTIONS as $option): ?>
                <option value="<?= $option ?>" <?= $option==$perPage?'selected':'' ?>><?= $option ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    
<div class="md:border-l md:border-gray-600 md:pl-4 flex flex-wrap items-center gap-4">
    <?php
        // Build a clean URL for the reset button, preserving only dir and lang
        $reset_params = [
            'dir' => $dir,
            'lang' => $lang
        ];
        $reset_href = '?' . http_build_query($reset_params);
    ?>
    <a href="<?= htmlspecialchars($reset_href) ?>" 
       class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg transition duration-200">
        <?php echo __('reset_filters') ?>
    </a>

</div>

<!-- Columns Modal -->
<div id="columns-modal" class="fixed inset-0 bg-gray-900 bg-opacity-75 flex items-center justify-center hidden z-50">
    <div class="bg-gray-800 rounded-lg shadow-xl p-6 w-full max-w-6xl transform transition-all">
        <div class="flex justify-between items-center border-b border-gray-700 pb-3">
            <h3 class="text-xl font-semibold text-white"><?php echo __('columns') ?></h3>
            <button type="button" id="columns-close" class="text-gray-400 hover:text-white text-2xl">&times;</button>
        </div>
        <div id="columns-panel" class="mt-4 max-h-[75vh] overflow-y-auto">
            <div class="flex flex-wrap gap-6">
            <?php foreach (getColumnGroups() as $groupKey => $group): ?>
                <?php if ($groupKey === 'star' && !$showStarMetrics) continue; ?>
                <div style="flex: 1 1 14rem;">
                <div class="flex items-center gap-2 mt-2 mb-1">
                    <input type="checkbox" data-group-toggle="<?= htmlspecialchars($groupKey) ?>"
                           class="w-4 h-4 text-blue-600 bg-gray-700 border-gray-600 rounded focus:ring-blue-600 ring-offset-gray-800 focus:ring-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-400"><?php echo __($group['label']); ?></span>
                </div>
                <?php foreach ($group['columns'] as $sortKey => [$labelKey, $_]): ?>
                    <label class="flex items-center gap-2 py-1 text-sm text-gray-200 cursor-pointer hover:bg-gray-600 rounded px-1">
                        <input type="checkbox" data-col="<?= htmlspecialchars($sortKey) ?>" data-group="<?= htmlspecialchars($groupKey) ?>"
                               <?= showCol($sortKey) ? 'checked' : '' ?>
                               class="w-4 h-4 text-blue-600 bg-gray-700 border-gray-600 rounded focus:ring-blue-600 ring-offset-gray-800 focus:ring-2">
                        <?php echo __($labelKey); ?>
                    </label>
                <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <div class="flex gap-2 pt-4 border-t border-gray-700 mt-4">
            <button type="button" id="columns-all"
               class="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold py-2 px-4 rounded transition duration-200">
                <?php echo __('columns_show_all') ?>
            </button>
            <button type="button" id="columns-reset"
               class="flex-1 bg-gray-600 hover:bg-gray-500 text-white text-sm font-bold py-2 px-4 rounded transition duration-200">
                <?php echo __('columns_reset') ?>
            </button>
            <button type="button" id="columns-apply"
               class="flex-1 bg-green-600 hover:bg-green-700 text-white text-sm font-bold py-2 px-4 rounded transition duration-200">
                <?php echo __('columns_apply') ?>
            </button>
        </div>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('columns-btn');
    var panel = document.getElementById('columns-panel');
    if (!btn || !panel) return;

    var modal = document.getElementById('columns-modal');
    var closeBtn = document.getElementById('columns-close');
    btn.addEventListener('click', function() {
        if (modal) modal.classList.remove('hidden');
    });
    if (closeBtn) closeBtn.addEventListener('click', function() {
        if (modal) modal.classList.add('hidden');
    });
    if (modal) modal.addEventListener('click', function(event) {
        if (event.target === modal) modal.classList.add('hidden');
    });

    function currentUrlWithoutAdvanced() {
        var url = new URL(window.location.href);
        url.searchParams.delete('show_advanced'); // legacy param, superseded by the cookie
        return url.toString();
    }
    function saveAndReload(hidden) {
        document.cookie = 'hiddenCols=' + hidden.join(',') + ';path=/;max-age=31536000;samesite=Lax';
        window.location.href = currentUrlWithoutAdvanced();
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
    function readHiddenCols() {
        var m = document.cookie.match(/(?:^|;\s*)hiddenCols=([^;]*)/);
        return m ? m[1].split(',').filter(Boolean) : null; // null = no cookie yet
    }
    function stagedHidden() {
        var hidden = [];
        var listed = {};
        panel.querySelectorAll('input[data-col]').forEach(function(box) {
            var k = box.getAttribute('data-col');
            listed[k] = true;
            if (!box.checked) hidden.push(k);
        });
        // Preserve hidden keys not currently listed (e.g. star group while metrics are off)
        var current = readHiddenCols();
        if (current !== null) {
            current.forEach(function(k) {
                if (!listed[k] && hidden.indexOf(k) === -1) hidden.push(k);
            });
        }
        return hidden;
    }

    document.getElementById('columns-apply').addEventListener('click', function() {
        saveAndReload(stagedHidden());
    });

    document.getElementById('columns-all').addEventListener('click', function() {
        panel.querySelectorAll('input[data-col]').forEach(function(box) {
            box.checked = true;
        });
        refreshGroupToggles();
    });
    document.getElementById('columns-reset').addEventListener('click', function() {
        // Default: base columns visible, everything else hidden
        panel.querySelectorAll('input[data-col]').forEach(function(box) {
            box.checked = (box.getAttribute('data-group') === 'base');
        });
        refreshGroupToggles();
    });
});
</script>
</form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var toggle = document.getElementById('filters-toggle');
    var body = document.getElementById('filters-body');
    var chevron = document.getElementById('filters-chevron');
    if (!toggle || !body) return;
    toggle.addEventListener('click', function() {
        var isHidden = body.classList.toggle('hidden');
        toggle.setAttribute('aria-expanded', isHidden ? 'false' : 'true');
        if (chevron) chevron.style.transform = isHidden ? '' : 'rotate(180deg)';
    });
});
</script>
<script>
// Observation-date calendars with session dots.
// The red dot marks days with >= 1 file (DATE(date_obs)) under the current
// filters, IGNORING the date range itself (fetched from the API).
document.addEventListener('DOMContentLoaded', function() {
    var i18n = window.i18n || {};
    var T = {
        apply: i18n.columns_apply || 'Apply',
        clear: i18n.clear || 'Clear',
        noSession: '● sessione esistente',
        prev: '‹', next: '›'
    };
    var DOW = ['L', 'M', 'M', 'G', 'V', 'S', 'D']; // Monday-first
    var MONTHS = ['Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
    var sessionDates = {}; // 'YYYY-MM-DD' -> count

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function fmtDate(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
    function parseInput(val) {
        // 'YYYY-MM-DDTHH:MM' -> {date, time}
        var m = /^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}))?/.exec(val || '');
        if (!m) return { date: null, time: null };
        return { date: m[1], time: m[2] || null };
    }

    function fetchSessions() {
        var form = document.getElementById('filters-form');
        if (!form) return;
        var params = new URLSearchParams();
        ['dir', 'object', 'filter', 'imgtype', 'exptime_min', 'exptime_max'].forEach(function(name) {
            var el = form.querySelector('[name="' + name + '"]');
            if (el && el.value !== '') params.set(name, el.value);
        });
        fetch('/api/get_observation_dates.php?' + params.toString())
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.dates) {
                    sessionDates = data.dates;
                    pickers.forEach(function(p) { if (!p.popup.classList.contains('hidden')) p.render(); });
                }
            })
            .catch(function() { /* dots are best-effort: leave calendar usable */ });
    }

    var pickers = [];
    function makePicker(inputId, popupId) {
        var input = document.getElementById(inputId);
        var popup = document.getElementById(popupId);
        if (!input || !popup) return null;
        var now = new Date();
        var state = {
            input: input, popup: popup,
            viewY: now.getFullYear(), viewM: now.getMonth(),
            selDate: null, selTime: '12:00', timeTouched: false
        };
        var p = { state: state, popup: popup, render: function() { renderPicker(state); } };
        pickers.push(p);

        function syncFromInput() {
            var parsed = parseInput(input.value);
            state.selDate = parsed.date;
            state.selTime = parsed.time || '12:00';
            state.timeTouched = !!parsed.time;
            if (parsed.date) {
                var parts = parsed.date.split('-');
                state.viewY = parseInt(parts[0], 10);
                state.viewM = parseInt(parts[1], 10) - 1;
            } else {
                var t = new Date();
                state.viewY = t.getFullYear();
                state.viewM = t.getMonth();
            }
        }

        function renderPicker(st) {
            var html = '';
            html += '<div class="flex items-center justify-between mb-2">';
            html += '<button type="button" data-nav="-1" class="px-2 py-1 rounded hover:bg-gray-700">' + T.prev + '</button>';
            html += '<span class="text-sm font-semibold">' + MONTHS[st.viewM] + ' ' + st.viewY + '</span>';
            html += '<button type="button" data-nav="1" class="px-2 py-1 rounded hover:bg-gray-700">' + T.next + '</button>';
            html += '</div><div class="obs-cal-grid mb-1">';
            DOW.forEach(function(d) { html += '<div class="obs-cal-dow">' + d + '</div>'; });
            var first = new Date(st.viewY, st.viewM, 1);
            var lead = (first.getDay() + 6) % 7; // Monday-first offset
            var days = new Date(st.viewY, st.viewM + 1, 0).getDate();
            var todayStr = fmtDate(now.getFullYear(), now.getMonth(), now.getDate());
            for (var i = 0; i < lead; i++) html += '<span></span>';
            for (var d = 1; d <= days; d++) {
                var ds = fmtDate(st.viewY, st.viewM, d);
                var cls = 'obs-cal-day';
                if (sessionDates[ds]) cls += ' has-session';
                if (ds === st.selDate) cls += ' is-selected';
                if (ds === todayStr) cls += ' is-today';
                var title = sessionDates[ds] ? sessionDates[ds] + ' file' : '';
                html += '<button type="button" data-day="' + ds + '" class="' + cls + '" title="' + title + '">' + d + '</button>';
            }
            html += '</div>';
            html += '<div class="text-xs text-gray-400 mb-2"><span class="inline-block w-2 h-2 rounded-full mr-1" style="background:#ef4444"></span>' + T.noSession + '</div>';
            html += '<div class="flex items-center gap-2">';
            html += '<input type="time" data-time value="' + st.selTime + '" class="bg-gray-700 border border-gray-600 rounded px-2 py-1 text-sm">';
            html += '<button type="button" data-apply class="flex-1 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold py-1 px-2 rounded">' + T.apply + '</button>';
            html += '<button type="button" data-clear class="bg-gray-600 hover:bg-gray-500 text-white text-sm font-bold py-1 px-2 rounded">' + T.clear + '</button>';
            html += '</div>';
            popup.innerHTML = html;
        }

        popup.addEventListener('click', function(e) {
            // Internal clicks must not reach the document-level closer below:
            // renderPicker() destroys the clicked button (innerHTML rewrite),
            // so popup.contains(e.target) would be false by the time the
            // document handler runs and the popup would close on every click.
            e.stopPropagation();
            var nav = e.target.closest('[data-nav]');
            if (nav) {
                state.viewM += parseInt(nav.getAttribute('data-nav'), 10);
                if (state.viewM < 0) { state.viewM = 11; state.viewY--; }
                if (state.viewM > 11) { state.viewM = 0; state.viewY++; }
                renderPicker(state);
                return;
            }
            var day = e.target.closest('[data-day]');
            if (day) {
                state.selDate = day.getAttribute('data-day');
                if (!state.timeTouched) state.selTime = '12:00';
                renderPicker(state);
                var t = popup.querySelector('[data-time]');
                if (t) t.value = state.selTime;
                return;
            }
            if (e.target.closest('[data-apply]')) {
                var timeEl = popup.querySelector('[data-time]');
                var tm = (timeEl && timeEl.value) ? timeEl.value : '12:00';
                if (state.selDate) {
                    input.value = state.selDate + ' ' + tm;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
                popup.classList.add('hidden');
                return;
            }
            if (e.target.closest('[data-clear]')) {
                input.value = '';
                input.dispatchEvent(new Event('change', { bubbles: true }));
                popup.classList.add('hidden');
            }
        });
        popup.addEventListener('change', function(e) {
            if (e.target && e.target.hasAttribute('data-time')) {
                state.selTime = e.target.value || '12:00';
                state.timeTouched = true;
            }
        });
        function openPopup() {
            pickers.forEach(function(o) { o.popup.classList.add('hidden'); });
            syncFromInput();
            renderPicker(state);
            // Fixed positioning: escapes the filters card's overflow-hidden
            // (which would clip an absolute popup) and any stacking context.
            var r = input.getBoundingClientRect();
            popup.style.position = 'fixed';
            popup.style.zIndex = '100';
            popup.style.width = '18rem';
            popup.style.top = (r.bottom + 4) + 'px';
            popup.style.left = Math.max(4, Math.min(r.left, window.innerWidth - 296)) + 'px';
            popup.classList.remove('hidden');
        }
        // The calendar opens from the existing filter fields: no extra buttons.
        input.addEventListener('focus', openPopup);
        input.addEventListener('click', openPopup);
        return p;
    }

    makePicker('date_obs_from', 'date_obs_from_popup');
    makePicker('date_obs_to', 'date_obs_to_popup');
    document.addEventListener('click', function(e) {
        pickers.forEach(function(p) {
            if (!p.popup.classList.contains('hidden')
                && !p.popup.contains(e.target)
                && e.target !== p.state.input) {
                p.popup.classList.add('hidden');
            }
        });
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') pickers.forEach(function(p) { p.popup.classList.add('hidden'); });
    });
    // Fixed-positioned popups don't follow the page: close them on viewport
    // scroll/resize. Ignore scrolls from inner elements (e.g. interacting
    // with the time field inside an open popup must not close it).
    window.addEventListener('scroll', function(e) {
        if (e.target !== document) return;
        pickers.forEach(function(p) { p.popup.classList.add('hidden'); });
    }, true);
    window.addEventListener('resize', function() {
        pickers.forEach(function(p) { p.popup.classList.add('hidden'); });
    });
    fetchSessions();
});
</script>
