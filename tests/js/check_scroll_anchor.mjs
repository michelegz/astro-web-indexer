// Check — the scroll memory must bring back the thing that was ON SCREEN, not a
// pixel offset and not something below the fold.
//
//   main.js: awiAnchorInView() / awiCaptureScroll() / awiRestoreScroll()
//
// A pixel offset is the obvious implementation and the wrong one here: the table
// <img> carry no width/height (file_cells.php:209, class="thumb h-auto"), so every
// row starts flat and grows when /image.php hands over the blob, and the watcher
// indexes new files while the tab is closed. At the same offset a second later
// there is a different file.
//
// The second trap is the one that shipped broken. The first version asked for the
// first row with `bottom > 0`, which also accepts a row starting 3000px below the
// fold — and that is exactly the situation when ▲▼ is clicked: the button lives on
// a trend chart, the table is a screenful or two further down. The anchor was taken
// out of sight, and the page landed somewhere that looked arbitrary.
//
// Every case below runs the real functions, lifted out of main.js, against a fake
// page whose elements report the same rects a browser would.
//
// Usage:  node tests/js/check_scroll_anchor.mjs

import { readFileSync } from 'node:fs';

const mainJs = readFileSync(new URL('../../src/assets/js/main.js', import.meta.url), 'utf8');

/** Lifts `function NAME(...) {...}` (or an if/else block) by brace matching. */
function lift(signature) {
    const at = mainJs.indexOf(signature);
    if (at === -1) {
        console.log(`FAILED: ${signature} not found in main.js`);
        process.exit(1);
    }
    const open = mainJs.indexOf('{', mainJs.indexOf(')', at));
    let depth = 0;
    let i = open;
    for (; i < mainJs.length; i++) {
        if (mainJs[i] === '{') {
            depth++;
        } else if (mainJs[i] === '}') {
            depth--;
            if (depth === 0) {
                // An if/else is ONE statement. Stopping at the `}` that closes the
                // `if` silently drops the half that actually does the work.
                const m = /^\s*else\b/.exec(mainJs.slice(i + 1));
                if (!m) break;
                i += m[0].length - 1;
            }
        }
    }
    if (depth !== 0) {
        console.log(`FAILED: could not brace-match ${signature}`);
        process.exit(1);
    }
    return mainJs.slice(at, i + 1);
}

const keyConst = /const\s+AWI_SCROLL_KEY\s*=\s*'([^']+)'/.exec(mainJs);
const selConst = /const\s+AWI_ANCHOR_SELECTOR\s*=\s*'([^']+)'/.exec(mainJs);
if (!keyConst || !selConst) {
    console.log('FAILED: AWI_SCROLL_KEY / AWI_ANCHOR_SELECTOR not found in main.js');
    process.exit(1);
}
const liftAll = new Function('document', 'window', 'sessionStorage', 'URLSearchParams',
    `const AWI_SCROLL_KEY = ${JSON.stringify(keyConst[1])};\n`
    + `const AWI_ANCHOR_SELECTOR = ${JSON.stringify(selConst[1])};\n`
    + lift('function awiAnchorInView()')
    + '\n' + lift('function awiFindAnchor(kind, key)')
    + '\n' + lift('function awiCaptureScroll()')
    + '\n' + lift('function awiRestoreScroll()')
    // The scheduling block too: WHEN the restore runs is half the design, and it
    // lives outside the functions.
    + '\nfunction scheduleRestore() {\n' + lift('if (document.readyState ===') + '\n}\n'
    + '\nreturn { awiAnchorInView, awiFindAnchor, awiCaptureScroll, awiRestoreScroll, scheduleRestore };');
const KEY = keyConst[1];

/**
 * Does `el` match one simple selector part? The fake DOM resolves selectors on the
 * elements' own attributes and classes, NOT on the selector the code happens to
 * use: a page that mirrors the code's selector back cannot notice the code asking
 * for the wrong things.
 */
function matches(el, part) {
    const attr = /\[data-(scroll-anchor|id)(?:="([^"]*)")?\]/.exec(part);
    if (!attr) return false;
    const [, name, value] = attr;
    const key = name === 'scroll-anchor' ? 'scrollAnchor' : 'id';
    if (el.dataset[key] === undefined) return false;
    if (value !== undefined && el.dataset[key] !== value) return false;
    return !/\.selectable-item/.test(part) || el.classes.has('selectable-item');
}

/**
 * A page. `items` are the anchorable things in DOCUMENT order:
 *   { kind: 'row', id, top, height, hidden }
 *   { kind: 'el',  key, top, height, hidden }
 * A hidden item measures 0, like display:none does.
 * `store` is shared between the two pages of a simulated navigation.
 */
function makePage(items, search, store = new Map(), opts = {}) {
    const { readyState = 'interactive', innerHeight = 900 } = opts;
    const listeners = [];
    const win = {
        scrollY: 0,
        innerHeight,
        scrolls: [],
        location: { search },
        addEventListener: (type, fn) => listeners.push([type, fn]),
        scrollTo(_x, y) { this.scrollY = y; this.scrolls.push(y); },
    };
    const els = items.map((it) => ({
        dataset: it.kind === 'el' ? { scrollAnchor: it.key } : { id: String(it.id) },
        classes: new Set(it.kind === 'el' ? ['chart-block'] : ['selectable-item']),
        getBoundingClientRect: () => (it.hidden
            ? { top: 0, bottom: 0 }
            : { top: it.top - win.scrollY, bottom: it.top - win.scrollY + (it.height ?? 60) }),
    }));
    const document = {
        readyState,
        querySelectorAll: (selector) => els.filter((el) =>
            selector.split(',').some((part) => matches(el, part.trim()))),
    };
    const sessionStorage = {
        getItem: (k) => (store.has(k) ? store.get(k) : null),
        setItem: (k, v) => store.set(k, v),
        removeItem: (k) => store.delete(k),
    };
    const api = liftAll(document, win, sessionStorage, URLSearchParams);
    return { ...api, win, store, listeners, saved: () => (store.has(KEY) ? JSON.parse(store.get(KEY)) : null) };
}

const rows = (n, firstTop = 0, rowHeight = 60) =>
    Array.from({ length: n }, (_, i) => ({ kind: 'row', id: i + 1, top: firstTop + i * rowHeight, height: rowHeight }));

let bad = 0;
function check(label, cond, detail = '') {
    if (!cond) bad++;
    console.log(`  ${cond ? 'ok  ' : 'FAIL'}  ${label}${detail ? '   [' + detail + ']' : ''}`);
}

console.log('--- the anchor is the first thing ON SCREEN ---');
{
    const p = makePage(rows(400), '?dir=M33&sort_by=hfr&sort_order=ASC');
    p.win.scrollY = 9000;
    p.awiCaptureScroll();
    const s = p.saved();
    check('a table row in view anchors the row', s && s.kind === 'row' && s.key === '151',
        s && `kind=${s.kind} key=${s.key}`);
    check('at the right distance from the top edge', s && s.top === 0, s && `top=${s.top}`);
}
{
    // A row cut off by the top edge is still the anchor, with a negative offset, so
    // the same part of it comes back.
    const p = makePage(rows(400), '?dir=M33');
    p.win.scrollY = 9020;
    p.awiCaptureScroll();
    const s = p.saved();
    check('a half-visible row keeps its negative offset', s && s.key === '151' && s.top === -20,
        s && `key=${s.key} top=${s.top}`);
}

console.log('\n--- THE BUG: clicking ▲▼ with the table below the fold ---');
{
    // The real layout: 7 trend charts (~230px each), then the table.
    // The user is looking at the 5th chart when they click its ▲▼.
    const charts = Array.from({ length: 7 }, (_, i) => ({
        kind: 'el', key: `trend-${i}`, top: i * 230, height: 230,
    }));
    const table = rows(30, 7 * 230, 60);
    const p = makePage([...charts, ...table], '?dir=M33&sort_by=name');
    p.win.scrollY = 4 * 230; // the 5th chart fills the viewport
    p.awiCaptureScroll();
    const s = p.saved();
    check('it anchors on the chart, not on a table row nobody can see',
        s && s.kind === 'el' && s.key === 'trend-4', s && `kind=${s.kind} key=${s.key}`);
    check('with a sane offset', s && s.top === 0, s && `top=${s.top}`);

    // And the restore puts that same chart back under the cursor.
    const after = makePage([...charts, ...table], '?dir=M33&sort_by=hfr', p.store);
    after.awiRestoreScroll();
    check('the chart comes back where it was', after.win.scrollY === 4 * 230,
        `scrollY=${after.win.scrollY}`);
}
{
    // The same situation, and this time the table IS on screen: the row wins,
    // because the row is what the user is looking at.
    const charts = Array.from({ length: 7 }, (_, i) => ({
        kind: 'el', key: `trend-${i}`, top: i * 230, height: 230,
    }));
    const table = rows(30, 7 * 230, 60);
    const p = makePage([...charts, ...table], '?dir=M33&sort_by=name');
    p.win.scrollY = 7 * 230 + 300; // 300px into the table
    p.awiCaptureScroll();
    const s = p.saved();
    check('with a row on screen the row is the anchor', s && s.kind === 'row' && s.key === '6',
        s && `kind=${s.kind} key=${s.key}`);
}
{
    // Nothing anchorable on screen (the user is in the sidebar): no anchor is
    // better than one invented from an off-screen row.
    const p = makePage([...rows(30, 5000)], '?dir=M33&sort_by=name');
    p.win.scrollY = 0;
    p.awiCaptureScroll();
    check('nothing on screen -> no anchor, no invention', p.saved() === null,
        p.saved() ? `invented ${p.saved().key}` : '');
}

console.log('\n--- a re-sort brings the SAME anchor back to the SAME height ---');
{
    const store = new Map();
    const before = makePage(rows(400), '?dir=M33&sort_by=name&sort_order=ASC', store);
    before.win.scrollY = 9020;
    before.awiCaptureScroll();

    // Same rows, reordered: row 151 moved to the 250th slot. (Reversing the array
    // is not enough — each row carries its position, and that has to change too.)
    const reordered = rows(400).map((r, i) => ({ ...r, top: (399 - i) * 60 }));
    const after = makePage(reordered, '?dir=M33&sort_by=hfr&sort_order=DESC', store);
    after.awiRestoreScroll();
    check('the anchored row is back at the same place', after.win.scrollY === 14960,
        `scrollY=${after.win.scrollY}`);
    check('and it scrolled exactly once', after.win.scrolls.length === 1);
}
{
    // A page that GREW while the tab was away: a naive pixel offset would land on a
    // different file, this one follows the row.
    const store = new Map();
    const before = makePage(rows(400), '?dir=M33&sort_by=name', store);
    before.win.scrollY = 9000;
    before.awiCaptureScroll();

    const grown = [{ kind: 'row', id: 9001, top: 0, height: 60 },
        ...rows(400).map((r) => ({ ...r, top: r.top + 40 * 60 }))];
    const after = makePage(grown, '?dir=M33&sort_by=hfr', store);
    after.awiRestoreScroll();
    check('a page that grew still lands on the anchored row', after.win.scrollY === 40 * 60 + 9000,
        `scrollY=${after.win.scrollY}`);
}

console.log('\n--- it fires only for its own navigation ---');
{
    const p = makePage(rows(10), '?dir=M31&sort_by=fwhm&sort_order=DESC');
    p.store.set(KEY, JSON.stringify({ kind: 'row', key: '4', top: 0, ctx: 'dir=M33&object=M31' }));
    p.awiRestoreScroll();
    check('a different view does not scroll', p.win.scrolls.length === 0, `${p.win.scrolls.length} scrolls`);
    check('and the stale entry is gone anyway', p.store.has(KEY) === false);
}
{
    const store = new Map();
    const before = makePage(rows(10), '?dir=M33&sort_by=name', store);
    before.win.scrollY = 180;
    before.awiCaptureScroll();

    // Same anchor, but the user changed a filter instead of sorting.
    const after = makePage(rows(10), '?dir=M33&object=M31&sort_by=hfr', store);
    after.awiRestoreScroll();
    check('a changed filter does not scroll', after.win.scrolls.length === 0,
        `${after.win.scrolls.length} scrolls`);
}

console.log('\n--- it is one shot ---');
{
    const p = makePage(rows(10), '?dir=M33');
    p.store.set(KEY, JSON.stringify({ kind: 'row', key: '8', top: -30, ctx: 'dir=M33' }));
    p.awiRestoreScroll();
    const after = p.win.scrolls.length;
    p.awiRestoreScroll();
    check('the first load scrolls', after === 1, `${after} scroll`);
    check('a later visit finds nothing to do', p.win.scrolls.length === after,
        `${p.win.scrolls.length} scrolls`);
}

console.log('\n--- an anchor that is not on the page does not scroll into the void ---');
{
    const p = makePage(rows(3), '?dir=M33');
    p.store.set(KEY, JSON.stringify({ kind: 'row', key: '9999', top: 0, ctx: 'dir=M33' }));
    p.awiRestoreScroll();
    check('missing row -> no scroll, no error', p.win.scrolls.length === 0);
    p.store.set(KEY, JSON.stringify({ kind: 'el', key: 'trend-9', top: 0, ctx: 'dir=M33' }));
    p.awiRestoreScroll();
    check('missing chart -> no scroll, no error', p.win.scrolls.length === 0);
}

console.log('\n--- a tampered entry cannot become a selector ---');
{
    for (const key of ['1"] *', "1'||1", 'a b', 'x}#', '']) {
        const p = makePage(rows(3), '?dir=M33');
        p.store.set(KEY, JSON.stringify({ kind: 'el', key, top: 0, ctx: 'dir=M33' }));
        let threw = false;
        try { p.awiRestoreScroll(); } catch (e) { threw = true; }
        check(`key=${JSON.stringify(key)} is refused`, !threw && p.win.scrolls.length === 0,
            threw ? 'threw' : `${p.win.scrolls.length} scrolls`);
    }
}

console.log('\n--- the hidden view is not mistaken for the visible one ---');
{
    // table.php always prints both views and hides one with display:none, so half
    // the rows in the DOM measure 0.
    const specs = [
        ...rows(4).map((r) => ({ ...r, hidden: true })),
        ...rows(4).map((r) => ({ ...r, hidden: false })),
    ];
    const p = makePage(specs, '?dir=M33');
    p.win.scrollY = 120;
    p.awiCaptureScroll();
    const s = p.saved();
    check('it anchored in the visible view', s && s.key === '3', s && `key=${s.key}`);
    check('and ignored the hidden rows above it', s && s.top === 0, s && `top=${s.top}`);

    // And the same trap on the way back: the lookup must skip the hidden twin too,
    // or it would restore against a box that is not on the page.
    const after = makePage(specs, '?dir=M33&sort_by=fwhm', p.store);
    after.win.scrollY = 0;
    after.awiRestoreScroll();
    check('the restore picks the visible twin, not the hidden one',
        after.win.scrollY === 120, `scrollY=${after.win.scrollY}`);
}

console.log('\n--- the anchor selector names what it must name ---');
{
    // If the charts drop out of the selector, clicking ▲▼ has nothing to anchor
    // on and the whole feature silently falls back to "the page starts at the top".
    const p = makePage(
        [{ kind: 'el', key: 'trend-3', top: 0, height: 230 }, ...rows(5, 230)],
        '?dir=M33&sort_by=name');
    p.win.scrollY = 0;
    p.awiCaptureScroll();
    check('a trend chart block is anchorable', p.saved() && p.saved().kind === 'el',
        p.saved() && JSON.stringify(p.saved()));
}

console.log('\n--- the restore waits for the page, not for the DOM ---');
{
    // On DOMContentLoaded the rows are still flat: the <img> have no width/height,
    // so the document is much shorter than it will be and any offset computed then
    // points at the wrong file. `load` is when the layout is final.
    const p = makePage(rows(50), '?dir=M33', new Map(), { readyState: 'loading' });
    p.scheduleRestore();
    const types = p.listeners.map(([t]) => t);
    check('it is wired to load', types.includes('load'), types.join(', '));
    check('and not to DOMContentLoaded', !types.includes('DOMContentLoaded'), types.join(', '));
}
{
    // Already complete (a late script, a bfcache return): run it at once, or it
    // would wait for an event that has been and gone.
    const p = makePage(rows(50), '?dir=M33', new Map(), { readyState: 'complete' });
    p.store.set(KEY, JSON.stringify({ kind: 'row', key: '8', top: 0, ctx: 'dir=M33' }));
    p.scheduleRestore();
    check('a page already loaded restores immediately', p.win.scrolls.length === 1,
        `${p.win.scrolls.length} scrolls`);
}

console.log(bad === 0
    ? '\nOK: the anchor is what was on screen, and it only fires for its own navigation'
    : `\nFAILED: ${bad} case(s)`);
process.exit(bad === 0 ? 0 : 1);