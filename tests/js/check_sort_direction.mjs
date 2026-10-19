// Check — sortTable() must take an EXPLICIT direction without flipping it.
//
//   main.js: window.sortTable = (column, order) => {...}
//
// It has two callers with two different meanings:
//
//   - template_functions.php:55  onclick="sortTable('hfr')"   column headers -> TOGGLE
//   - metrics_chart.php:76/80    onclick="sortTable('hfr','ASC')" the ▲▼ buttons
//                                                                -> JUMP to that direction
//
// With one meaning for both, ▲ on a table already sorted ascending by HFR would send
// it to DESC: the button says "sort ascending", the page sorts descending, and since
// the trend card follows the table order the chart visibly flips instead of obeying.
// Nothing errors, so only the URL can tell.
//
// The function is lifted out of main.js and run as it ships, rather than modelled:
// a copy of the logic here would keep passing after the real function breaks. If the
// extraction stops matching, the test goes RED on purpose — a rewritten sortTable
// deserves a look at this gate, not a silent skip.
//
// Usage:  node tests/js/check_sort_direction.mjs

import { readFileSync } from 'node:fs';

const mainJs = readFileSync(new URL('../../src/assets/js/main.js', import.meta.url), 'utf8');

// --- lift window.sortTable out of main.js -------------------------------------
const decl = mainJs.indexOf('window.sortTable =');
if (decl === -1) {
    console.log('FAILED: window.sortTable not found in main.js');
    process.exit(1);
}
const open = mainJs.indexOf('{', decl);
let depth = 0;
let close = -1;
for (let i = open; i < mainJs.length; i++) {
    const c = mainJs[i];
    if (c === '{') depth++;
    else if (c === '}') {
        depth--;
        if (depth === 0) { close = i; break; }
    }
}
if (close === -1) {
    console.log('FAILED: could not brace-match window.sortTable in main.js');
    process.exit(1);
}
const lifted = mainJs.slice(decl, close + 2) + ' return window.sortTable;';

// The body only touches window.location and the URLSearchParams global, both
// available here: a stub window is enough to run the shipped code unmodified.
const build = new Function('window', lifted);

/** Runs the real sortTable over a fake location and returns the new query string. */
function sortFrom(search, ...args) {
    const window = { location: { search } };
    const sortTable = build(window);
    if (typeof sortTable !== 'function') {
        console.log('FAILED: main.js no longer assigns a function to window.sortTable');
        process.exit(1);
    }
    sortTable(...args);
    return new URLSearchParams(window.location.search);
}

let bad = 0;
function check(label, search, args, expected) {
    const p = sortFrom(search, ...args);
    const got = { sort_by: p.get('sort_by'), sort_order: p.get('sort_order'), page: p.get('page') };
    const ok = Object.entries(expected).every(([k, v]) => got[k] === v);
    if (!ok) bad++;
    console.log(`  ${ok ? 'ok  ' : 'FAIL'}  ${label}`);
    console.log(`        from ${search || '(no query)'}`);
    console.log(`        sortTable(${[...args].map(a => `'${a}'`).join(', ')})`);
    console.log(`        -> sort_by=${got.sort_by} sort_order=${got.sort_order} page=${got.page}`);
    if (!ok) console.log(`        expected ${JSON.stringify(expected)}`);
}

console.log('--- column headers: one argument, TOGGLE (unchanged behaviour) ---');
check('first click on a column', '?dir=M33',
    ['hfr'], { sort_by: 'hfr', sort_order: 'ASC', page: '1' });
check('same column, already ASC -> flips to DESC', '?sort_by=hfr&sort_order=ASC&page=1',
    ['hfr'], { sort_by: 'hfr', sort_order: 'DESC', page: '1' });
check('same column, already DESC -> flips to ASC', '?sort_by=hfr&sort_order=DESC&page=1',
    ['hfr'], { sort_by: 'hfr', sort_order: 'ASC', page: '1' });
check('different column -> starts at ASC', '?sort_by=hfr&sort_order=DESC&page=1',
    ['fwhm'], { sort_by: 'fwhm', sort_order: 'ASC', page: '1' });

console.log('\n--- trend chart ▲▼: two arguments, JUMP (never flips) ---');
check('▼ on a table sorted ascending by HFR', '?sort_by=hfr&sort_order=ASC&page=1',
    ['hfr', 'DESC'], { sort_by: 'hfr', sort_order: 'DESC', page: '1' });
check('▲ on a table sorted DESCENDING by HFR stays ascending', '?sort_by=hfr&sort_order=DESC&page=1',
    ['hfr', 'ASC'], { sort_by: 'hfr', sort_order: 'ASC', page: '1' });
check('▲ on an already-ASC table does NOT flip (the whole point)', '?sort_by=hfr&sort_order=ASC&page=1',
    ['hfr', 'ASC'], { sort_by: 'hfr', sort_order: 'ASC', page: '1' });
check('▼ on an already-DESC table does NOT flip', '?sort_by=hfr&sort_order=DESC&page=1',
    ['hfr', 'DESC'], { sort_by: 'hfr', sort_order: 'DESC', page: '1' });
check('an unknown direction falls back to the toggle, never to a silent no-op',
    '?sort_by=hfr&sort_order=ASC&page=1', ['hfr', 'down'],
    { sort_by: 'hfr', sort_order: 'DESC', page: '1' });

console.log('\n--- the active filters survive, the page does not ---');
const p = sortFrom('?dir=M33&object=M31&filter=Ha&exptime_min=60&page=7', 'hfr', 'DESC');
const kept = ['dir', 'object', 'filter', 'exptime_min'];
const lost = kept.filter((k) => p.get(k) === null);
console.log(`  ${lost.length === 0 ? 'ok  ' : 'FAIL'}  filters preserved: ${kept.map((k) => `${k}=${p.get(k)}`).join(' ')}`);
if (lost.length) { bad++; console.log(`        lost: ${lost.join(', ')}`); }
console.log(`  ${p.get('page') === '1' ? 'ok  ' : 'FAIL'}  page reset to 1 (got ${p.get('page')})`);
if (p.get('page') !== '1') bad++;

console.log(bad === 0
    ? '\nOK: an explicit direction is honoured, a missing one still toggles'
    : `\nFAILED: ${bad} case(s)`);
process.exit(bad === 0 ? 0 : 1);