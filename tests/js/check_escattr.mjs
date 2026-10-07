// Verifica §5 — escHtml() non escapa le virgolette, quindi non puo' finire in un
// attributo. escAttr() aggiunge " e '.
//
//   escHtml: textContent -> innerHTML  =>  escapa solo & < >
//   payload: Ha" onmouseover="alert(1)  =>  prima chiude title= e inietta un handler
//
// Uso:  node tmp/check_escattr.mjs

// Approssimazione fedele di escHtml: il browser non converte " ne' &apos; ne' &#39;
// quando si passa da textContent a innerHTML.
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
    // un attributo ben formato contiene esattamente 2 virgolette
    const clean = (attr) => (attr.match(/"/g) || []).length === 2;
    const ok = clean(newAttr) && !clean(oldAttr);
    if (!clean(newAttr)) bad++;
    console.log(`payload : ${p}`);
    console.log(`  prima : ${oldAttr}${clean(oldAttr) ? '' : '   <<< attributo iniettato'}`);
    console.log(`  dopo  : ${newAttr}`);
    console.log('');
}
console.log(bad === 0 ? 'OK: escAttr chiude sempre l\'attributo'
                      : `FALLITO: ${bad} casi non protetti`);
process.exit(bad === 0 ? 0 : 1);