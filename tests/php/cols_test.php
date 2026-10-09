<?php
declare(strict_types=1);

// Column registry logic: toggleable set, cookie-driven hidden columns, group structure.
//
// This had two stale pieces. It required '/tmp/cols.php', a copy of includes/columns.php
// that no longer exists, so the script died before running anything; it now loads the real
// file. And it asserted absolute counts that had aged out: 68 toggleable keys and 9 groups.
// There are now 76 keys and 10 groups, so it failed while being correct about the code.
//
// Absolute counts are the wrong invariant anyway: they break every time a column is added
// and never catch a real defect on their own. What must hold is that the toggleable set
// and the groups describe each other exactly, so what is checked instead is the
// correspondence, plus the behaviours that matter (base columns cannot be hidden, 'name'
// can never be hidden, junk in the cookie is dropped).

$candidates = [
    __DIR__ . '/includes/columns.php',
    '/var/www/html/includes/columns.php',
];
$columnsFile = null;
foreach ($candidates as $c) {
    if (is_file($c)) {
        $columnsFile = $c;
        break;
    }
}
if ($columnsFile === null) {
    fwrite(STDERR, "columns.php not found in: " . implode(', ', $candidates) . "\n");
    exit(1);
}
require $columnsFile;

$failed = [];
function check($label, $cond) {
    global $failed;
    echo ($cond ? 'OK   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$cond) {
        $failed[] = $label;
    }
}

$t = getToggleableKeys();
$g = getColumnGroups();
echo 'toggleable: ' . count($t) . ', groups: ' . count($g) . PHP_EOL;

// Flatten the groups: every column lives in exactly one.
$inGroups = [];
foreach ($g as $grp) {
    foreach (array_keys($grp['columns']) as $c) {
        $inGroups[$c] = ($inGroups[$c] ?? 0) + 1;
    }
}
$duplicatedInGroups = array_keys(array_filter($inGroups, static fn($n) => $n > 1));

check('no column is in two groups', $duplicatedInGroups === [], implode(',', $duplicatedInGroups));
check(
    'toggleable and groups describe each other',
    array_diff($t, array_keys($inGroups)) === [] && array_diff(array_keys($inGroups), $t) === [],
    'toggleable without group: ' . implode(',', array_diff($t, array_keys($inGroups)))
    . '; in groups but not toggleable: ' . implode(',', array_diff(array_keys($inGroups), $t))
);
check('base is the first group', array_key_first($g) === 'base', (string) array_key_first($g));
check('name is never toggleable', !in_array('name', $t, true));
check(
    'the base group does not contain name',
    !isset($g['base']['columns']['name']),
    implode(',', array_keys($g['base']['columns']))
);

$baseCount = count($g['base']['columns']);
check('the base group exists and is populated', $baseCount > 0, $baseCount . ' columns');
check('getBaseColumns() holds one column more than the base group (name)',
    count(getBaseColumns()) === $baseCount + 1,
    count(getBaseColumns()) . ' vs ' . ($baseCount + 1));

// Defaults: everything toggleable except the base group starts hidden.
$_GET = [];
$_COOKIE = [];
$d = resolveHiddenColumns($t);
check(
    'by default everything except the base columns is hidden',
    count($d) === count($t) - $baseCount,
    count($d) . ' hidden, expected ' . (count($t) - $baseCount)
);
check('no base column is hidden', array_intersect($d, array_keys($g['base']['columns'])) === []);

$_COOKIE = ['hiddenCols' => 'fwhm,xxx,preview'];
check('the cookie intersects and drops the junk', resolveHiddenColumns($t) === ['fwhm', 'preview'],
    implode(',', resolveHiddenColumns($t)));

$_COOKIE = ['hiddenCols' => 'name,preview'];
check('name cannot be hidden', resolveHiddenColumns($t) === ['preview'],
    implode(',', resolveHiddenColumns($t)));

$_COOKIE = ['hiddenCols' => ''];
check('empty cookie shows everything', resolveHiddenColumns($t) === []);

$_GET = ['show_advanced' => 1];
check('the legacy parameter shows everything', resolveHiddenColumns($t) === []);

$GLOBALS['hiddenCols'] = ['gain', 'preview'];
check('showCol hides a hidden column', showCol('gain') === false && showCol('preview') === false);
check('showCol shows the others', showCol('hfr') === true);
check('showCol always leaves name visible', showCol('name') === true);

echo $failed === []
    ? 'ALL COLUMN LOGIC TESTS PASSED' . PHP_EOL
    : 'FAILURES: ' . count($failed) . ' -> ' . implode(' | ', $failed) . PHP_EOL;
exit($failed === [] ? 0 : 1);