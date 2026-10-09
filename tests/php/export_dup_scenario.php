<?php
declare(strict_types=1);

// Fix 1 scenario test: a calibration linked to TWO setups of the same project.
// The old builder dropped the second copy, the new one must emit both and
// report one duplicated_files record holding the two paths.
// Everything runs inside a transaction that is always rolled back.
//
// The project used to be hardcoded to 36, which no longer exists, so the script printed
// "no setup in project 36" and exited 1 without asserting anything: the one scenario test
// for the duplicate export had stopped testing. Project and frame are now selected from
// the database for the same reason.

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/language_functions.php';
require_once __DIR__ . '/includes/language.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/projects_functions.php';
require_once __DIR__ . '/includes/projects_diagnostics.php';
require_once __DIR__ . '/includes/project_export.php';
require_once __DIR__ . '/_old_export_probe.php';

while (ob_get_level() > 0) {
    ob_end_clean();
}

$conn = connectDB();

// A project that still has setups, and a BIAS frame to share between them. Both were
// hardcoded and both had aged out.
$PROJECT = (int)$conn->query(
    'SELECT project_id FROM project_setups GROUP BY project_id ORDER BY COUNT(*) DESC, project_id LIMIT 1'
)->fetchColumn();
$SHARED_FID = (int)$conn->query(
    "SELECT id FROM files WHERE imgtype LIKE 'BIAS%' AND deleted_at IS NULL ORDER BY id LIMIT 1"
)->fetchColumn();
if ($PROJECT <= 0 || $SHARED_FID <= 0) {
    echo "  FAIL nessun progetto con setup, o nessun frame BIAS\n";
    echo "FAILURES: 1\n";
    exit(1);
}
$fail = 0;
$check = function (bool $ok, string $msg) use (&$fail): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
};

$conn->beginTransaction();
try {
    $s = $conn->prepare("SELECT id, setup_no FROM project_setups WHERE project_id = :p ORDER BY setup_no LIMIT 1");
    $s->execute([':p' => $PROJECT]);
    $setupA = $s->fetch();
    if ($setupA === false) {
        echo "no setup in project $PROJECT\n";
        exit(1);
    }
    $ins = $conn->prepare(
        "INSERT INTO project_setups (project_id, fingerprint, label, setup_no) "
        . "VALUES (:p, 'PROBE-FINGERPRINT-ONLY', 'Probe setup', :no)"
    );
    // uq_project_setups_no makes setup_no unique per project, so take a free number
    // rather than "the next one after the first setup".
    $newNo = (int)$conn->query(
        "SELECT COALESCE(MAX(setup_no), 0) + 1 FROM project_setups WHERE project_id = $PROJECT"
    )->fetchColumn();
    $ins->execute([':p' => $PROJECT, 'no' => $newNo]);
    $setupB = (int)$conn->lastInsertId();

    $link = $conn->prepare(
        "INSERT INTO project_files (file_id, level, node_id, filter_name, role, is_light, enabled) "
        . "VALUES (:fid, 'setup', :node, NULL, 'master', 0, 1)"
    );
    $link->execute([':fid' => $SHARED_FID, ':node' => (int)$setupA['id']]);
    $link->execute([':fid' => $SHARED_FID, ':node' => $setupB]);
    echo "shared file $SHARED_FID linked to setups {$setupA['id']} and $setupB\n\n";

    $new = buildProjectExportMap($conn, $PROJECT);
    $old = buildProjectExportMapOld($conn, $PROJECT);

    echo "old builder: " . count($old['entries']) . " entries, "
        . count($old['duplicates_resolved']) . " dropped as duplicate\n";
    echo "new builder: " . count($new['entries']) . " entries, "
        . count($new['duplicated_files']) . " duplicated file(s)\n\n";

    $check(
        count($new['entries']) === count($old['entries']) + 1,
        'new builder emits exactly one extra entry (the recovered copy)'
    );
    $check(!isset($new['duplicates_resolved']), 'duplicates_resolved is gone from the return');
    $check(count($new['duplicated_files']) === 1, 'exactly one duplicated_files record');

    $rec = $new['duplicated_files'][0] ?? null;
    $check(is_array($rec) && (int)($rec['fid'] ?? 0) === $SHARED_FID, 'record points at the shared file');
    $check(is_array($rec) && count($rec['paths'] ?? []) === 2, 'record lists both zip paths');
    $check(
        is_array($rec) && count(array_unique($rec['paths'] ?? [])) === 2,
        'the two zip paths are distinct'
    );
    $check(
        is_array($rec) && !array_key_exists('kept_in', $rec) && !array_key_exists('duplicated_in', $rec),
        'obsolete kept_in/duplicated_in keys removed'
    );

    // The recovered copy must sit under the SECOND setup, i.e. the new builder
    // did not just duplicate inside the first folder.
    $paths = $rec['paths'] ?? [];
    $check(
        (bool) preg_grep('#^SETUP_S' . preg_quote((string)$newNo, '#') . '#', $paths)
        && (bool) preg_grep('#^SETUP_S' . preg_quote((string)$setupA['setup_no'], '#') . '#', $paths),
        'one copy under each setup folder'
    );

    // Both copies must exist as real entries.
    $entryPaths = array_column($new['entries'], 'zip_path');
    foreach ($paths as $p) {
        $check(in_array($p, $entryPaths, true), "entry present for '$p'");
    }

    // Nothing but the recovered copy may change.
    foreach (['sets', 'tiles', 'skipped'] as $k) {
        $check(json_encode($new[$k]) === json_encode($old[$k]), "'$k' unchanged vs old builder");
    }
    // The two builders can differ in WHICH folder a frame lands in. The old one had a
    // duplicated empty 'elseif ($kind === bias)' branch, so $leaf was never assigned for
    // biases, and which symptom showed up depended on the order of the groups: with a bias
    // group first $leaf was simply undefined and the path became 'SETUP_Sn//file.fits'
    // (visible as a Warning during this very run), whereas after a dark group it kept that
    // group's leaf and biases were written under 'SETUP_Sn/DARK/EXPS_600_TEMPC_0/...'.
    // export_regression_probe.php covers the second shape; here what matters is that
    // nothing is LOST, so the two sides are compared per file rather than per path.
    $keyOf = static fn(array $e): string => $e['fid'] . "\0" . basename((string)$e['zip_path']);
    $newKeys = array_map($keyOf, $new['entries']);
    $oldOnly = array_values(array_diff(array_map($keyOf, $old['entries']), $newKeys));
    $check($oldOnly === [], 'nessuna entry del builder vecchio e\' andata perduta: ' . json_encode($oldOnly));

    // The duplicate must not leak into skipped, and the manifest must agree.
    $check(
        !in_array($SHARED_FID, array_map(fn($s) => (int)$s['fid'], array_filter(
            $new['skipped'],
            fn($s) => true
        )), true),
        'shared file not reported as skipped'
    );
    $check(
        json_encode($new['manifest']['duplicated_files']) === json_encode($new['duplicated_files']),
        'MANIFEST.duplicated_files matches the return value'
    );
    $check(
        !isset($new['manifest']['duplicates_resolved']),
        'MANIFEST no longer carries duplicates_resolved'
    );
    $check(
        count($new['manifest']['folders']) >= count($old['manifest']['folders']),
        'folders cover both setups'
    );
} finally {
    $conn->rollBack();
    echo "\nrolled back\n";
}
echo $fail === 0 ? "SCENARIO OK\n" : "FAILURES: $fail\n";
// The exit code is what run.sh records. The fixture guards above exit 1 on their own,
// but a real assertion failure reaching this point used to return 0.
exit($fail === 0 ? 0 : 1);
