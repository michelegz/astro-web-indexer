<?php
declare(strict_types=1);

// Synthetic calibration frames for the export probes.
//
// Both export probes need a BIAS frame, and export_regression_probe.php additionally needs
// the OLD builder to actually violate the leaf invariant it is meant to freeze. The frozen
// bug is a duplicated, empty `elseif ($kind === 'bias')` branch: $leaf keeps the value
// left by the previous group, and groupCalibrations() always emits darks before bias — so
// a node carrying both a DARK and a BIAS makes the old builder write the bias rows under
// DARK/. That is the shape the probe documents.
//
// This archive holds FLAT and LIGHT frames only, so without these helpers the probes
// either skip their case or refuse to run and stop testing anything.
//
// Nothing here commits: the caller owns the transaction, and these rows disappear with
// its rollback, exactly like the projects and setups the probes create themselves.

/**
 * Insert a synthetic calibration frame and return its id.
 *
 * `path` is UNIQUE in `files`, so the tag has to be unique too.
 */
function exportFixtureFrame(PDO $conn, string $kind, array $overrides = []): int
{
    $row = array_merge([
        'object' => 'FIXTURE',
        'instrume' => 'FIXTURE',
        'telescop' => 'FIXTURE',
        'cameraid' => 'FIXTURE',
        'filter' => 'R',
        'exptime' => 600.0,
        'ccd_temp' => -10.0,
        'xbinning' => 1,
        'ybinning' => 1,
        'gain' => 1.5,
        'offset' => 10,
        'xpixsz' => 3.76,
        'date_obs' => '2026-03-04 05:06:07',
        'objctra' => '12 34 56',
        'objctdec' => '+12 34 56',
        'fov_w' => 120.0,
        'fov_h' => 90.0,
        'objctrot' => 0.0,
        'rotator_angle' => 0.0,
    ], $overrides);

    $ins = $conn->prepare(
        'INSERT INTO files (path, name, imgtype, `object`, instrume, telescop, cameraid, '
        . '`filter`, exptime, ccd_temp, xbinning, ybinning, gain, `offset`, xpixsz, '
        . 'date_obs, objctra, objctdec, fov_w, fov_h, objctrot, rotator_angle, '
        . 'file_hash, mtime, file_size) '
        . 'VALUES (:path, :name, :imgtype, :object, :instrume, :telescop, :cameraid, '
        . ':filter, :exptime, :ccd_temp, :xb, :yb, :gain, :offset, :xpixsz, '
        . ':date_obs, :objctra, :objctdec, :fov_w, :fov_h, :objctrot, :rot, '
        . ':hash, :mtime, :size)'
    );
    $tag = 'exportfix_' . bin2hex(random_bytes(4));
    $ins->execute([
        ':path' => $tag . '/' . $kind . '/frame.fits',
        ':name' => 'frame.fits',
        ':imgtype' => $kind,
        ':object' => $row['object'],
        ':instrume' => $row['instrume'],
        ':telescop' => $row['telescop'],
        ':cameraid' => $row['cameraid'],
        ':filter' => $row['filter'],
        ':exptime' => $row['exptime'],
        ':ccd_temp' => $row['ccd_temp'],
        ':xb' => $row['xbinning'],
        ':yb' => $row['ybinning'],
        ':gain' => $row['gain'],
        ':offset' => $row['offset'],
        ':xpixsz' => $row['xpixsz'],
        ':date_obs' => $row['date_obs'],
        ':objctra' => $row['objctra'],
        ':objctdec' => $row['objctdec'],
        ':fov_w' => $row['fov_w'],
        ':fov_h' => $row['fov_h'],
        ':objctrot' => $row['objctrot'],
        ':rot' => $row['rotator_angle'],
        ':hash' => bin2hex(random_bytes(8)),
        ':mtime' => 1750000000,
        ':size' => 1024,
    ]);
    return (int)$conn->lastInsertId();
}

/** Link a frame at setup level of the given setup. */
function exportFixtureLinkSetup(PDO $conn, int $fid, int $setupId, string $role = 'master'): void
{
    $ins = $conn->prepare(
        "INSERT INTO project_files (file_id, level, node_id, filter_name, role, is_light, enabled) "
        . "VALUES (:fid, 'setup', :node, NULL, :role, 0, 1)"
    );
    $ins->execute([':fid' => $fid, ':node' => $setupId, ':role' => $role]);
}

/** The id of the first setup of a project, or 0. */
function exportFixtureFirstSetup(PDO $conn, int $projectId): int
{
    $s = $conn->prepare("SELECT id FROM project_setups WHERE project_id = :p ORDER BY setup_no LIMIT 1");
    $s->execute([':p' => $projectId]);
    return (int)$s->fetchColumn();
}

/**
 * Give a project one DARK and one BIAS at its first setup, both linked so the builder
 * picks them up. The DARK is what makes the old builder's stale-$leaf bug observable, so
 * the pair is deliberate: a BIAS alone would either land under an unrelated leaf or, as the
 * first group of the node, leave $leaf undefined.
 *
 * Returns ['dark' => id, 'bias' => id] on success, or [] when the project has no setup.
 */
function exportFixtureDarkAndBias(PDO $conn, int $projectId): array
{
    $setupId = exportFixtureFirstSetup($conn, $projectId);
    if ($setupId <= 0) {
        return [];
    }
    $dark = exportFixtureFrame($conn, 'DARK', ['exptime' => 600.0, 'ccd_temp' => -10.0]);
    $bias = exportFixtureFrame($conn, 'BIAS', ['exptime' => 1.0, 'ccd_temp' => -10.0, 'filter' => '']);
    exportFixtureLinkSetup($conn, $dark, $setupId);
    exportFixtureLinkSetup($conn, $bias, $setupId);
    return ['dark' => $dark, 'bias' => $bias, 'setup' => $setupId];
}
