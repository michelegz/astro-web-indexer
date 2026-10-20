// Check — the live indexer card must not undo a manual "recent runs" toggle on
// every poll.
//
// indexer_status.js repaints #indexer-content with innerHTML every five
// seconds. Rebuilding the <details> would otherwise reset it to the default
// (open while idle, closed during a run) and the user's click would be lost.
//
// The real file is run against a fake DOM: a click on the summary records an
// explicit choice, and the next poll must read that element's open state before
// replacing it. A card nobody touched keeps following the idle/active default.
//
// Usage:  node tests/js/check_indexer_history_state.mjs

import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../../src/assets/js/indexer_status.js', import.meta.url), 'utf8');

let bad = 0;
function check(label, cond, detail = '') {
    if (!cond) bad++;
    console.log(`  ${cond ? 'ok  ' : 'FAIL'}  ${label}${detail ? '   [' + detail + ']' : ''}`);
}

function runPayload(current, last = null) {
    return {
        available: true,
        current,
        last,
        archive: { metrics_pending: 0 },
        watcher: null,
        history: [
            {
                status: 'done',
                started_age: 60,
                reason_label: 'manual',
                elapsed_seconds: 10,
                files_done: 5,
                metrics_done: 5,
                error_count: 0,
                status_label: 'done',
            },
        ],
    };
}

const activeRun = {
    phase_label: 'Scanning',
    phase_done: 1,
    phase_total: 10,
    percent: 10,
    files_scanned: 1,
    db_files: 1,
    skipped: 0,
    eta_seconds: 100,
    elapsed_seconds: 5,
    rate_per_minute: 12,
    error_count: 0,
    heartbeat_age: 1,
    stalled: false,
    last_error: null,
    status: 'running',
};

const finishedRun = {
    status: 'done',
    status_label: 'done',
    reason_label: 'manual',
    elapsed_seconds: 10,
    files_done: 5,
    metrics_done: 5,
    error_count: 0,
    finished_age: 30,
    last_error: null,
};

// --- a DOM just real enough for the card -------------------------------------
function makeHarness(initial, next) {
    let details = null;
    let contentHtml = '';
    let domReady = null;
    let contentClick = null;
    let intervalFn = null;
    let removed = false;
    let fetches = 0;

    const card = { remove() { removed = true; } };
    const headline = { innerHTML: '' };
    const dot = { className: '' };

    const content = {
        addEventListener(type, fn) {
            if (type === 'click') contentClick = fn;
        },
    };
    Object.defineProperty(content, 'innerHTML', {
        get: () => contentHtml,
        set: (html) => {
            contentHtml = html;
            const tag = /<details id="indexer-history"[^>]*>/.exec(html);
            // A browser builds a new <details> from the markup, so its open
            // property starts from the attribute the renderer emitted.
            details = tag ? { id: 'indexer-history', open: /\bopen\b/.test(tag[0]) } : null;
        },
    });

    const els = {
        'indexer-card': card,
        'indexer-headline': headline,
        'indexer-dot': dot,
        'indexer-content': content,
    };

    const document = {
        visibilityState: 'hidden', // keep the initial poll quiet; the test drives it
        getElementById(id) {
            if (id === 'indexer-history') return details;
            return els[id] ?? null;
        },
        addEventListener(type, fn) {
            if (type === 'DOMContentLoaded') domReady = fn;
        },
    };

    const window = {
        awiIndexerI18n: {},
        awiIndexerInitial: initial,
        awiBindCard() {},
        setInterval(fn) { intervalFn = fn; return 1; },
        clearInterval() {},
    };

    const fetch = () => {
        fetches++;
        return Promise.resolve({
            status: 200,
            ok: true,
            json: () => Promise.resolve(next),
        });
    };

    new Function('window', 'document', 'fetch', source)(window, document, fetch);
    domReady();

    return {
        get details() { return details; },
        get html() { return contentHtml; },
        get removed() { return removed; },
        get fetches() { return fetches; },
        clickSummary() {
            if (!details) return;
            if (contentClick) {
                contentClick({ target: { tagName: 'SUMMARY', parentNode: details } });
            }
            // The browser flips the state after dispatching the click.
            details.open = !details.open;
        },
        async poll() {
            document.visibilityState = 'visible';
            intervalFn();
            // fetch -> json -> render -> finally; a macrotask drains them all.
            await new Promise((resolve) => setTimeout(resolve, 0));
            document.visibilityState = 'hidden';
        },
    };
}

async function flush() {
    await new Promise((resolve) => setTimeout(resolve, 0));
}

console.log('--- while a run is active the history starts folded ---');
const h = makeHarness(runPayload(activeRun), runPayload(activeRun));
await flush();
check('the details exists', !!h.details);
check('it starts closed', h.details?.open === false);

console.log('\n--- opening it survives the next poll ---');
h.clickSummary();
await h.poll();
check('the poll repainted the card', h.fetches >= 1, `${h.fetches} fetches`);
check('the details is still open', h.details?.open === true);
check('the markup carries the open attribute', /\bopen\b/.test(h.html));

console.log('\n--- closing it survives the next poll too ---');
h.clickSummary();
await h.poll();
check('the details is still closed', h.details?.open === false);
check('the markup no longer carries the open attribute', !/(<details id="indexer-history"[^>]*)\bopen\b/.test(h.html));

console.log('\n--- an untouched idle card still opens the history by itself ---');
const idle = makeHarness(runPayload(null, finishedRun), runPayload(null, finishedRun));
await flush();
check('the idle default is open', idle.details?.open === true);

console.log('\n--- without a user choice it keeps following the run state ---');
const auto = makeHarness(runPayload(null, finishedRun), runPayload(activeRun));
await flush();
check('it starts open while idle', auto.details?.open === true);
await auto.poll();
check('a run starting folds it', auto.details?.open === false);

console.log('\n--- and a run starting does not reopen a history the user closed ---');
const closing = makeHarness(runPayload(null, finishedRun), runPayload(activeRun));
await flush();
closing.clickSummary();
await closing.poll();
check('the run is now active', /Scanning/.test(closing.html), closing.html.slice(0, 80));
check('the user choice still wins', closing.details?.open === false);

console.log(bad === 0
    ? '\nOK: a manual history toggle survives every poll'
    : `\nFAILED: ${bad} case(s)`);
process.exit(bad === 0 ? 0 : 1);
