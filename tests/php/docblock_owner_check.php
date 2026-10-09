<?php
// Check 13.21 — a docblock must describe the function that follows.
//
// In projects_functions.php the docblock of projectAddFiles() ("Manual match-or-create
// (add to project) ... Returns ['added' => int, 'skipped' => [['name' =>, 'reason' =>
// ]]]") had been detached from its function by a helper inserted in between:
// it described projectNormPart(), which normalizes a single value for the tree. The
// result was that the 240-line function that actually creates the links had no
// documentation at all, and a six-line helper was described as the matcher.
//
// The test looks for docblocks whose following function is not the one described. It serves
// to catch the case, not to certify the quality of the documentation.
//
// Usage:  docker cp tmp/docblock_owner_check.php awi-php:/tmp/
//         docker exec awi-php sh -c 'cd /tmp && php docblock_owner_check.php'

require_once '/var/www/html/includes/config.php';

$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-58s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

$file = '/var/www/html/includes/projects_functions.php';
$src = (string)file_get_contents($file);
$lines = explode("\n", $src);

echo "\n=== 13.21: every function docblock documents the function that follows ===\n";

// projectAddFiles() must have its own docblock, with the return shape it
// declares: it is the function the orphan docblock used to describe.
$addFilesAt = 0;
foreach ($lines as $i => $l) {
    if (str_starts_with(ltrim($l), 'function projectAddFiles(')) {
        $addFilesAt = $i;
        break;
    }
}
check('projectAddFiles() found', $addFilesAt > 0, 'line ' . ($addFilesAt + 1));

$before = '';
// $i >= 0 and not $i -ge: "-ge" is PowerShell syntax, in PHP it becomes the constant "ge".
for ($i = $addFilesAt - 1; $i >= max(0, $addFilesAt - 16); $i--) {
    $before = $lines[$i] . "\n" . $before;
}
check('projectAddFiles() has its own docblock',
    str_contains($before, '/**') && str_contains($before, 'Manual match-or-create'),
    '');
check('  declaring the return shape',
    str_contains($before, "'added' => int") && str_contains($before, "'skipped' =>"),
    '');
check('  with no blank lines that would detach the comment from the function',
    !preg_match('/\*\/\s*\n\s*\n\s*function projectAddFiles/', $src),
    '');

// projectNormPart() must no longer be described as the matcher.
$normAt = 0;
foreach ($lines as $i => $l) {
    if (str_starts_with(ltrim($l), 'function projectNormPart(')) {
        $normAt = $i;
        break;
    }
}
$normBefore = '';
for ($i = $normAt - 1; $i >= max(0, $normAt - 16); $i--) {
    $normBefore = $lines[$i] . "\n" . $normBefore;
}
check('projectNormPart() has its own docblock',
    str_contains($normBefore, '/**'), '');
check('  describing it for what it does',
    str_contains($normBefore, 'Normalize a name-ish value')
    && !str_contains($normBefore, 'Manual match-or-create'),
    str_contains($normBefore, 'Manual match-or-create') ? 'STILL described as the matcher' : '');

// The claims of the moved docblock must be true about the code of projectAddFiles.
// This is the point: moving a wrong comment would be worth nothing.
$body = '';
$depth = 0;
$started = false;
foreach ($lines as $i => $l) {
    if ($i <= $addFilesAt) {
        continue;
    }
    $depth += substr_count($l, '{') - substr_count($l, '}');
    $started = true;
    $body .= $l . "\n";
    if ($started && $depth <= 0) {
        break;
    }
}
check('the docblock promises the removal of superseded suggestions',
    str_contains($body, 'DELETE FROM project_suggestions'), '');
check('  and the creation of the setup/panel/session chain',
    str_contains($body, 'projectCreateSetup') && str_contains($body, 'projectCreatePanel')
    && str_contains($body, 'projectFindOrCreateSession'), '');
check('  and it returns added/skipped with name and reason',
    (bool)preg_match("/return \['added' =>[^\]]*'skipped' =>/", $body)
    && substr_count($body, "'name' =>") >= 3,
    substr_count($body, "'name' =>") . ' occurrences of name');

// No other function docblock may be orphaned in this file: the check
// looks for a '/**' followed by one or two blank lines and then a function, which is
// the shape of the defect found.
echo "\n=== no other orphan docblock ===\n";
$orphans = [];
for ($i = 0; $i < count($lines); $i++) {
    if (!preg_match('/^\s*\/\*\*\s*$/', $lines[$i])) {
        continue;
    }
    $j = $i + 1;
    while ($j < count($lines) && trim($lines[$j]) !== '' && !str_starts_with(trim($lines[$j]), 'function')) {
        $j++;
    }
    // the docblock must end with */ and the line after must be the function
    $k = $j;
    if ($k < count($lines) && preg_match('/^\s*\*\/\s*$/', $lines[$k])
        && isset($lines[$k + 1]) && preg_match('/^\s*function\s+(\w+)/', $lines[$k + 1], $m2)) {
        $fn = $m2[1];
        // section headers describe more than one function: skip them if the text
        // names more than one or uses section words
        $block = implode("\n", array_slice($lines, $i, $k - $i + 1));
        $isHeader = str_contains($block, 'Shared ') || str_contains($block, 'building blocks')
            || str_contains($block, 'file header');
        if (!$isHeader) {
            $orphans[] = ($k + 2) . ':' . $fn;
        }
    }
}
check('no other docblock detached from the function it describes',
    $orphans === [],
    $orphans === [] ? '' : implode(', ', $orphans));

echo "\nRESULT: " . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'every docblock documents its function') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed FAILURES.
exit($failed ? 1 : 0);