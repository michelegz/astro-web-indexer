// Check §3 — the integration group chart canvases were identified by the
// positional INDEX of the metric, not by its key.
//
//   PHP  ['hfr', 'fwhm', 'hfr_sd', ...]   ->  -1 = fwhm
//   JS   ['hfr', 'hfr_sd', 'fwhm', ...]   ->  -1 = hfr_sd
//
// The id is now 'ig-chart-<group>-<metric key>' on both sides.
//
// Usage:  node tmp/check_chart_ids.mjs

const phpOrder = ['hfr', 'fwhm', 'hfr_sd', 'eccentricity', 'star_count', 'snr_weight', 'psf_signal'];
const jsSeries = [
    { key: 'hfr' }, { key: 'hfr_sd' }, { key: 'fwhm' },
    { key: 'eccentricity' }, { key: 'star_count' }, { key: 'snr_weight' }, { key: 'psf_signal' },
];

console.log('--- OLD scheme: positional id ---');
let oldBad = 0;
jsSeries.forEach((s, mi) => {
    const label = phpOrder[mi];
    const ok = label === s.key;
    if (!ok) oldBad++;
    console.log(`  canvas -${mi}  label=${label.padEnd(13)} data=${s.key.padEnd(13)} ` +
        (ok ? 'ok' : '<<< SWAPPED'));
});
console.log(`  -> ${oldBad} canvases whose label and data differ\n`);

console.log('--- NEW scheme: id by key ---');
let newBad = 0;
for (const key of phpOrder) {
    // PHP emits id="ig-chart-G-<key>"; JS looks for id="ig-chart-G-" + s.key
    const s = jsSeries.find((x) => x.key === key);
    if (!s) newBad++;
    console.log(`  canvas -${key.padEnd(13)} etichetta=${key.padEnd(13)} ` +
        `data=${s ? s.key : 'NOT FOUND'} ${s ? 'ok' : '<<< ERROR'}`);
}
console.log(`  -> ${newBad} mismatches`);

process.exit(oldBad > 0 && newBad === 0 ? 0 : 1);