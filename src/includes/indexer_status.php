<?php
/**
 * Indexer status card (home page, just before the footer).
 *
 * The Python container writes indexer_runs / indexer_watcher; this partial is
 * only the reading side. The card is admin-only when authentication is on: the
 * same rule as api/indexer_status.php, where it is actually enforced.
 *
 * The initial payload is rendered into the page so the first paint is already
 * correct; assets/js/indexer_status.js then polls the API every five seconds.
 */
require_once __DIR__ . '/indexer_functions.php';

if (!isAuthEnabled() || isAdmin()):
    if (!isset($indexerStatus) || !is_array($indexerStatus)) {
        if (!isset($conn) || !($conn instanceof PDO)) {
            return;
        }
        $indexerStatus = getIndexerStatus($conn, true);
    }
    if (empty($indexerStatus['available'])):
        return;
    endif;
    $cardOpen = awiCardOpen('indexer', !empty($indexerStatus['current']));
    $initialHeadline = !empty($indexerStatus['current'])
        ? __('indexer_running')
        : (!empty($indexerStatus['last'])
            ? (($indexerStatus['last']['status'] ?? '') === 'error'
                ? __('indexer_failed')
                : (($indexerStatus['last']['status'] ?? '') === 'interrupted'
                    ? __('indexer_status_interrupted')
                    : __('indexer_idle')))
            : __('indexer_no_runs'));
    $i18n = [
        'running' => __('indexer_running'),
        'idle' => __('indexer_idle'),
        'failed' => __('indexer_failed'),
        'interrupted' => __('indexer_status_interrupted'),
        'no_runs' => __('indexer_no_runs'),
        'last_run' => __('indexer_last_run'),
        'elapsed' => __('indexer_elapsed'),
        'eta' => __('indexer_eta'),
        'rate' => __('indexer_rate'),
        'errors' => __('indexer_errors'),
        'updated' => __('indexer_updated'),
        'stalled' => __('indexer_stalled'),
        'files_on_disk' => __('indexer_files_on_disk'),
        'files_indexed' => __('indexer_files_indexed'),
        'skipped' => __('indexer_skipped'),
        'metrics_pending' => __('indexer_metrics_pending'),
        'no_metrics_pending' => __('indexer_no_metrics_pending'),
        'watcher' => __('indexer_watcher'),
        'watcher_last_scan' => __('indexer_watcher_last_scan'),
        'watcher_failures' => __('indexer_watcher_failures'),
        'watcher_stale' => __('indexer_watcher_stale'),
        'history' => __('indexer_history'),
        'history_started' => __('indexer_history_started'),
        'history_reason' => __('indexer_history_reason'),
        'history_duration' => __('indexer_history_duration'),
        'history_files' => __('indexer_history_files'),
        'history_metrics' => __('indexer_history_metrics'),
        'history_errors' => __('indexer_history_errors'),
        'history_status' => __('indexer_history_status'),
        'ago' => __('indexer_ago'),
        'never' => __('indexer_never'),
        'reload' => __('indexer_reload'),
        'just_finished' => __('indexer_just_finished'),
    ];
?>
<div id="indexer-card" class="bg-gray-800 rounded-lg shadow-md mb-6 overflow-hidden">
    <div class="w-full flex flex-wrap items-center justify-between gap-2 p-4">
        <button type="button"
                id="indexer-toggle"
                aria-expanded="<?= $cardOpen ? 'true' : 'false' ?>"
                aria-controls="indexer-body"
                class="flex-1 flex flex-wrap items-center justify-between gap-2 hover:bg-gray-700/50 transition-colors text-left rounded">
            <span class="flex items-center gap-2 font-semibold text-gray-100">
                <span id="indexer-dot" class="inline-block w-2.5 h-2.5 rounded-full bg-gray-500" aria-hidden="true"></span>
                <span><?php echo __('indexer_status'); ?></span>
                <span id="indexer-headline" class="text-sm font-normal text-gray-400"><?= htmlspecialchars($initialHeadline) ?></span>
            </span>
            <svg id="indexer-chevron" class="w-5 h-5 text-gray-400 transition-transform duration-200"<?= $cardOpen ? ' style="transform: rotate(180deg)"' : '' ?> fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
        </button>
    </div>

    <div id="indexer-body" class="border-t border-gray-700<?= $cardOpen ? '' : ' hidden' ?>">
        <div id="indexer-content" class="p-4 text-sm text-gray-300">
            <p class="text-gray-400"><?= htmlspecialchars($initialHeadline) ?></p>
        </div>
    </div>
</div>
<script>
window.awiIndexerInitial = <?= json_encode($indexerStatus, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
window.awiIndexerI18n = <?= json_encode($i18n, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
</script>
<?php endif; ?>
