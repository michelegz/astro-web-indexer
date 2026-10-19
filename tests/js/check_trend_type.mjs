// Check — the type of the trend charts: what the choice does to the cookie, what
// the old localStorage value does to it, and what the page renders.
//
//   main.js: awiSaveTrendType(mode)        awi_trend_type=bar|line|auto
//   main.js: awiAdoptLegacyTrendType()     the old localStorage value, once
//   includes/metrics_chart.php              the three buttons, the rendered mode
//
// The preference used to live in localStorage, where the server could not see
// it. It now lives in a cookie, read by the server (awiTrendChartMode(),
// template_functions.php) so the page comes back drawn the way the user left it.
//
// "auto" is not a shape: it is the absence of a choice, and the automatic shape
// depends on how many points the query returned. So the cookie holds the
// EXPLICIT choice, and a value nobody could have written is not read as one.
//
// Both functions are lifted out of main.js and run as they ship against a fake
// document: a copy of the logic here would keep passing after the real functions
// break. If the extraction stops matching, the test goes RED on purpose.
//
// Usage:  node tests/js/check_trend_type.mjs

import { readFileSync } from 'node:fs';

const mainJs = readFileSync(new URL('../../src/assets/js/main.js', import.meta.url), 'utf8');
const chartPhp = readFileSync(new URL('../../src/includes/metrics_chart.php', import.meta.url), 'utf8');

/** Lifts `function name(...) {...}` out of the source by brace matching. */
function lift(src, signature) {
    const at = src.indexOf(signature);
    if (at === -1) {
        console.log(`FAILED: ${signature} not found in main.js`);
        process.exit(1);
    }
    const open = src.indexOf('{', src.indexOf(')', at));
    let depth = 0;
    for (let i = open; i < src.length; i++) {
        if (src[i] === '{') depth++;
        else if (src[i] === '}') {
            depth--;
            if (depth === 0) return src.slice(at, i + 1);
        }
    }
    console.log(`FAILED: could not brace-match ${signature}`);
    process.exit(1);
}

// awiSaveTrendType() reads the expiry from a module-level const: it is part of the
// shipped behaviour (a cookie that outlives the year would keep a choice nobody
// can take back from the UI), so it comes along instead of being restated here.
const daysConst = /const\s+AWI_TREND_COOKIE_DAYS\s*=\s*[^;]+;/.exec(mainJs);
if (!daysConst) {
    console.log('FAILED: AWI_TREND_COOKIE_DAYS not found in main.js');
    process.exit(1);
}

const load = new Function('document', 'localStorage',
    daysConst[0] + '\n'
    + lift(mainJs, 'function awiSaveTrendType(mode)')
    + '\n' + lift(mainJs, 'function awiAdoptLegacyTrendType()')
    + '\nreturn { awiSaveTrendType, awiAdoptLegacyTrendType };');

/** A fake document whose cookie jar records what was written. */
function makeDocument() {
    const jar = [];
    return {
        jar,
        document: {
            get cookie() { return jar.join('; '); },
            set cookie(v) { jar.push(v); },
        },
    };
}

/** A fake localStorage, and the calls that reached it. */
function makeStorage(initial = {}) {
    const data = { ...initial };
    const calls = [];
    return {
        data,
        calls,
        storage: {
            getItem(k) { calls.push(['get', k]); return k in data ? data[k] : null; },
            removeItem(k) { calls.push(['remove', k]); delete data[k]; },
            setItem(k, v) { calls.push(['set', k]); data[k] = String(v); },
        },
    };
}

let bad = 0;
function check(label, cond, detail = '') {
    if (!cond) bad++;
    console.log(`  ${cond ? 'ok  ' : 'FAIL'}  ${label}${detail ? '   [' + detail + ']' : ''}`);
}

console.log('--- the choice goes into the cookie ---');
{
    const { document, jar } = makeDocument();
    const api = load(document, makeStorage().storage);
    const returned = api.awiSaveTrendType('bar');
    const c = jar.at(-1) ?? '';
    check('the cookie is named awi_trend_type', c.startsWith('awi_trend_type=bar'), c);
    check('and the function says what it wrote', returned === 'bar', returned);
    check('path=/', c.includes('path=/'), c);
    check('SameSite=Lax', c.toLowerCase().includes('samesite=lax'), c);
    const exp = /expires=([^;]+)/i.exec(c);
    const days = exp ? (Date.parse(exp[1]) - Date.now()) / 864e5 : NaN;
    check('expires in about a year', Math.abs(days - 365) < 2, `${days.toFixed(1)} days`);
}

console.log('\n--- lines, bars, and auto are all writable ---');
{
    const { document, jar } = makeDocument();
    const api = load(document, makeStorage().storage);
    check('lines', api.awiSaveTrendType('line') === 'line'
        && (jar.at(-1) ?? '').startsWith('awi_trend_type=line'), jar.at(-1));
    check('bars', api.awiSaveTrendType('bar') === 'bar'
        && (jar.at(-1) ?? '').startsWith('awi_trend_type=bar'), jar.at(-1));
    check('auto', api.awiSaveTrendType('auto') === 'auto'
        && (jar.at(-1) ?? '').startsWith('awi_trend_type=auto'), jar.at(-1));
}

console.log('\n--- a value nobody could have written is not a choice ---');
{
    const { document, jar } = makeDocument();
    const api = load(document, makeStorage().storage);
    for (const junk of ['nonsense', '', 'BAR', 'Line', null, undefined, 42]) {
        const returned = api.awiSaveTrendType(junk);
        check(`'${String(junk)}' is written as auto`,
            returned === 'auto' && (jar.at(-1) ?? '').startsWith('awi_trend_type=auto'),
            `${returned} / ${jar.at(-1)}`);
    }
}

console.log('\n--- the old localStorage value is adopted once ---');
{
    const { document } = makeDocument();
    const store = makeStorage({ awiTrendType: 'line' });
    const api = load(document, store.storage);
    check('the old value is read', api.awiAdoptLegacyTrendType() === 'line', '');
    check('and removed, so it happens exactly once', 'awiTrendType' in store.data === false,
        JSON.stringify(store.data));
    check('a second call finds nothing', api.awiAdoptLegacyTrendType() === null, '');
}

console.log('\n--- there is nothing to adopt ---');
{
    const { document } = makeDocument();
    const store = makeStorage();
    const api = load(document, store.storage);
    check('no old key: nothing adopted', api.awiAdoptLegacyTrendType() === null, '');
    check('the old key was looked for', store.calls.some(([op, k]) => op === 'get' && k === 'awiTrendType'),
        JSON.stringify(store.calls));

    const junk = makeStorage({ awiTrendType: 'nonsense' });
    const api2 = load(document, junk.storage);
    check('an old value that is not a shape: nothing adopted',
        api2.awiAdoptLegacyTrendType() === null, '');
}

console.log('\n--- private mode does not throw ---');
{
    const { document } = makeDocument();
    const hostile = {
        getItem() { throw new Error('denied'); },
        removeItem() { throw new Error('denied'); },
    };
    const api = load(document, hostile);
    let thrown = null;
    try { api.awiAdoptLegacyTrendType(); } catch (e) { thrown = e; }
    check('reading the old value fails quietly', thrown === null, thrown ? String(thrown) : '');
}

console.log('\n--- the page renders the three buttons ---');
{
    check('the line button', chartPhp.includes('id="trend-type-line"'), '');
    check('the bar button', chartPhp.includes('id="trend-type-bar"'), '');
    check('the auto button', chartPhp.includes('id="trend-type-auto"'), '');
    // The button carries a letter, not an emoji: a screen reader would announce
    // "A" and nothing else, so the label has to be there. The tag is read up to
    // its closing tag: the class attribute carries a PHP expression with a ">"
    // in it, so the tag cannot be cut at the first angle bracket.
    const autoAt = chartPhp.indexOf('id="trend-type-auto"');
    const autoTag = autoAt === -1 ? '' : chartPhp.slice(autoAt, chartPhp.indexOf('</button>', autoAt));
    check('the auto button names itself', autoTag.includes('aria-label="'), '');
    check('the mode comes from the server', /var chartMode =/.test(chartPhp), '');
    check('the type comes from the server', /var chartType =/.test(chartPhp), '');
    check('the automatic type comes from the server', /var autoType =/.test(chartPhp), '');
    // The old localStorage value is adopted only when the cookie is not there:
    // a cookie that is there is the user's answer, and the leftover value must
    // not overrule it, not even when the answer is "automatic".
    check('the page says whether the cookie is there', /var cookiePresent =/.test(chartPhp), '');
    check('and the adoption waits for it',
        /if \(!cookiePresent && typeof awiAdoptLegacyTrendType === 'function'\)/.test(chartPhp), '');
    check('the old localStorage read is gone', !chartPhp.includes("localStorage.getItem('awiTrendType')"), '');
    check('the old localStorage write is gone', !chartPhp.includes("localStorage.setItem('awiTrendType'"), '');
    check('a click on the auto button goes back to the automatic shape',
        /autoBtn\.addEventListener\('click', function\(\) \{ setChartType\('auto'\); \}\)/.test(chartPhp), '');
    check('the cookie is written on every choice',
        chartPhp.includes('awiSaveTrendType(chartMode)'), '');
}

console.log(bad === 0
    ? '\nOK: the choice is remembered in the cookie, and the old one is adopted once'
    : `\nFAILED: ${bad} case(s)`);
process.exit(bad === 0 ? 0 : 1);
