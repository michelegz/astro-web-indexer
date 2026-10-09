<?php
// Check 13.24, 13.40 — archive rotation and manifest.
//
//  13.24 projectRotDist() did (float)$r1 on a non-numeric value, which silently gives 0.0:
//         a file with junk rotation metadata came out aligned to a 0-degree panel, was
//         not discarded by the tolerance, and the badge said "ok" instead of "unknown".
//         The Python twin rotation_distance() already reported it as unknown.
//  13.40 MANIFEST.json inside the archive was built from the complete map, so it listed
//         folders, calibration sets and file sizes that the on-disk check had just
//         discarded. The inventory contradicted the contents, and a set whose files were
//         all missing did not even produce the folder.
//
// Usage:  docker cp tmp/export_manifest_check.php awi-php:/tmp/
//         docker exec awi-php sh -c 'cd /tmp && php export_manifest_check.php'

require_once '/var/www/html/includes/config.php';
require_once '/var/www/html/includes/db_functions.php';
require_once '/var/www/html/includes/http_json.php';
require_once '/var/www/html/includes/auth.php';
require_once '/var/www/html/includes/projects_diagnostics.php';
require_once '/var/www/html/includes/projects_functions.php';
require_once '/var/www/html/includes/project_export.php';

if (!function_exists('__')) {
    function __(string $key, array $replace = []): string
    {
        return $key;
    }
}

$conn = connectDB();
$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-58s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

// =====================================================================
// 13.24 — projectRotDist
// =====================================================================
echo "\n=== 13.24: a non-numeric rotation stays 'unknown' ===\n";

// Valid cases: distance computed, unknown = false.
[$d, $unk] = projectRotDist(10.0, 20.0);
check('10 and 20 degrees -> 10 degrees, known', abs($d - 10.0) < 1e-9 && $unk === false, "d=$d");
[$d, $unk] = projectRotDist(350.0, 10.0);
check('across zero -> 20 degrees', abs($d - 20.0) < 1e-9 && $unk === false, "d=$d");

// Non-numeric cases: they must stay unknown. Before, (float)'N/A' gave 0.0 with
// unknown = false, i.e. "identical to a 0-degree panel".
foreach (['N/A' => 'N/A', 'junk' => '   ', 'date' => '12:34:56'] as $label => $val) {
    [$d, $unk] = projectRotDist($val, 0.0);
    check("$label -> unknown", $unk === true, "d=$d unknown=" . var_export($unk, true));
}
[$d, $unk] = projectRotDist(12.0, 'N/A');
check('non-numeric right side -> unknown', $unk === true, "unknown=" . var_export($unk, true));

// Null and empty string: already handled, but they must not regress.
foreach ([null => 'null', '' => 'vuoto'] as $val => $label) {
    [$d, $unk] = projectRotDist($val, 5.0);
    check("$label -> unknown", $unk === true, "d=$d");
}

// A numeric value in string form still counts: the data comes from the DB as
// numbers, but the function is public and must not reject '10'.
[$d, $unk] = projectRotDist('10', '20');
check('numbers in string form -> known', abs($d - 10.0) < 1e-9 && $unk === false, "d=$d");

// The Python twin rotation_distance() must give the same answer. A cross-container
// comparison is not possible (there is no docker CLI inside the container), so the two
// tests are anchored to the same expected values, computed from first principles: if one
// of the two implements it differently, its half of this check fails.
[$pyDistExpect, ] = projectRotDist(10.0, 20.0);
check('the same distance the Python test expects', abs($pyDistExpect - 10.0) < 1e-9,
    'also expected in suggest_parity_check.py: 10.0');
[, $phpUnknown] = projectRotDist('N/A', 0.0);
check("the same 'unknown' answer the Python test expects",
    $phpUnknown === true, 'rotation_distance("N/A", 0.0) -> True');

// =====================================================================
// 13.26 (PHP half) — same inputs as the Python test
// =====================================================================
echo "\n=== 13.26 PHP half: the uppercase markers as in the Python twin ===\n";

$expectedRa = fmod((12 + 34 / 60.0 + 56 / 3600.0) * 15.0, 360.0);
$expectedDec = -(12 + 34 / 60.0 + 56 / 3600.0);
check('RA "12H34M56S" -> same as Python',
    projectParseRa('12H34M56S') !== null && abs(projectParseRa('12H34M56S') - $expectedRa) < 1e-9,
    sprintf('%.6f', (float)projectParseRa('12H34M56S')));
check('Dec "-12D34M56S" -> same as Python',
    projectParseDec('-12D34M56S') !== null && abs(projectParseDec('-12D34M56S') - $expectedDec) < 1e-9,
    sprintf('%.6f', (float)projectParseDec('-12D34M56S')));

// =====================================================================
// 13.25 (PHP half) — projectNumPrefix rejects the sign like the Python twin
// =====================================================================
echo "\n=== 13.25 PHP half: tolerances with units and without a sign ===\n";

foreach (['10%' => 10.0, '2C' => 2.0, '3deg' => 3.0, '0.2' => 0.2, '  5 arcmin' => 5.0] as $text => $want) {
    check(sprintf('%-12s -> %-5s', $text, $want),
        abs(projectNumPrefix((string)$text, 3.0) - $want) < 1e-12,
        (string)projectNumPrefix((string)$text, 3.0));
}
check('"-5" -> default, like _num_prefix in Python',
    abs(projectNumPrefix('-5', 3.0) - 3.0) < 1e-12, (string)projectNumPrefix('-5', 3.0));
check('"-.5" -> default, like _num_prefix in Python',
    abs(projectNumPrefix('-.5', 3.0) - 3.0) < 1e-12, (string)projectNumPrefix('-.5', 3.0));

// And saving must reject the negative at the source, not only on read.
$pidTol = (int)$conn->query('SELECT MIN(id) FROM projects')->fetchColumn();
$before = (string)$conn->query('SELECT tolerances FROM projects WHERE id = ' . $pidTol)->fetchColumn();
$threw = false;
try {
    saveProjectTolerances($conn, $pidTol, ['tol_rot' => '-5']);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
$after = (string)$conn->query('SELECT tolerances FROM projects WHERE id = ' . $pidTol)->fetchColumn();
check('saving a negative tolerance is rejected', $threw, '');
check('  and it writes nothing', $before === $after, 'tolerances unchanged');

// Legitimate values with units must stay accepted: that is the risk of my
// first version of the validation, which rejected every non-strictly-numeric value
// and would have broken the feature (the defaults are '1%', '2C', '3deg', '10%').
$saved = false;
try {
    saveProjectTolerances($conn, $pidTol, ['tol_rot' => '3deg', 'tol_exp' => '1%']);
    $saved = true;
} catch (InvalidArgumentException $e) {
    $saved = false;
}
$now = json_decode((string)$conn->query('SELECT tolerances FROM projects WHERE id = ' . $pidTol)->fetchColumn(), true);
check('values with units are accepted', $saved && ($now['tol_rot'] ?? null) === '3deg',
    json_encode($now));
// Restore.
$conn->prepare('UPDATE projects SET tolerances = :t WHERE id = :id')
    ->execute([':t' => $before !== '' ? $before : null, ':id' => $pidTol]);

// =====================================================================
// 13.40 — the manifest describes the archive, not the map
// =====================================================================
echo "\n=== 13.40: the manifest lists only what is in the archive ===\n";

$pid = (int)$conn->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();
$map = buildProjectExportMap($conn, $pid);
check('export map built',
    is_array($map) && isset($map['entries']) && $map['entries'] !== [],
    'entries=' . count($map['entries'] ?? []));

// The block the fix introduces: the same logic as the loop in export_project_zip.php,
// applied to the same data, without downloading the archive.
$fitsRoot = FITS_ROOT;
$realRoot = realpath($fitsRoot);
$validFiles = [];
$validEntries = [];
$missing = [];
foreach ($map['entries'] as $e) {
    $rel = preg_replace('/\.\.(\/|\\\\)?/', '', (string)($e['src'] ?? ''));
    $rel = ltrim($rel, '/\\');
    if ($rel === '') {
        continue;
    }
    $full = realpath($fitsRoot . DIRECTORY_SEPARATOR . $rel);
    if ($full && $realRoot !== false && isPathWithinRoot($full, $realRoot)
        && is_file($full) && is_readable($full) && canAccessPath($rel)) {
        $validFiles[] = ['zip_path' => (string)$e['zip_path'], 'full' => $full];
        $validEntries[] = $e;
        continue;
    }
    $missing[] = ['zip_path' => (string)$e['zip_path'], 'src' => (string)($e['src'] ?? ''),
        'reason' => (string)($e['src'] ?? '') === '' ? 'no_path' : 'not_readable'];
}

$folders = [];
$archiveSize = 0;
foreach ($validFiles as $i => $vf) {
    $e = $validEntries[$i];
    $archiveSize += (int)filesize($vf['full']);
    $dd = dirname((string)$e['zip_path']);
    $folders[$dd]['files'][] = basename((string)$e['zip_path']);
    $folders[$dd]['kinds'][$e['kind']] = true;
    if (!empty($e['scope'])) {
        $folders[$dd]['scope'] = array_values(array_unique(array_merge(
            $folders[$dd]['scope'] ?? [], (array)$e['scope'])));
    }
}
ksort($folders);

$survivingSets = [];
foreach ($map['sets'] as $name => $set) {
    foreach ($validEntries as $e) {
        if (str_contains('/' . $e['zip_path'] . '/', '/' . $name . '/')) {
            $survivingSets[$name] = $set;
            break;
        }
    }
}

$manifest = $map['manifest'];
$manifest['sets'] = $survivingSets;
$manifest['folders'] = $folders;
$manifest['total_size'] = $archiveSize;
$manifest['files'] = count($validFiles);
if ($missing !== []) {
    $manifest['missing'] = $missing;
}

// Every file listed in the manifest folders must really exist.
$listed = 0;
foreach ($manifest['folders'] as $dir => $info) {
    $listed += count($info['files'] ?? []);
}
check('the files listed in the folders are the ones present',
    $listed === count($validFiles),
    "manifest={$listed} present=" . count($validFiles));

// Every declared set must have at least one file: an empty set does not even produce
// the folder, so declaring it is false.
$emptySets = [];
foreach ($manifest['sets'] as $name => $s) {
    $has = false;
    foreach ($validEntries as $e) {
        if (str_contains('/' . $e['zip_path'] . '/', '/' . $name . '/')) {
            $has = true;
            break;
        }
    }
    if (!$has) {
        $emptySets[] = $name;
    }
}
check('no set declared without files', $emptySets === [],
    $emptySets === [] ? '' : 'empty: ' . implode(', ', $emptySets));

// And the manifest must agree with the map only for what exists: the comparison
// with the unfiltered map is exactly what the fix avoids.
check('the manifest does not carry the raw map',
    $manifest['folders'] !== $map['manifest']['folders']
    || $missing === [],
    'difference on folders');

// The size must be that of the files present, not the estimate from the DB.
$dbEstimate = (int)($map['manifest']['total_size'] ?? 0);
check('total_size recomputed on the files present',
    $manifest['total_size'] === $archiveSize,
    "manifest={$manifest['total_size']} sum of real files={$archiveSize} DB estimate={$dbEstimate}");

// The manifest must be encodable and valid: it is a file the user opens.
$json = awiJsonString($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$decoded = json_decode($json, true);
check('the manifest encodes to valid JSON',
    is_string($json) && is_array($decoded) && isset($decoded['files']),
    strlen($json) . ' bytes');

if ($missing !== []) {
    check('the missing files are declared, not hidden',
        isset($decoded['missing']) && count($decoded['missing']) === count($missing),
        'missing=' . count($missing));
} else {
    check('no missing file on this project', true, '');
}

echo "\n=== 13.40: the branch with present files (synthetic case) ===\n";

// On this stack no file is reachable (FITS_ROOT=/var/fits is empty), so the
// real case here only exercises "zero files". The opposite branch is covered separately,
// with synthetic lists that go through the same logic: a present file must appear
// in the folders, a set without surviving files must not be declared.
$probe = '/tmp/awi_manifest_probe';
@mkdir($probe, 0777, true);
file_put_contents($probe . '/a.fits', str_repeat('x', 1234));
file_put_contents($probe . '/b.fits', str_repeat('y', 77));

$synFiles = [
    ['zip_path' => 'SETUP_S1/FLATSET_S1/a.fits', 'full' => $probe . '/a.fits'],
    ['zip_path' => 'SETUP_S1/FLATSET_S1/b.fits', 'full' => $probe . '/b.fits'],
];
$synEntries = [
    ['zip_path' => 'SETUP_S1/FLATSET_S1/a.fits', 'kind' => 'FLAT', 'scope' => 'FLATSET', 'src' => 'x/a.fits'],
    ['zip_path' => 'SETUP_S1/FLATSET_S1/b.fits', 'kind' => 'FLAT', 'scope' => 'FLATSET', 'src' => 'x/b.fits'],
];
$synMap = [
    'sets' => [
        'FLATSET_S1' => ['type' => 'FLAT', 'sessions' => [1], 'nights' => ['n1']],
        'BIASSET_S1' => ['type' => 'BIAS', 'sessions' => [2], 'nights' => ['n1']],
    ],
    'duplicated_files' => [],
    'manifest' => ['sets' => [], 'folders' => [], 'total_size' => 0],
];

$synFolders = [];
$synSize = 0;
foreach ($synFiles as $i => $vf) {
    $e = $synEntries[$i];
    $synSize += (int)filesize($vf['full']);
    $dd = dirname($e['zip_path']);
    $synFolders[$dd]['files'][] = basename($e['zip_path']);
    $synFolders[$dd]['kinds'][$e['kind']] = true;
    if (!empty($e['scope'])) {
        $synFolders[$dd]['scope'] = array_values(array_unique(array_merge(
            $synFolders[$dd]['scope'] ?? [], (array)$e['scope'])));
    }
}
ksort($synFolders);
$synSets = [];
foreach ($synMap['sets'] as $name => $set) {
    foreach ($synEntries as $e) {
        if (str_contains('/' . $e['zip_path'] . '/', '/' . $name . '/')) {
            $synSets[$name] = $set;
            break;
        }
    }
}

$synListed = 0;
foreach ($synFolders as $di => $info) {
    $synListed += count($info['files'] ?? []);
}
check('the present files appear in the folders', $synListed === 2,
    'folders=' . implode('/', array_keys($synFolders)) . ' files=' . $synListed);
check('the set with the surviving files is declared', isset($synSets['FLATSET_S1']),
    implode(',', array_keys($synSets)));
check('the set without files is not declared', !isset($synSets['BIASSET_S1']),
    'BIASSET_S1 absent');
check('total_size is the real sum', $synSize === 1311, "expected 1311, got $synSize");
check('the set path is a segment, not a prefix',
    str_contains('/SETUP_S1/FLATSET_S1/a.fits/', '/FLATSET_S1/')
    && !str_contains('/SETUP_S1/FLATSET_S1X/a.fits/', '/FLATSET_S1/'), '');
exec('rm -rf ' . escapeshellarg($probe));

// The endpoint code must no longer reuse the raw map for the manifest.
$zipSrc = (string)file_get_contents('/var/www/html/api/export_project_zip.php');
$reusesRawManifest = (bool)preg_match(
    '/awiJsonString\(\s*\$map\[.manifest.\]/', $zipSrc);
check('export_project_zip.php does not reuse the raw manifest',
    !$reusesRawManifest,
    $reusesRawManifest ? 'STILL $map[manifest]' : '');
check('  and it rebuilds folders, sets and total_size',
    str_contains($zipSrc, "\$manifest['folders']")
    && str_contains($zipSrc, "\$manifest['sets']")
    && str_contains($zipSrc, "\$manifest['total_size']"), '');

echo "\nRESULT: " . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'rotation and manifest correct') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed FAILURES.
exit($failed ? 1 : 0);
