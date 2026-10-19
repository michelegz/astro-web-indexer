// Check — awiBindCard() must persist the card state to the cookie, and the
// three DOM signals must move together with it.
//
//   main.js: awiSaveCardState(id, open)   card_<id>=1|0
//   main.js: awiBindCard(id, onOpen)      the toggle shared by the 3 cards
//
// The PHP side (awiCardOpen(), template_functions.php) renders the card from
// that cookie, and the inline script of each partial only ever TOGGLES. So a
// click that moves the body without writing the cookie is a card that forgets
// itself on reload, and one that writes the cookie without moving the chevron
// is a card whose arrow contradicts its content.
//
// Both functions are lifted out of main.js and run as they ship against a fake
// DOM: a copy of the logic here would keep passing after the real functions
// break. If the extraction stops matching, the test goes RED on purpose — a
// rewritten binder deserves a look at this gate, not a silent skip.
//
// Usage:  node tests/js/check_card_cookie.mjs

import { readFileSync } from 'node:fs';

const mainJs = readFileSync(new URL('../../src/assets/js/main.js', import.meta.url), 'utf8');

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

// --- a DOM small enough to read, big enough to run the real code --------------
function makeElement(initialClasses = []) {
    const classes = new Set(initialClasses);
    return {
        attrs: {},
        style: {},
        classList: {
            toggle: (name, force) => {
                const want = force === undefined ? !classes.has(name) : !!force;
                if (want) classes.add(name); else classes.delete(name);
                return want;
            },
            contains: (name) => classes.has(name),
        },
        setAttribute(name, value) { this.attrs[name] = value; },
        getAttribute(name) { return this.attrs[name]; },
        fire() { this.listeners.forEach((fn) => fn()); },
        listeners: [],
        addEventListener(_type, fn) { this.listeners.push(fn); },
        get hidden() { return classes.has('hidden'); },
        get classes() { return [...classes]; },
    };
}

function makeDocument(cards) {
    const els = {};
    for (const [id, initialClasses] of Object.entries(cards)) {
        els[`${id}-toggle`] = makeElement();
        els[`${id}-body`] = makeElement(initialClasses);
        els[`${id}-chevron`] = makeElement();
    }
    return { cookieJar: [], els, document: {
        cookie: '',
        getElementById: (id) => els[id] ?? null,
    } };
}

// awiSaveCardState() reads the expiry from a module-level const: it is part of the
// shipped behaviour (a cookie that outlives the year would keep a state nobody can
// clear from the UI), so it comes along instead of being restated here.
const daysConst = /const\s+AWI_CARD_COOKIE_DAYS\s*=\s*[^;]+;/.exec(mainJs);
if (!daysConst) {
    console.log('FAILED: AWI_CARD_COOKIE_DAYS not found in main.js');
    process.exit(1);
}

const load = new Function('document',
    daysConst[0] + '\n'
    + lift(mainJs, 'function awiSaveCardState(id, open)')
    + '\n' + lift(mainJs, 'function awiBindCard(id, onOpen)')
    + '\nreturn { awiSaveCardState, awiBindCard };');

function bind(cards, id, onOpen) {
    const { document, cookieJar, els } = makeDocument(cards);
    // The fake cookie is writable: `document.cookie = ...` in the real code has to
    // land in the jar, so the assignment is intercepted.
    Object.defineProperty(document, 'cookie', {
        get: () => cookieJar.join('; '),
        set: (v) => cookieJar.push(v),
    });
    const api = load(document);
    api.awiBindCard(id, onOpen);
    return { api, els, cookieJar };
}

let bad = 0;
function check(label, cond, detail = '') {
    if (!cond) bad++;
    console.log(`  ${cond ? 'ok  ' : 'FAIL'}  ${label}${detail ? '   [' + detail + ']' : ''}`);
}

console.log('--- an open card closes on the first click and says so in the cookie ---');
{
    const { els, cookieJar } = bind({ filters: [] }, 'filters');
    els['filters-toggle'].fire();
    check('the body got the hidden class', els['filters-body'].hidden);
    check('aria-expanded went false', els['filters-toggle'].getAttribute('aria-expanded') === 'false',
        els['filters-toggle'].getAttribute('aria-expanded'));
    check('the chevron dropped its rotation', els['filters-chevron'].style.transform === '');
    check('the cookie records "collapsed"', cookieJar.at(-1)?.startsWith('card_filters=0'), cookieJar.at(-1));
}

console.log('\n--- and reopens, remembering the opposite state ---');
{
    const { els, cookieJar } = bind({ filters: ['hidden'] }, 'filters');
    els['filters-chevron'].style.transform = '';
    els['filters-toggle'].fire();
    check('the body came back visible', !els['filters-body'].hidden);
    check('aria-expanded went true', els['filters-toggle'].getAttribute('aria-expanded') === 'true');
    check('the chevron points up again', els['filters-chevron'].style.transform === 'rotate(180deg)',
        els['filters-chevron'].style.transform);
    check('the cookie records "open"', cookieJar.at(-1)?.startsWith('card_filters=1'), cookieJar.at(-1));
}

console.log('\n--- a card never touched writes nothing ---');
{
    const { els, cookieJar } = bind({ stats: ['hidden'], trend: ['hidden'] }, 'stats');
    check('binding alone writes no cookie', cookieJar.length === 0, `${cookieJar.length} cookies`);
    check('and leaves the card collapsed', els['stats-body'].hidden);
}

console.log('\n--- the cookie is scoped and lasts a year ---');
{
    const { api, cookieJar } = bind({ stats: ['hidden'] }, 'stats');
    api.awiSaveCardState('stats', true);
    const c = cookieJar.at(-1) ?? '';
    check('path=/', c.includes('path=/'), c);
    check('SameSite=Lax', c.toLowerCase().includes('samesite=lax'));
    const exp = /expires=([^;]+)/i.exec(c);
    const days = exp ? (Date.parse(exp[1]) - Date.now()) / 864e5 : NaN;
    check('expires in about a year', Math.abs(days - 365) < 2, `${days.toFixed(1)} days`);
}

console.log('\n--- onOpen fires on opening only, once per opening ---');
{
    // This is the metrics card: it builds its charts the first time it is revealed.
    let builds = 0;
    const { els } = bind({ trend: ['hidden'] }, 'trend', () => builds++);
    els['trend-toggle'].fire();   // opens
    check('opened -> callback ran', builds === 1, `${builds} calls`);
    els['trend-toggle'].fire();   // closes
    check('closed -> no callback', builds === 1, `${builds} calls`);
    els['trend-toggle'].fire();   // opens again
    check('opened again -> callback ran', builds === 2, `${builds} calls`);
}

console.log('\n--- one card does not drag the others along ---');
{
    const { els, cookieJar } = bind({ filters: [], stats: ['hidden'], trend: ['hidden'] }, 'stats');
    els['stats-toggle'].fire();
    check('stats opened', !els['stats-body'].hidden);
    check('filters untouched', !els['filters-body'].hidden);
    check('trend untouched', els['trend-body'].hidden);
    check('only the stats cookie was written',
        cookieJar.length === 1 && cookieJar[0].startsWith('card_stats='),
        cookieJar.join(' | '));
}

console.log('\n--- a card missing its elements is a no-op, not a crash ---');
{
    const { api } = bind({}, 'ghost');
    check('binding a card that is not on the page returns quietly', typeof api.awiBindCard === 'function');
}

console.log(bad === 0
    ? '\nOK: the toggle, the three DOM signals and the cookie always move together'
    : `\nFAILED: ${bad} case(s)`);
process.exit(bad === 0 ? 0 : 1);