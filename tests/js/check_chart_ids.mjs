// Verifica §3 — i canvas dei chart delle integration group erano identificati
// dall'INDICE posizionale della metrica, non dalla chiave.
//
//   PHP  ['hfr', 'fwhm', 'hfr_sd', ...]   ->  -1 = fwhm
//   JS   ['hfr', 'hfr_sd', 'fwhm', ...]   ->  -1 = hfr_sd
//
// L'id e' ora 'ig-chart-<gruppo>-<chiave metrica>' in entrambi i lati.
//
// Uso:  node tmp/check_chart_ids.mjs

const phpOrder = ['hfr', 'fwhm', 'hfr_sd', 'eccentricity', 'star_count', 'snr_weight', 'psf_signal'];
const jsSeries = [
    { key: 'hfr' }, { key: 'hfr_sd' }, { key: 'fwhm' },
    { key: 'eccentricity' }, { key: 'star_count' }, { key: 'snr_weight' }, { key: 'psf_signal' },
];

console.log('--- schema VECCHIO: id posizionale ---');
let oldBad = 0;
jsSeries.forEach((s, mi) => {
    const label = phpOrder[mi];
    const ok = label === s.key;
    if (!ok) oldBad++;
    console.log(`  canvas -${mi}  etichetta=${label.padEnd(13)} dati=${s.key.padEnd(13)} ` +
        (ok ? 'ok' : '<<< SCAMBIATO'));
});
console.log(`  -> ${oldBad} canvas con etichetta e dati diversi\n`);

console.log('--- schema NUOVO: id per chiave ---');
let newBad = 0;
for (const key of phpOrder) {
    // il PHP emette id="ig-chart-G-<chiave>"; il JS cerca id="ig-chart-G-" + s.key
    const s = jsSeries.find((x) => x.key === key);
    if (!s) newBad++;
    console.log(`  canvas -${key.padEnd(13)} etichetta=${key.padEnd(13)} ` +
        `dati=${s ? s.key : 'NON TROVATO'} ${s ? 'ok' : '<<< ERRORE'}`);
}
console.log(`  -> ${newBad} mismatches`);

process.exit(oldBad > 0 && newBad === 0 ? 0 : 1);