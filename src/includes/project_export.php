<?php
// WBPP-style project ZIP export map builder.
// Shared by the preview endpoint and the download endpoint so the preview
// can never diverge from the archive. Pure mapping: no disk reads here
// (missing files are filtered at zip time and reported as skipped).
//
// Layout (mirror tree + WBPP path keywords, see tmp/project-zip-export-plan.md):
//   SETUP_S1_<label>/DARK_EXP300_Tm10C/[DARKSET_S1S3/]dark.fits
//   SETUP_S1_<label>/BIAS/bias.fits
//   SETUP_S1_<label>/PANEL_P1/SESSION_S1_20240501[_DARKSET_S1S3]/LIGHT_Ha/light.fits
//   SETUP_S1_<label>/PANEL_P1/SESSION_S1_20240501/FLAT_Ha/flat.fits
//
// Rules: effective files only (enabled, not pending, not auto_off); masters
// preferred over subs per group; one copy per file_id (first in top-down walk
// wins); scope sets become single-value DARKSET_/FLATSET_ tokens on both sides.

/**
 * Filesystem- and WBPP-safe token: strict [A-Za-z0-9_].
 */
function exportSanitize(string $s): string
{
    $s = preg_replace('/[^A-Za-z0-9_]+/', '_', $s);
    return trim((string)$s, '_');
}

/**
 * Compact night: 2024-05-01 -> 20240501 (dashes are WBPP stop characters).
 */
function exportNight(string $night): string
{
    return str_replace('-', '', trim($night));
}

/**
 * Exposure folder fragment: 300 -> EXP300, 12.5 -> EXP12.5.
 */
function exportExpFrag($exptime): string
{
    if ($exptime === null || $exptime === '') {
        return 'EXPX';
    }
    $v = (float)$exptime;
    $s = $v == floor($v) ? (string)(int)$v : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    return 'EXP' . $s;
}

/**
 * Temperature folder fragment: -10.2 -> Tm10.2 (minus would break parsing).
 */
function exportTempFrag($temp): string
{
    if ($temp === null || $temp === '') {
        return '';
    }
    $t = round((float)$temp, 1);
    if ($t == 0) {
        $t = 0.0;
    }
    $s = number_format($t, 1, '.', '');
    if (str_starts_with($s, '-')) {
        $s = 'm' . substr($s, 1);
    }
    return 'T' . $s . 'C';
}

/**
 * Set token value from session numbers: [1,3] -> S1S3. Unambiguous because
 * every component is S+digits.
 */
function exportSetValue(array $nos): string
{
    $nos = array_values(array_unique(array_map('intval', $nos)));
    sort($nos);
    return 'S' . implode('S', $nos);
}

/**
 * Fail closed on token hygiene of the EMITTED directories (original file
 * basenames are preserved verbatim and never parsed as keywords): only
 * [A-Za-z0-9_.] in dir components, and every grouping keyword at most once
 * per path (same keyword twice has undefined WBPP matching semantics).
 */
function validateExportTokens(array $entries): void
{
    foreach ($entries as $e) {
        $p = (string)($e['zip_path'] ?? '');
        $dir = dirname($p);
        $base = basename($p);
        if ($base === '' || str_contains($base, '/') || str_contains($base, '\\')) {
            throw new InvalidArgumentException('export_bad_token: ' . $p);
        }
        if (!preg_match('#^[A-Za-z0-9_/.]+$#', $dir)) {
            throw new InvalidArgumentException('export_bad_token: ' . $p);
        }
        foreach (['SESSION_', 'DARKSET_', 'FLATSET_', 'PANEL_', 'SETUP_'] as $kw) {
            if (substr_count($dir, $kw) > 1) {
                throw new InvalidArgumentException('export_dup_keyword: ' . $kw . ' in ' . $p);
            }
        }
    }
}

/**
 * Build the full export map for a project. Returns:
 * ['entries' => [['zip_path','fid','kind','scope','master','link']...],
 *  'sets' => ['DARKSET_S1S3' => ['type','sessions','nights']...],
 *  'skipped' => [['name','reason']...], 'manifest' => [...]].
 */
function buildProjectExportMap(PDO $conn, int $projectId): array
{
    $project = getProject($conn, $projectId);
    if ($project === null) {
        throw new InvalidArgumentException(__('projects_error_name'));
    }
    $tree = getProjectTree($conn, $projectId, false);
    $globals = getGlobalTolerances($conn);
    $defs = getToleranceDefs();
    $tols = [];
    foreach ($defs as $tkey => $tdef) {
        $tols[$tkey] = resolve_tol($conn, $projectId, $tkey);
    }
    $tolExpRaw = trim((string)($tols['tol_exp'] ?? '1%'));
    if ($tolExpRaw === '') {
        $tolExpRaw = '1%';
    }
    $projectTree = markTreeAutoOff(
        $tree,
        indexAutoOffLights(getIntegrationGroups(
            $tree,
            $tolExpRaw,
            getProjectThresholds($conn, $projectId)
        ))
    );

    // Session registry for set tokens and legends.
    $sessions = [];
    foreach ($projectTree['setups'] ?? [] as $setup) {
        foreach ($setup['panels'] ?? [] as $panel) {
            foreach ($panel['sessions'] ?? [] as $session) {
                $sessions[(int)$session['id']] = [
                    'no' => isset($session['session_no']) ? (int)$session['session_no'] : null,
                    'night' => (string)($session['astro_night'] ?? ''),
                ];
            }
        }
    }

    $st = [
        'entries' => [], 'sets' => [], 'skipped' => [],
        'seen' => [], 'dups' => [], 'used' => [],
    ];

    $addFile = function (string $dir, array $f, string $kind, $scope) use (&$st): void {
        $fid = (int)($f['file_id'] ?? $f['id'] ?? 0);
        if ($fid <= 0) {
            return;
        }
        if (isset($st['seen'][$fid])) {
            $st['dups'][] = ['name' => (string)($f['name'] ?? "#$fid"), 'kept_in' => $st['seen'][$fid], 'skipped_in' => $dir];
            return;
        }
        $base = (string)($f['name'] ?? "file_$fid.fits");
        $name = $base;
        $i = 1;
        while (isset($st['used'][$dir . "\0" . $name])) {
            $dot = strrpos($base, '.');
            $name = $dot === false ? $base . '_' . $i : substr($base, 0, $dot) . '_' . $i . substr($base, $dot);
            $i++;
        }
        $st['used'][$dir . "\0" . $name] = true;
        $st['seen'][$fid] = $dir . '/' . $name;
        $st['entries'][] = [
            'zip_path' => $dir . '/' . $name,
            'fid' => $fid,
            'src' => (string)($f['path'] ?? ''),
            'kind' => $kind,
            'scope' => $scope,
            'master' => (($f['role'] ?? 'sub') === 'master'),
        ];
    };
    $skip = function (array $f, string $reason) use (&$st): void {
        $st['skipped'][] = ['name' => (string)($f['name'] ?? ("#" . (int)($f['file_id'] ?? 0))), 'reason' => $reason];
    };

    // First pass: collect scope sets per type from effectively exported rows.
    // (Second walk below emits files; sets must be known upfront for the
    // session folder tokens, so scope collection runs on raw rows first.)
    $setSessions = [];
    $collectScopes = function (array $rows, string $type) use (&$setSessions): void {
        foreach ($rows as $r) {
            if (!empty($r['pending']) || empty($r['enabled'])) {
                continue;
            }
            $scope = $r['scope_sessions'] ?? [];
            if (!empty($scope)) {
                $setSessions[strtoupper($type)][] = array_values(array_unique(array_map('intval', $scope)));
            }
        }
    };
    $walkCals = function (array $tree, callable $fn) use (&$walkCals): void {
        $fn($tree['project_links'] ?? [], 'project', 0);
        foreach ($tree['setups'] ?? [] as $setup) {
            $fn($setup['calibrations'] ?? [], 'setup', (int)$setup['id']);
            foreach ($setup['panels'] ?? [] as $panel) {
                $fn($panel['calibrations'] ?? [], 'panel', (int)$panel['id']);
                foreach ($panel['sessions'] ?? [] as $session) {
                    $fn($session['calibrations'] ?? [], 'session', (int)$session['id']);
                    foreach ($session['filters'] ?? [] as $filter) {
                        $fn($filter['calibrations'] ?? [], 'filter', (int)$session['id']);
                    }
                }
            }
        }
    };
    $walkCals($projectTree, function (array $rows) use (&$collectScopes): void {
        foreach ($rows as $r) {
            $t = strtoupper((string)($r['imgtype'] ?? ''));
            if (in_array($t, ['DARK', 'FLAT', 'BIAS'], true)) {
                $collectScopes([$r], $t);
            }
        }
    });
    // Merge overlapping scope sets per type into named sets (union-find over
    // shared sessions would over-merge; keep distinct sets, dedupe identical).
    $sets = [];
    foreach (['DARK', 'FLAT', 'BIAS'] as $type) {
        $seen = [];
        foreach ($setSessions[$type] ?? [] as $list) {
            sort($list);
            $k = implode(',', $list);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $nos = [];
            foreach ($list as $sid) {
                if (isset($sessions[$sid]) && $sessions[$sid]['no'] !== null) {
                    $nos[] = (int)$sessions[$sid]['no'];
                }
            }
            if (empty($nos)) {
                continue;
            }
            $name = $type . 'SET_' . exportSetValue($nos);
            $sets[$name] = [
                'type' => $type,
                'sessions' => $list,
                'nights' => array_values(array_unique(array_map(
                    fn($sid) => $sessions[$sid]['night'] ?? '',
                    $list
                ))),
            ];
        }
    }
    // Session id -> set tokens covering it, per type.
    $sessionTokens = [];
    foreach ($sets as $name => $s) {
        foreach ($s['sessions'] as $sid) {
            $sessionTokens[$sid][] = $name;
        }
    }
    foreach ($sessionTokens as &$tl) {
        sort($tl);
    }
    unset($tl);

    $tolDark = (string)($tols['tol_exp_dark'] ?? '10%');
    $tolTemp = (string)($tols['tol_temp'] ?? '2C');

    // Emit one calibration group folder (masters preferred over subs).
    $emitCalGroup = function (string $dir, array $group) use (&$addFile, &$skip): void {
        $masters = array_values(array_filter(
            $group['rows'],
            fn($r) => ($r['role'] ?? 'sub') === 'master'
        ));
        $keep = $masters !== [] ? $masters : $group['rows'];
        $keepIds = [];
        foreach ($keep as $r) {
            $keepIds[(int)($r['file_id'] ?? 0)] = true;
        }
        foreach ($group['rows'] as $r) {
            if (!isset($keepIds[(int)($r['file_id'] ?? 0)])) {
                $skip($r, 'shadowed_by_master');
            }
        }
        foreach ($keep as $r) {
            $addFile($dir, $r, strtolower($group['kind']), $r['scope_nights'] ?? null);
        }
    };

    // Calibrations attached to one tree node (setup/panel/session/filter/project).
    $emitNodeCals = function (string $dir, array $cals) use (&$emitCalGroup, &$skip, $tolDark, $tolTemp, &$sets): void {
        $live = array_values(array_filter($cals, fn($c) => empty($c['pending']) && !empty($c['enabled'])));
        if (empty($live)) {
            return;
        }
        foreach (groupCalibrations($live, $tolDark, $tolTemp) as $g) {
            $kind = $g['kind'];
            if ($kind === 'flat') {
                $leaf = 'FLAT_' . exportSanitize((string)($g['label'] ?? ''));
            } elseif ($kind === 'dark') {
                $exp = null;
                $tmp = null;
                if (!empty($g['rows'])) {
                    $exp = $g['rows'][0]['exptime'] ?? null;
                    $tmp = $g['rows'][0]['ccd_temp'] ?? null;
                }
                $leaf = 'DARK_' . exportSanitize(exportExpFrag($exp) . ($tmp !== null && $tmp !== '' ? '_' . exportTempFrag($tmp) : ''));
                $leaf = rtrim($leaf, '_');
            } elseif ($kind === 'bias') {
                $leaf = 'BIAS';
            } else {
                $leaf = exportSanitize(strtoupper((string)($g['label'] ?? 'CAL')));
            }
            // Scoped rows go to per-set subfolders; unscoped stay in the group dir.
            $scoped = [];
            $plain = [];
            foreach ($g['rows'] as $r) {
                if (!empty($r['scope_sessions'])) {
                    $scoped[] = $r;
                } else {
                    $plain[] = $r;
                }
            }
            if (!empty($plain)) {
                $emitCalGroup($dir . '/' . $leaf, ['kind' => $kind, 'rows' => $plain]);
            }
            $bySet = [];
            foreach ($scoped as $r) {
                $names = [];
                foreach (array_keys($sets) as $sname) {
                    if (str_starts_with($sname, strtoupper($kind) . 'SET_')
                        && !array_diff($r['scope_sessions'], $sets[$sname]['sessions'])) {
                        $names[] = $sname;
                    }
                }
                $bySet[$names !== [] ? $names[0] : ''] [] = $r;
            }
            foreach ($bySet as $sname => $rows) {
                if ($sname === '') {
                    // Scope not matching any known set (stale sessions): keep
                    // with the plain group rather than dropping the files.
                    $emitCalGroup($dir . '/' . $leaf, ['kind' => $kind, 'rows' => $rows]);
                } else {
                    $emitCalGroup($dir . '/' . $leaf . '/' . $sname, ['kind' => $kind, 'rows' => $rows]);
                }
            }
        }
    };

    foreach ($projectTree['setups'] ?? [] as $setup) {
        $setupLabel = ($setup['label'] !== null && $setup['label'] !== '')
            ? (string)$setup['label'] : substr((string)$setup['fingerprint'], 0, 48);
        $setupDir = 'SETUP_S' . (int)($setup['setup_no'] ?? $setup['id'])
            . '_' . exportSanitize($setupLabel);
        $emitNodeCals($setupDir, $setup['calibrations'] ?? []);
        foreach ($setup['panels'] ?? [] as $panel) {
            $panelDir = $setupDir . '/PANEL_P' . (int)($panel['panel_no'] ?? $panel['id']);
            $emitNodeCals($panelDir, $panel['calibrations'] ?? []);
            foreach ($panel['sessions'] ?? [] as $session) {
                $sno = isset($session['session_no']) ? (int)$session['session_no'] : null;
                $sessDir = $panelDir . '/SESSION_'
                    . ($sno !== null ? 'S' . $sno . '_' : '')
                    . exportNight((string)($session['astro_night'] ?? ''));
                foreach ($sessionTokens[(int)$session['id']] ?? [] as $tok) {
                    $sessDir .= '_' . $tok;
                }
                foreach ($session['filters'] ?? [] as $filter) {
                    $fname = trim((string)($filter['name'] ?? ''));
                    // Effective lights only (red diagnostics stay in: WBPP decides).
                    foreach ($filter['lights'] ?? [] as $li) {
                        if (!empty($li['pending']) || empty($li['enabled'])) {
                            continue;
                        }
                        if (!empty($li['auto_off'])) {
                            $skip($li, 'auto_off');
                            continue;
                        }
                        if (strtoupper((string)($li['imgtype'] ?? '')) !== 'LIGHT') {
                            continue;
                        }
                        $leaf = 'LIGHT' . ($fname !== '' ? '_' . exportSanitize($fname) : '');
                        $addFile($sessDir . '/' . $leaf, $li, 'light', null);
                    }
                    // Filter-level calibrations live next to their lights.
                    $fdir = $sessDir . '/' . ('FILTER' . ($fname !== '' ? '_' . exportSanitize($fname) : ''));
                    $emitNodeCals($fdir, $filter['calibrations'] ?? []);
                }
                // Session-level calibrations: out-of-scope rows are ineffective.
                $sessCals = [];
                foreach ($session['calibrations'] ?? [] as $c) {
                    if (!empty($c['pending']) || empty($c['enabled'])) {
                        continue;
                    }
                    $scope = $c['scope_sessions'] ?? [];
                    if (!empty($scope) && !in_array((int)$session['id'], $scope, true)) {
                        $skip($c, 'out_of_scope');
                        continue;
                    }
                    $sessCals[] = $c;
                }
                $emitNodeCals($sessDir, $sessCals);
            }
        }
    }
    // Project-level calibrations (rare): shared folder at ZIP root.
    $projCals = array_values(array_filter(
        $projectTree['project_links'] ?? [],
        function ($c) {
            return empty($c['pending']) && !empty($c['enabled'])
                && in_array(strtoupper((string)($c['imgtype'] ?? '')), ['DARK', 'FLAT', 'BIAS'], true);
        }
    ));
    if (!empty($projCals)) {
        $emitNodeCals('SHARED', $projCals);
    }

    validateExportTokens($st['entries']);

    $folders = [];
    foreach ($st['entries'] as $e) {
        $d = dirname($e['zip_path']);
        $folders[$d]['files'][] = basename($e['zip_path']);
        $folders[$d]['kinds'][$e['kind']] = true;
        if (!empty($e['scope'])) {
            $folders[$d]['scope'] = array_values(array_unique(array_merge(
                $folders[$d]['scope'] ?? [],
                (array)$e['scope']
            )));
        }
    }
    ksort($folders);
    return [
        'entries' => $st['entries'],
        'sets' => $sets,
        'skipped' => $st['skipped'],
        'duplicates_resolved' => $st['dups'],
        'manifest' => [
            'project' => (string)($project['name'] ?? ''),
            'exported_at' => date('c'),
            'sets' => $sets,
            'folders' => $folders,
            'skipped' => $st['skipped'],
            'duplicates_resolved' => $st['dups'],
        ],
    ];
}
