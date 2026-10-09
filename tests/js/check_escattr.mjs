// Check §5 — escHtml() does not escape quotes, so it must not end up in an
// attribute. escAttr() adds " and '.
//
//   escHtml: textContent -> innerHTML  =>  escapes only & < >
//   payload: Ha" onmouseover="alert(1)  =>  before, it closes title= and injects a handler
//
// Usage:  node tmp/check_escattr.mjs

// A faithful approximation of escHtml: the browser converts neither " nor &apos; nor &#39;
// when going from textContent to innerHTML.
const escHtml = (s) => String(s ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const escAttr = (s) => escHtml(s)
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

const payloads = [
    'Ha" onmouseover="alert(1)',
    "Q99' onfocus='alert(2)",
    'L\'Ha" onmouseover=alert(3)',
    'normale',
];

let bad = 0;
for (const p of payloads) {
    const oldAttr = `title="${escHtml(p)}"`;
    const newAttr = `title="${escAttr(p)}"`;
    // a well-formed attribute contains exactly 2 quotes
    const clean = (attr) => (attr.match(/"/g) || []).length === 2;
    const ok = clean(newAttr) && !clean(oldAttr);
    if (!clean(newAttr)) bad++;
    console.log(`payload : ${p}`);
    console.log(`  before: ${oldAttr}${clean(oldAttr) ? '' : '   <<< attribute injected'}`);
    console.log(`  after : ${newAttr}`);
    console.log('');
}
console.log(bad === 0 ? 'OK: escAttr always closes the attribute'
                      : `FAILED: ${bad} unprotected cases`);
process.exit(bad === 0 ? 0 : 1);