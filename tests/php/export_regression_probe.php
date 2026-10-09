<?php
declare(strict_types=1);

// Regression probe for the export builder.
//
// This used to assert that 'entries' was byte-identical to tmp/_old_export_probe.php.
// That was the wrong invariant: the pre-fix builder had a duplicated, empty branch
//
//     } elseif ($kind === 'bias') {      // matched, body empty
//     } elseif ($kind === 'bias') {      // unreachable
//         $leaf = 'BIAS';
//
// so a bias group following a dark group inherited $leaf from the previous iteration and
// its 11 frames were written under DARK/EXPS_600_TEMPC_0/ instead of BIAS/. Valid PHP
// syntax, silent failure. Asserting equality with that builder encoded the bug and made
// the correct output look like a regression.
//
// So: sets/tiles/skipped are still compared byte-for-byte (untouched by the fixes), while
// entries are checked against the invariant the bug violated -- a frame of one kind must
// live under the folder that kind implies -- plus that no fid lands in two directories
// without being reported as a duplicate.

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/language_functions.php';
require_once __DIR__ . '/includes/language.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/projects_functions.php';
require_once __DIR__ . '/includes/projects_diagnostics.php';
require_once __DIR__ . '/includes/project_export.php';
require_once __DIR__ . '/_old_export_probe.php';
require_once __DIR__ . '/_export_fixture.php';

while (ob_get_level() > 0) {
    ob_end_clean();
}

// kind => the folder segment its frames are required to live under.
$LEAF_OF_KIND = [
    'bias' => '/BIAS/',
    'dark' => '/DARK/',
    'darkflat' => '/DARKFLAT/',
    'flat' => '/FLAT/',
    'light' => '/LIGHT',
];

$conn = connectDB();

// The probe is only worth running if the OLD builder actually breaks the leaf invariant
// (see the bottom of this file). The frozen bug needs a bias group, and groupCalibrations()
// emits darks before bias, so a node holding both makes the stale $leaf observable. This
// archive has neither DARK nor BIAS, so the pair is created here, inside the transaction.
$conn->beginTransaction();
try {
    $fixtureProject = (int)$conn->query(
        'SELECT project_id FROM project_setups GROUP BY project_id ORDER BY COUNT(*) DESC, project_id LIMIT 1'
    )->fetchColumn();
    $fixtureMade = [];
    if ($fixtureProject > 0) {
        $fixtureMade = exportFixtureDarkAndBias($conn, $fixtureProject);
        if ($fixtureMade !== []) {
            echo "fixture: project $fixtureProject got a synthetic DARK ({$fixtureMade['dark']}) "
                . "and BIAS ({$fixtureMade['bias']}) at setup {$fixtureMade['setup']}\n";
        }
    }

    $rows = $conn->query("SELECT id, name FROM projects ORDER BY id")->fetchAll();
    if (!$rows) {
        // Not a silent pass: with nothing to build the map for, this probe proves nothing,
        // which is the same verdict as the old builder not violating the invariant below.
        echo "no projects in DB: this probe has nothing to check\n";
        $fail = 1;
        return;
    }

    $fail = 0;

/**
 * Checks one builder's output. $label is only used in the messages.
 * @return array<string,int> failure counts by reason
 */
$checkEntries = function (array $map, string $label) use ($LEAF_OF_KIND, $conn): array {
    $bad = ['leaf' => 0, 'dup_unreported' => 0];
    $dirByFid = [];
    foreach ($map['entries'] as $e) {
        $kind = (string)$e['kind'];
        $zip = (string)$e['zip_path'];
        if (isset($LEAF_OF_KIND[$kind])
            && !str_contains($zip, $LEAF_OF_KIND[$kind])) {
            echo "$label: {$e['fid']} kind=$kind outside {$LEAF_OF_KIND[$kind]}: $zip\n";
            $bad['leaf']++;
        }
        $fid = (int)$e['fid'];
        $dir = dirname($zip);
        if (isset($dirByFid[$fid]) && $dirByFid[$fid] !== $dir) {
            $reported = array_column($map['duplicated_files'] ?? [], 'fid');
            if (!in_array($fid, array_map('intval', $reported), true)) {
                echo "$label: fid $fid emitted in {$dirByFid[$fid]} and $dir, not reported as duplicate\n";
                $bad['dup_unreported']++;
            }
        }
        $dirByFid[$fid] = $dir;
    }
    return $bad;
};

foreach ($rows as $r) {
    $id = (int)$r['id'];
    try {
        $new = buildProjectExportMap($conn, $id);
    } catch (Throwable $e) {
        echo "project $id ({$r['name']}): NEW THREW " . $e->getMessage() . "\n";
        $fail++;
        continue;
    }

    // Invariants on the shipped builder.
    foreach ($checkEntries($new, "project $id ({$r['name']}) NEW") as $n) {
        $fail += $n;
    }

    // sets/tiles/skipped must be unchanged by the fixes; entries must not be compared
    // byte-for-byte against the buggy builder (see header).
    try {
        $old = buildProjectExportMapOld($conn, $id);
    } catch (Throwable $e) {
        echo "project $id ({$r['name']}): OLD THREW " . $e->getMessage() . "\n";
        $fail++;
        continue;
    }
    foreach (['sets', 'tiles', 'skipped'] as $k) {
        if (json_encode($new[$k]) !== json_encode($old[$k])) {
            echo "project $id ({$r['name']}): DIFF in '$k'\n";
            $fail++;
        }
    }

    $dups = $new['duplicated_files'] ?? null;
    if (!is_array($dups)) {
        echo "project $id: duplicated_files missing\n";
        $fail++;
        continue;
    }
    foreach ($dups as $d) {
        if (count($d['paths']) < 2) {
            echo "project $id: dup record for fid {$d['fid']} has " . count($d['paths']) . " path(s)\n";
            $fail++;
        }
        if (count(array_unique($d['paths'])) !== count($d['paths'])) {
            echo "project $id: dup record for fid {$d['fid']} repeats a path\n";
            $fail++;
        }
    }
    $entryPaths = array_column($new['entries'], 'zip_path');
    foreach ($dups as $d) {
        foreach ($d['paths'] as $p) {
            if (!in_array($p, $entryPaths, true)) {
                echo "project $id: dup path '$p' missing from entries\n";
                $fail++;
            }
        }
    }

    echo "project $id ({$r['name']}): " . count($new['entries']) . " entries, "
        . count($dups) . " duplicated, " . count($new['skipped']) . " skipped\n";
}

// The old builder must still fail the leaf invariant, or this probe proves nothing.
$oldBad = [];
foreach ($rows as $r) {
    try {
        $oldBad += $checkEntries(buildProjectExportMapOld($conn, (int)$r['id']), "OLD " . $r['name']);
    } catch (Throwable $e) {
        // A project the old builder cannot build is not evidence either way.
    }
}
$oldLeaf = array_sum($oldBad);
if ($oldLeaf === 0) {
    echo "NOTA: il builder vecchio NON viola piu' l'invariante: il confronto pre-fix non dimostra nulla\n";
    $fail++;
} else {
    echo "pre-fix: the old builder violates the invariant on $oldLeaf entries "
        . "(empty bias branch bug)\n";
}
} finally {
    $conn->rollBack();
    echo "\nrolled back\n";
}
echo $fail === 0 ? "REGRESSION OK\n" : "FAILURES: $fail\n";
// The exit code is what run.sh records. Without it the script falls off the end and
// returns 0 even when it printed FAILURES.
exit($fail === 0 ? 0 : 1);