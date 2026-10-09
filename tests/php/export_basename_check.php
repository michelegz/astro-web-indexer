<?php
// Check §11 — the export basenames must survive an unzip on
// Windows, which is where this export gets consumed (WBPP flow).
//
// Before the fix the basenames passed through verbatim and the collision map was
// case-sensitive, so:
//   - a name with ":" or "*" failed or got rewritten at extraction time;
//   - "light.fits " lost its trailing space and became "light.fits";
//   - CON/NUL were not extractable;
//   - "Light.fits" and "light.fits" collided on a case-insensitive filesystem and
//     the second overwrote the first: silent data loss.
//
// Usage:  docker cp tmp/export_basename_check.php awi-php:/var/www/html/
//         docker exec awi-php php /var/www/html/export_basename_check.php
//
// It does not touch the DB: it works on the function and on buildProjectExportMap with a
// fake tree.

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/language_functions.php';
require_once '/var/www/html/includes/language.php';
require_once '/var/www/html/includes/projects_functions.php';
require_once '/var/www/html/includes/project_export.php';

$failed = [];
function check(string $label, bool $cond, string $detail): void
{
    printf("  %-40s %-34s %s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

/** Characters Windows does not accept in a filename. */
function windowsSafe(string $name): bool
{
    if (preg_match('/[\x00-\x1F<>:"|?*]/', $name)) {
        return false;
    }
    // Windows strips trailing spaces and dots
    if (preg_match('/[ .]$/', $name)) {
        return false;
    }
    // No path separator may appear in the basename
    if (str_contains($name, '/') || str_contains($name, '\\')) {
        return false;
    }
    $stem = str_contains($name, '.') ? substr($name, 0, strrpos($name, '.')) : $name;
    if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i', $stem) === 1) {
        return false;
    }
    return true;
}

echo "=== dangerous names become extractable ===\n";
$cases = [
    'Q99:Ha.fits',
    'light*2024.fits',
    'a?b.fits',
    "quote\".fits",
    'pipe|.fits',
    'lt<gt>.fits',
    "ctrl\x01char.fits",
    'light.fits ',      // trailing space
    'light.',           // trailing dot
    'CON.fits',
    'nul.fits',
    'COM1.fits',
    'LPT9.fits',
    'a/b.fits',
    'a\\b.fits',
    '.hidden.fits',
];
foreach ($cases as $raw) {
    $safe = exportSafeBasename($raw, 1);
    $vis = str_replace(["\x01"], ['^'], $raw);
    check('sanitized', windowsSafe($safe), sprintf('%s -> %s', $vis, $safe));
}

echo "\n=== readable names stay readable ===\n";
$keep = [
    '2023-05-17_01-23-45_R_300.00s_0042.fits',
    'Q99 Ha (2024).fits',
    'light_flat_ha_300s.fits',
    'XYZ1234_L.fits',
];
foreach ($keep as $raw) {
    check('preserved', exportSafeBasename($raw, 1) === $raw, $raw);
}

echo "\n=== empty names or punctuation only ===\n";
check('empty -> fallback', exportSafeBasename('', 42) === 'file_42', exportSafeBasename('', 42));
check('dots only -> fallback', exportSafeBasename('...', 7) === 'file_7', exportSafeBasename('...', 7));
check('odd extension', exportSafeBasename('img.a b', 1) === 'img', exportSafeBasename('img.a b', 1));

echo "\n=== case-insensitive collisions in the same folder ===\n";
// It builds the 'used' map as $addFile does and checks that two names which
// collide on a case-insensitive filesystem do NOT keep the same name.
$dir = 'SETUP_S1/PANEL_P1/LIGHT';
$used = [];
$names = [];
foreach (['Light.fits', 'light.fits', 'LIGHT.FITS', 'Light_1.fits'] as $base) {
    $name = $base;
    $i = 1;
    while (isset($used[mb_strtolower($dir . "\0" . $name)])) {
        $dot = strrpos($base, '.');
        $name = $dot === false ? $base . '_' . $i : substr($base, 0, $dot) . '_' . $i . substr($base, $dot);
        $i++;
    }
    $used[mb_strtolower($dir . "\0" . $name)] = true;
    $names[] = $name;
}
echo '  emitted names: ' . implode(', ', $names) . "\n";
$folded = array_map('mb_strtolower', $names);
check('all distinct (case-folded)', count($folded) === count(array_unique($folded)),
      implode(', ', $names));

echo "\n=== different folders: the same name stays identical ===\n";
$used2 = [];
$emitted = [];
foreach ([['SETUP_S1/A/LIGHT', 'light.fits'], ['SETUP_S2/B/LIGHT', 'light.fits']] as [$d, $base]) {
    $name = $base;
    $i = 1;
    while (isset($used2[mb_strtolower($d . "\0" . $name)])) {
        $dot = strrpos($base, '.');
        $name = $dot === false ? $base . '_' . $i : substr($base, 0, $dot) . '_' . $i . substr($base, $dot);
        $i++;
    }
    $used2[mb_strtolower($d . "\0" . $name)] = true;
    $emitted[] = $d . '/' . $name;
}
echo '  emitted: ' . implode(' | ', $emitted) . "\n";
check('no cross-folder renaming',
    $emitted[0] === 'SETUP_S1/A/LIGHT/light.fits' && $emitted[1] === 'SETUP_S2/B/LIGHT/light.fits',
    'each setup keeps its own copy');

echo "\n=== does the file really survive? (the data loss case) ===\n";
// It simulates the extraction on a case-insensitive filesystem: two paths differing
// only in case land on the same entry and one overwrites the other.
$extracted = [];
foreach ($names as $n) {
    $key = mb_strtolower($n);
    $extracted[$key] = ($extracted[$key] ?? 0) + 1;
}
$lost = array_filter($extracted, fn($c) => $c > 1);
check('no file lost in the extraction', empty($lost),
      empty($lost) ? count($extracted) . ' distinct entries'
                   : count($lost) . ' overwritten');

echo "\nRESULT: " . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'all checks passed') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed FAILURES.
exit($failed ? 1 : 0);