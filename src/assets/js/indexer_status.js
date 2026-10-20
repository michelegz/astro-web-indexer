// Live indexer status card.
//
// The page is rendered with an initial payload by includes/indexer_status.php;
// this file only repaints the variable parts and polls /api/indexer_status.php
// every five seconds while the tab is visible. Nothing here is an access
// control: the endpoint checks for an admin session itself.
(function () {
    'use strict';

    var I18N = window.awiIndexerI18n || {};
    var POLL_MS = 5000;
    var timer = null;
    var inFlight = false;
    var card = null;
    // null means "no choice yet": the history opens by itself while idle and
    // stays folded while a run is active. A click records an explicit choice,
    // which then survives every poll's innerHTML replacement.
    var historyOpen = null;
    var historyTouched = false;

    function t(key) {
        return I18N[key] || key;
    }

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function num(value) {
        if (value === null || value === undefined || isNaN(value)) return '—';
        return Number(value).toLocaleString();
    }

    function duration(seconds) {
        if (seconds === null || seconds === undefined || isNaN(seconds)) return '';
        var s = Math.max(0, Math.round(Number(seconds)));
        if (s < 60) return s + 's';
        var m = Math.floor(s / 60);
        var rs = s % 60;
        if (m < 60) return rs ? m + 'm ' + rs + 's' : m + 'm';
        var h = Math.floor(m / 60);
        var rm = m % 60;
        if (h < 24) return rm ? h + 'h ' + rm + 'm' : h + 'h';
        var d = Math.floor(h / 24);
        var rh = h % 24;
        return rh ? d + 'd ' + rh + 'h' : d + 'd';
    }

    function ago(seconds) {
        if (seconds === null || seconds === undefined || isNaN(seconds)) return t('never');
        var s = Math.max(0, Math.round(Number(seconds)));
        var value;
        if (s < 60) value = s + 's';
        else if (s < 3600) value = Math.floor(s / 60) + 'm';
        else if (s < 86400) value = Math.floor(s / 3600) + 'h';
        else value = Math.floor(s / 86400) + 'd';
        return String(t('ago')).replace('{value}', value);
    }

    function setDot(kind) {
        var dot = document.getElementById('indexer-dot');
        if (!dot) return;
        var colour = 'bg-gray-500';
        if (kind === 'running') colour = 'bg-blue-400 animate-pulse';
        else if (kind === 'stalled') colour = 'bg-amber-400';
        else if (kind === 'error') colour = 'bg-red-400';
        else if (kind === 'ok') colour = 'bg-emerald-400';
        dot.className = 'inline-block w-2.5 h-2.5 rounded-full ' + colour;
    }

    function headline(data) {
        var run = data.current;
        if (run) {
            var text = t('running') + ' · ' + esc(run.phase_label);
            if (run.phase_total > 0) {
                text += ' · ' + num(run.phase_done) + ' / ' + num(run.phase_total)
                    + ' (' + num(run.percent) + '%)';
            } else if (run.files_scanned > 0) {
                text += ' · ' + num(run.files_scanned) + ' ' + esc(t('files_on_disk'));
            }
            return text;
        }
        var last = data.last;
        if (!last) return esc(t('no_runs'));
        var idle = last.status === 'error' ? t('failed')
            : (last.status === 'interrupted' ? t('interrupted') : t('idle'));
        return esc(idle) + ' · ' + esc(t('last_run')) + ' ' + ago(last.finished_age);
    }

    function progressBar(run) {
        if (!(run.phase_total > 0)) return '';
        var pct = Math.max(0, Math.min(100, Number(run.percent) || 0));
        return '<div class="w-full bg-gray-700 rounded-full h-3.5 overflow-hidden"'
            + ' role="progressbar" aria-valuenow="' + pct + '" aria-valuemin="0" aria-valuemax="100">'
            + '<div class="bg-blue-500 h-3.5 rounded-full transition-all" style="width: ' + pct + '%"></div>'
            + '</div>';
    }

    function metricsLine(data) {
        if (!data.archive || data.archive.metrics_pending === null
            || data.archive.metrics_pending === undefined) {
            return '';
        }
        var n = Number(data.archive.metrics_pending);
        if (n <= 0) return '<span class="text-gray-500">' + esc(t('no_metrics_pending')) + '</span>';
        return '<span class="text-blue-300">' + num(n) + ' ' + esc(t('metrics_pending')) + '</span>';
    }

    function watcherLine(w) {
        if (!w) return '';
        var parts = [esc(t('watcher')) + ': ' + esc(w.state_label)];
        if (w.state === 'scheduled' && w.pending_reason_label) {
            parts.push('(' + esc(w.pending_reason_label) + ')');
        }
        if (w.failures > 0) {
            parts.push(String(t('watcher_failures')).replace('{count}', num(w.failures)));
        }
        if (w.scan_age !== null && w.scan_age !== undefined) {
            parts.push(esc(t('watcher_last_scan')) + ' ' + ago(w.scan_age));
        }
        if (w.known_files > 0) {
            parts.push(num(w.known_files) + ' ' + esc(t('files_on_disk')));
        }
        var text = '<div class="text-xs text-gray-400">' + parts.join(' · ');
        if (w.stale) {
            text += ' · <span class="text-amber-300">' + esc(t('watcher_stale')) + '</span>';
        }
        return text + '</div>';
    }

    function statCells(data, run) {
        var cells = [];
        if (run) {
            cells.push([t('files_on_disk'), run.files_scanned]);
            cells.push([t('files_indexed'), run.db_files]);
            cells.push([t('skipped'), run.skipped]);
        }
        if (data.archive && data.archive.metrics_pending !== null
            && data.archive.metrics_pending !== undefined) {
            cells.push([t('metrics_pending'), data.archive.metrics_pending]);
        }
        if (cells.length === 0) return '';
        var html = '<div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs text-gray-400">';
        cells.forEach(function (cell) {
            html += '<div class="bg-gray-700/40 rounded p-2">'
                + '<div class="text-gray-500">' + esc(cell[0]) + '</div>'
                + '<div class="text-gray-200 font-semibold">' + num(cell[1]) + '</div>'
                + '</div>';
        });
        return html + '</div>';
    }

    function historyRows(data) {
        var rows = data.history || [];
        if (!rows.length) return '';
        // User preference wins over the default once there is one.
        var open = historyOpen === null ? !data.current : historyOpen;
        var html = '<details id="indexer-history" class="mt-3 border-t border-gray-700 pt-3"' + (open ? ' open' : '') + '>'
            + '<summary class="cursor-pointer text-xs font-semibold text-gray-300">'
            + esc(t('history')) + '</summary>'
            + '<div class="overflow-x-auto mt-2"><table class="w-full text-left text-xs">'
            + '<thead class="text-gray-500"><tr>'
            + '<th class="py-1 pr-3">' + esc(t('history_started')) + '</th>'
            + '<th class="py-1 pr-3">' + esc(t('history_reason')) + '</th>'
            + '<th class="py-1 pr-3">' + esc(t('history_duration')) + '</th>'
            + '<th class="py-1 pr-3 text-right">' + esc(t('history_files')) + '</th>'
            + '<th class="py-1 pr-3 text-right">' + esc(t('history_metrics')) + '</th>'
            + '<th class="py-1 pr-3 text-right">' + esc(t('history_errors')) + '</th>'
            + '<th class="py-1">' + esc(t('history_status')) + '</th>'
            + '</tr></thead><tbody>';
        rows.forEach(function (row) {
            var colour = row.status === 'error' ? 'text-red-300'
                : (row.status === 'done' ? 'text-emerald-300' : 'text-amber-300');
            html += '<tr class="border-t border-gray-700/50">'
                + '<td class="py-1 pr-3 text-gray-400">' + ago(row.started_age) + '</td>'
                + '<td class="py-1 pr-3 text-gray-300">' + esc(row.reason_label) + '</td>'
                + '<td class="py-1 pr-3 text-gray-400">' + duration(row.elapsed_seconds) + '</td>'
                + '<td class="py-1 pr-3 text-right text-gray-300">' + num(row.files_done) + '</td>'
                + '<td class="py-1 pr-3 text-right text-gray-300">' + num(row.metrics_done) + '</td>'
                + '<td class="py-1 pr-3 text-right ' + (row.error_count > 0 ? 'text-red-300' : 'text-gray-400') + '">'
                + num(row.error_count) + '</td>'
                + '<td class="py-1 ' + colour + '">' + esc(row.status_label) + '</td>'
                + '</tr>';
        });
        return html + '</tbody></table></div></details>';
    }

    function currentRun(data, run) {
        var html = '<div class="space-y-3">';
        html += '<div class="flex flex-wrap items-center justify-between gap-2">'
            + '<span class="font-semibold text-gray-100">' + esc(run.phase_label) + '</span>'
            + '<span class="text-gray-300">' + num(run.phase_done);
        if (run.phase_total > 0) html += ' / ' + num(run.phase_total);
        html += '</span></div>';
        html += progressBar(run);

        var meta = [];
        if (run.eta_seconds !== null && run.eta_seconds !== undefined) {
            meta.push(esc(t('eta')) + ': ' + duration(run.eta_seconds));
        }
        meta.push(esc(t('elapsed')) + ': ' + duration(run.elapsed_seconds));
        if (run.rate_per_minute !== null && run.rate_per_minute !== undefined) {
            meta.push(num(run.rate_per_minute) + ' ' + esc(t('rate')));
        }
        if (run.error_count > 0) {
            meta.push('<span class="text-red-300">' + esc(t('errors')) + ': ' + num(run.error_count) + '</span>');
        }
        if (run.heartbeat_age !== null && run.heartbeat_age !== undefined) {
            meta.push(esc(t('updated')) + ' ' + ago(run.heartbeat_age));
        }
        html += '<div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-400">'
            + meta.join(' · ') + '</div>';

        if (run.stalled) {
            html += '<p class="text-sm text-amber-300">' + esc(t('stalled')) + '</p>';
        }
        if (run.last_error) {
            html += '<p class="text-xs text-red-300 break-all">' + esc(run.last_error) + '</p>';
        }
        html += statCells(data, run);
        html += watcherLine(data.watcher);
        html += historyRows(data);
        return html + '</div>';
    }

    function finishedRun(data, run) {
        var html = '<div class="space-y-3">';
        html += '<div class="flex flex-wrap items-center justify-between gap-2">'
            + '<span class="font-semibold text-gray-100">' + esc(t('last_run')) + '</span>'
            + '<span class="' + (run.status === 'error' ? 'text-red-300'
                : (run.status === 'done' ? 'text-emerald-300' : 'text-amber-300')) + '">'
            + esc(run.status_label) + '</span></div>';
        var meta = [esc(run.reason_label)];
        meta.push(esc(t('history_duration')) + ': ' + duration(run.elapsed_seconds));
        meta.push(num(run.files_done) + ' ' + esc(t('history_files')));
        meta.push(num(run.metrics_done) + ' ' + esc(t('history_metrics')));
        if (run.error_count > 0) {
            meta.push('<span class="text-red-300">' + esc(t('errors')) + ': ' + num(run.error_count) + '</span>');
        }
        meta.push(ago(run.finished_age));
        html += '<div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-400">'
            + meta.join(' · ') + '</div>';
        if (run.last_error) {
            html += '<p class="text-xs text-red-300 break-all">' + esc(run.last_error) + '</p>';
        }
        if (run.status === 'done' && run.finished_age !== null && run.finished_age < 120) {
            html += '<p class="text-xs text-gray-400">' + esc(t('just_finished'))
                + ' <button type="button" onclick="location.reload()" class="ml-1 px-2 py-0.5 bg-blue-600 hover:bg-blue-700 text-white rounded">'
                + esc(t('reload')) + '</button></p>';
        }
        html += statCells(data, null);
        html += watcherLine(data.watcher);
        html += historyRows(data);
        return html + '</div>';
    }

    function render(data) {
        if (!card || !data || data.available === false) return;
        var headlineEl = document.getElementById('indexer-headline');
        if (headlineEl) headlineEl.innerHTML = headline(data);

        if (data.current && data.current.stalled) setDot('stalled');
        else if (data.current) setDot('running');
        else if (data.last && data.last.status === 'error') setDot('error');
        else if (data.last && data.last.status === 'interrupted') setDot('stalled');
        else if (data.last) setDot('ok');
        else setDot('unknown');

        var content = document.getElementById('indexer-content');
        if (!content) return;
        // Preserve an explicit toggle across the innerHTML replacement below;
        // an untouched history keeps following the run state.
        var historyEl = document.getElementById('indexer-history');
        if (historyEl && historyTouched) historyOpen = historyEl.open;
        if (data.current) {
            content.innerHTML = currentRun(data, data.current);
        } else if (data.last) {
            content.innerHTML = finishedRun(data, data.last);
        } else {
            content.innerHTML = '<div class="space-y-3"><p class="text-gray-400">'
                + esc(t('no_runs')) + '</p>'
                + '<div class="text-xs text-gray-400">' + metricsLine(data) + '</div>'
                + watcherLine(data.watcher) + '</div>';
        }
    }

    function poll() {
        if (document.visibilityState === 'hidden' || inFlight) return;
        inFlight = true;
        fetch('/api/indexer_status.php', {
            cache: 'no-store',
            headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            if (response.status === 401 || response.status === 403) {
                // The session expired (or the user lost admin rights): the card
                // must not keep showing what may now be forbidden to them.
                if (card) card.remove();
                card = null;
                stop();
                return null;
            }
            if (!response.ok) throw new Error('status ' + response.status);
            return response.json();
        }).then(function (data) {
            if (!data) return;
            if (data.available === false) {
                if (card) card.remove();
                card = null;
                stop();
                return;
            }
            render(data);
        }).catch(function () {
            // Keep the last good render. A transient fetch failure must not
            // replace a useful progress bar with an error message.
        }).finally(function () {
            inFlight = false;
        });
    }

    function start() {
        if (timer === null) timer = window.setInterval(poll, POLL_MS);
    }

    function stop() {
        if (timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        card = document.getElementById('indexer-card');
        if (!card) return;
        if (typeof window.awiBindCard === 'function') window.awiBindCard('indexer');
        var content = document.getElementById('indexer-content');
        if (content) {
            // A click on the summary flips <details>.open after this handler;
            // render() reads that state on the next poll and keeps it. Listening
            // for clicks rather than <details> "toggle" avoids mistaking the
            // default open state of a freshly inserted element for a choice.
            content.addEventListener('click', function (event) {
                var node = event.target;
                while (node && node !== content) {
                    if (node.tagName === 'SUMMARY' && node.parentNode
                        && node.parentNode.id === 'indexer-history') {
                        historyTouched = true;
                        return;
                    }
                    node = node.parentNode;
                }
            });
        }
        if (window.awiIndexerInitial) render(window.awiIndexerInitial);
        poll();
        start();
    });

    document.addEventListener('visibilitychange', function () {
        if (!card) return;
        if (document.visibilityState === 'visible') {
            poll();
            start();
        } else {
            stop();
        }
    });
})();
