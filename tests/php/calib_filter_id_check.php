<?php
// Check find_calibration_files.php: the column name arrives from the JSON body.
//
// $filters = $params['filters'] and then $id = $filter['id'] ends up inside
// $escapedId = "`{$id}`" which is interpolated into the query as a column name:
//   $sqlWhere[] = "{$escapedId} = :{$id}";
//
// The only thing stopping it is the guard right above, isset($refFile[$id]): if the
// key does not exist in the reference file's row the filter is skipped. So
// an arbitrary id is discarded, because real column names do not contain
// backticks.
//
// The point of this test is that the security depends on an incidental property of
// a check meant for something else: it holds as long as the guard stays there. The fix
// is an explicit whitelist.
//
// Usage:  docker cp tmp/calib_filter_id_check.php awi-php:/tmp/
//         docker exec awi-php php /tmp/calib_filter_id_check.php

$failed = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    printf("  %-56s %s%s\n", $label, $detail, $cond ? 'OK' : '<<< FAILED');
    if (!$cond) {
        $GLOBALS['failed'][] = $label;
    }
}

$src = (string)file_get_contents('/var/www/html/api/find_calibration_files.php');

echo "\n=== is the column name validated against the whitelist? ===\n";

// The filter ids are the keys of the shared catalog, and the whitelist must cover
// both the type and the membership.
//
// NOTE: this check must no longer be a regex on the source. It used to be, and it passed
// while find_calibration_files.php died with a TypeError in array_keys() on every
// search with at least one filter, i.e. the normal path of the modal: the shape of the
// code was right, $allFilters simply did not exist in that context. A regex
// cannot see an undefined variable. The behavior is verified by
// sff_filter_live_check.php, which really sends the modal's payload.
$hasGuard = str_contains($src, "require_once __DIR__ . '/../includes/sff_filters.php';")
    && (bool)preg_match('/\$allFilters\s*=\s*sff_all_filters\(\)\s*;/', $src);
check("the filter is validated against the shared catalog", $hasGuard,
    $hasGuard ? '' : 'NO CATALOG CALLED: it reaches the column name');
check('  and the catalog is not defined in place',
    (bool)preg_match('/\$allFilters\s*=\s*\[/', $src) === false,
    'inline duplicate: two copies diverge');

// The column name must not be able to contain a backtick, which would close the
// quoted identifier.
$idRead = (bool)preg_match('/\$id\s*=\s*\$filter\[\s*[\'"]id[\'"]\s*\]/', $src);
check('$id letto da $filter[\'id\']', $idRead, '');
check('and used as a column name quoted with backticks',
    str_contains($src, '$escapedId = "`{$id}`"'), '');

// The rejection status must be a clean 400, not a warning in the body.
check('an invalid id answers 400',
    (bool)preg_match('/Invalid filter|invalid filter/', $src)
    || (bool)preg_match('/http_response_code\(400\)/', $src), '');

echo "\n=== the happy path must stay intact ===\n";
// The filters the frontend sends are inside $allFilters: after the fix they must
// keep passing. I verify that the whitelist applies only to unknown ids,
// i.e. that the file still contains the loop over the validated filters.
check('the loop over the filters is still there', str_contains($src, 'foreach ($filters as $filter)'), '');
check('the isset($refFile[$id]) guard stays',
    str_contains($src, "isset(\$refFile[\$id])"), '');
check('search_type is still validated',
    str_contains($src, "isset(\$imgTypes[\$searchType])"), '');

echo "\nRESULT: " . ($failed ? 'FAILED: ' . implode(', ', $failed)
    : 'the column name is validated') . "\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed FAILURES.
exit($failed ? 1 : 0);