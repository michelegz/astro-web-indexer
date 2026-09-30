<?php
/**
 * Project tree + calibration diagnostics (WBPP-like, v1 free).
 *
 * Tree: PROJECT -> SETUP -> PANEL -> SESSION -> FILTER -> lights,
 * with calibrations (sub/master) attached at the level they were linked.
 *
 * effective_calib(): for a LIGHT, walk filter/session -> session ->
 * panel -> setup -> project, first match per calibration type wins;
 * a master in a level shadows subs of the same type in that level.
 *
 * Match rules (tolerances resolved per project, see resolve_tol()):
 * - dark-light:  exptime within tol_exp_dark + same binning/gain + ccd_temp within tol_temp
 * - flat-light:  same filter (case-insensitive) + same binning
 * - bias:        same binning (+gain when both known)
 * Missing comparison fields => yellow (unverifiable), no candidate => red.
 */

function parseTolFraction(string $value, float $default): float
{
    $value = trim($value);
    if (str_ends_with($value, '%')) {
        $num = (float)substr($value, 0, -1);
        return $num >= 0 ? $num / 100.0 : $default;
    }
    $num = (float)$value;
    return $num >= 0 ? $num : $default;
}

function parseTolAbs(string $value, float $default): float
{
    $num = (float)preg_replace('/[^0-9.\-]/', '', $value);
    return $num >= 0 ? $num : $default;
}

function sameBinning(array $a, array $b): ?bool
{
    if ($a['xbinning'] === null || $b['xbinning'] === null) {
        return null; // unverifiable
    }
    return (int)$a['xbinning'] === (int)$b['xbinning']
        && (int)($a['ybinning'] ?? $a['xbinning']) === (int)($b['ybinning'] ?? $b['xbinning']);
}

function sameGain(array $a, array $b): ?bool
{
    if ($a['gain'] === null || $b['gain'] === null) {
        return null;
    }
    return abs((float)$a['gain'] - (float)$b['gain']) < 1e-9;
}

/**
 * Match one dark against one light. Returns green|yellow|red.
 */
function matchDark(array $light, array $dark, array $tols): string
{
    if ($light['exptime'] === null || $dark['exptime'] === null) {
        return 'yellow';
    }
    $tol = parseTolFraction((string)($tols['tol_exp_dark'] ?? '10%'), 0.10);
    $ref = (float)$light['exptime'];
    if ($ref <= 0) {
        return 'yellow';
    }
    if (abs((float)$dark['exptime'] - $ref) / $ref > $tol) {
        return 'red';
    }
    $bin = sameBinning($light, $dark);
    if ($bin === false) {
        return 'red';
    }
    $gain = sameGain($light, $dark);
    if ($gain === false) {
        return 'red';
    }
    if ($light['ccd_temp'] !== null && $dark['ccd_temp'] !== null) {
        $tempTol = parseTolAbs((string)($tols['tol_temp'] ?? '2C'), 2.0);
        if (abs((float)$light['ccd_temp'] - (float)$dark['ccd_temp']) > $tempTol) {
            return 'yellow';
        }
    }
    return ($bin === null || $gain === null) ? 'yellow' : 'green';
}

function matchFlat(array $light, array $flat): string
{
    $lf = trim((string)($light['filter'] ?? ''));
    $ff = trim((string)($flat['filter'] ?? ''));
    if ($lf === '' || $ff === '' || strcasecmp($lf, $ff) !== 0) {
        return 'red';
    }
    $bin = sameBinning($light, $flat);
    if ($bin === false) {
        return 'red';
    }
    return $bin === null ? 'yellow' : 'green';
}

function matchBias(array $light, array $bias): string
{
    $bin = sameBinning($light, $bias);
    if ($bin === false) {
        return 'red';
    }
    $gain = sameGain($light, $bias);
    if ($gain === false) {
        return 'red';
    }
    return ($bin === null || $gain === null) ? 'yellow' : 'green';
}

/**
 * Best status among candidates of one calibration type.
 * Master shadows subs: if any master exists, only masters are considered.
 */
function bestCalibStatus(array $candidates, callable $matcher): string
{
    if (empty($candidates)) {
        return 'red';
    }
    $masters = array_values(array_filter($candidates, fn($c) => ($c['role'] ?? 'sub') === 'master'));
    $pool = $masters !== [] ? $masters : $candidates;
    $best = 'red';
    foreach ($pool as $cand) {
        $status = $matcher($cand);
        if ($status === 'green') {
            return 'green';
        }
        if ($status === 'yellow') {
            $best = 'yellow';
        }
    }
    return $best;
}

/**
 * Full project tree with files attached, in one batched read.
 * Calibrations stay at their linked level; lights hang under filter groups.
 *
 * With $includePending, pending wizard suggestions are merged as hypothetical
 * rows (marked 'pending' => true): the tree shows where files would land if
 * accepted. diagnoseProjectTree() always ignores them. Branches holding only
 * pending rows are shown (hypothetical content); fully empty ones are hidden.
 */
function getProjectTree(PDO $conn, int $projectId, bool $includePending = false): array
{
    $tree = ['setups' => [], 'project_links' => []];

    $setups = $conn->prepare("SELECT id, fingerprint, label FROM project_setups WHERE project_id = :pid ORDER BY id ASC");
    $setups->execute([':pid' => $projectId]);
    $setupRows = $setups->fetchAll();
    if (empty($setupRows)) {
        return $tree;
    }
    $setupIds = array_column($setupRows, 'id');

    $inSetups = implode(',', array_fill(0, count($setupIds), '?'));
    $panels = $conn->prepare(
        "SELECT id, setup_id, panel_no, ra, `dec`, rot_mean, fov_w, fov_h, label_object "
        . "FROM project_panels WHERE setup_id IN ($inSetups) ORDER BY id ASC"
    );
    $panels->execute($setupIds);
    $panelRows = $panels->fetchAll();

    $panelIds = array_column($panelRows, 'id');
    $sessionRows = [];
    if (!empty($panelIds)) {
        $inPanels = implode(',', array_fill(0, count($panelIds), '?'));
        $sessions = $conn->prepare(
            "SELECT id, panel_id, astro_night FROM project_sessions WHERE panel_id IN ($inPanels) ORDER BY astro_night ASC, id ASC"
        );
        $sessions->execute($panelIds);
        $sessionRows = $sessions->fetchAll();
    }

    // All links of this project with file metadata, one query.
    $links = $conn->prepare(
        "SELECT pf.level, pf.node_id, pf.filter_name, pf.role, pf.is_light, pf.enabled, "
        . "f.id AS file_id, f.name, f.path, f.imgtype, f.filter, f.exptime, f.date_obs, "
        . "f.xbinning, f.ybinning, f.gain, f.ccd_temp "
        . "FROM project_files pf JOIN files f ON f.id = pf.file_id "
        . "WHERE (pf.level = 'project' AND pf.node_id = :pid) "
        . "OR (pf.level = 'setup' AND pf.node_id IN (SELECT id FROM project_setups WHERE project_id = :pid2)) "
        . "OR (pf.level = 'panel' AND pf.node_id IN (SELECT pp.id FROM project_panels pp "
        . "JOIN project_setups ps ON ps.id = pp.setup_id WHERE ps.project_id = :pid3)) "
        . "OR (pf.level IN ('session','filter') AND pf.node_id IN (SELECT ss.id FROM project_sessions ss "
        . "JOIN project_panels pp ON pp.id = ss.panel_id "
        . "JOIN project_setups ps ON ps.id = pp.setup_id WHERE ps.project_id = :pid4)) "
        . "ORDER BY f.date_obs ASC, f.name ASC"
    );
    $links->execute([':pid' => $projectId, ':pid2' => $projectId, ':pid3' => $projectId, ':pid4' => $projectId]);
    $linkRows = $links->fetchAll();

    if ($includePending) {
        $pend = $conn->prepare(
            "SELECT s.id AS suggestion_id, s.level, s.node_id, s.filter_name, s.role, s.reason, "
            . "CASE WHEN UPPER(f.imgtype) = 'LIGHT' THEN 1 ELSE 0 END AS is_light, "
            . "f.id AS file_id, f.name, f.path, f.imgtype, f.filter, f.exptime, f.date_obs, "
            . "f.xbinning, f.ybinning, f.gain, f.ccd_temp "
            . "FROM project_suggestions s JOIN files f ON f.id = s.file_id "
            . "WHERE s.project_id = :pid AND s.status = 'pending' "
            . "ORDER BY s.created_at ASC LIMIT 2000"
        );
        $pend->execute([':pid' => $projectId]);
        foreach ($pend->fetchAll() as $prow) {
            $prow['pending'] = true;
            $prow['enabled'] = 1;
            // Rows pointing at deleted nodes are ignored by construction:
            // the tree walk below only visits existing setup/panel/session rows.
            $linkRows[] = $prow;
        }
    }

    // Index sessions by panel, links by level+node.
    $sessionsByPanel = [];
    foreach ($sessionRows as $s) {
        $sessionsByPanel[$s['panel_id']][] = $s;
    }
    $linksByNode = [];
    foreach ($linkRows as $l) {
        if ($l['level'] === 'project') {
            $tree['project_links'][] = $l;
            continue;
        }
        $linksByNode[$l['level'] . ':' . $l['node_id']][] = $l;
    }

    foreach ($setupRows as $setup) {
        $setupNode = $setup + ['panels' => [], 'calibrations' => []];
        foreach ($linksByNode['setup:' . $setup['id']] ?? [] as $l) {
            $setupNode['calibrations'][] = $l;
        }
        foreach ($panelRows as $panel) {
            if ((int)$panel['setup_id'] !== (int)$setup['id']) {
                continue;
            }
            $panelNode = $panel + ['sessions' => [], 'calibrations' => []];
            foreach ($linksByNode['panel:' . $panel['id']] ?? [] as $l) {
                $panelNode['calibrations'][] = $l;
            }
            foreach ($sessionsByPanel[$panel['id']] ?? [] as $session) {
                $sessionNode = $session + ['filters' => [], 'calibrations' => []];
                $byFilter = [];
                $filterCals = [];
                foreach ($linksByNode['session:' . $session['id']] ?? [] as $l) {
                    if ((int)$l['is_light'] === 1) {
                        $byFilter['__lights__'][] = $l;
                    } else {
                        $sessionNode['calibrations'][] = $l;
                    }
                }
                foreach ($linksByNode['filter:' . $session['id']] ?? [] as $l) {
                    $key = trim((string)($l['filter_name'] ?? $l['filter'] ?? ''));
                    $key = $key !== '' ? $key : '__nofilter__';
                    if ((int)$l['is_light'] === 1) {
                        $byFilter[$key][] = $l;
                    } else {
                        // Filter-level calibrations apply to their own filter group only.
                        $filterCals[$key][] = $l;
                        $byFilter[$key] = $byFilter[$key] ?? [];
                    }
                }
                foreach ($byFilter as $fname => $lights) {
                    $exp = 0.0;
                    foreach ($lights as $li) {
                        $exp += (float)($li['exptime'] ?? 0);
                    }
                    $sessionNode['filters'][] = [
                        'name' => $fname === '__lights__' || $fname === '__nofilter__' ? '' : $fname,
                        'lights' => $lights,
                        'calibrations' => $filterCals[$fname] ?? [],
                        'count' => count($lights),
                        'exposure' => $exp,
                    ];
                }
                $panelNode['sessions'][] = $sessionNode;
            }
            $setupNode['panels'][] = $panelNode;
        }
        $tree['setups'][] = $setupNode;
    }
    // Hide empty branches: setups/panels/sessions created as suggestion
    // targets appear only once they actually hold linked files.
    foreach ($tree['setups'] as $si => $setupNode) {
        foreach ($setupNode['panels'] as $pi => $panelNode) {
            foreach ($panelNode['sessions'] as $sesi => $sessionNode) {
                $hasContent = !empty($sessionNode['calibrations']);
                if (!$hasContent) {
                    foreach ($sessionNode['filters'] as $f) {
                        if (!empty($f['lights']) || !empty($f['calibrations'])) {
                            $hasContent = true;
                            break;
                        }
                    }
                }
                if (!$hasContent) {
                    unset($tree['setups'][$si]['panels'][$pi]['sessions'][$sesi]);
                }
            }
            $tree['setups'][$si]['panels'][$pi]['sessions'] = array_values(
                $tree['setups'][$si]['panels'][$pi]['sessions']
            );
            if (empty($tree['setups'][$si]['panels'][$pi]['sessions'])
                && empty($tree['setups'][$si]['panels'][$pi]['calibrations'])) {
                unset($tree['setups'][$si]['panels'][$pi]);
            }
        }
        $tree['setups'][$si]['panels'] = array_values($tree['setups'][$si]['panels']);
        if (empty($tree['setups'][$si]['panels']) && empty($tree['setups'][$si]['calibrations'])) {
            unset($tree['setups'][$si]);
        }
    }
    $tree['setups'] = array_values($tree['setups']);
    return $tree;
}

/**
 * Strip hypothetical (pending) rows from a tree built with $includePending.
 * The main project tree shows real links only; pending selection lives in
 * the wizard modal. Empty branches left behind are pruned.
 */
function stripPendingTree(array $tree): array
{
    foreach ($tree['setups'] ?? [] as $si => $setup) {
        $tree['setups'][$si]['calibrations'] = array_values(array_filter(
            $setup['calibrations'],
            fn($c) => empty($c['pending'])
        ));
        foreach ($setup['panels'] as $pi => $panel) {
            $tree['setups'][$si]['panels'][$pi]['calibrations'] = array_values(array_filter(
                $panel['calibrations'],
                fn($c) => empty($c['pending'])
            ));
            foreach ($panel['sessions'] as $sesi => $session) {
                $tree['setups'][$si]['panels'][$pi]['sessions'][$sesi]['calibrations'] = array_values(array_filter(
                    $session['calibrations'],
                    fn($c) => empty($c['pending'])
                ));
                $filters = [];
                foreach ($session['filters'] as $f) {
                    $lights = array_values(array_filter(
                        $f['lights'],
                        fn($li) => empty($li['pending'])
                    ));
                    $cals = array_values(array_filter(
                        $f['calibrations'],
                        fn($c) => empty($c['pending'])
                    ));
                    if (empty($lights) && empty($cals)) {
                        continue;
                    }
                    $exp = 0.0;
                    foreach ($lights as $li) {
                        $exp += (float)($li['exptime'] ?? 0);
                    }
                    $f['lights'] = $lights;
                    $f['calibrations'] = $cals;
                    $f['count'] = count($lights);
                    $f['exposure'] = $exp;
                    $filters[] = $f;
                }
                $tree['setups'][$si]['panels'][$pi]['sessions'][$sesi]['filters'] = $filters;
                if (empty($filters)
                    && empty($tree['setups'][$si]['panels'][$pi]['sessions'][$sesi]['calibrations'])) {
                    unset($tree['setups'][$si]['panels'][$pi]['sessions'][$sesi]);
                }
            }
            $tree['setups'][$si]['panels'][$pi]['sessions'] = array_values(
                $tree['setups'][$si]['panels'][$pi]['sessions']
            );
            if (empty($tree['setups'][$si]['panels'][$pi]['sessions'])
                && empty($tree['setups'][$si]['panels'][$pi]['calibrations'])) {
                unset($tree['setups'][$si]['panels'][$pi]);
            }
        }
        $tree['setups'][$si]['panels'] = array_values($tree['setups'][$si]['panels']);
        if (empty($tree['setups'][$si]['panels']) && empty($tree['setups'][$si]['calibrations'])) {
            unset($tree['setups'][$si]);
        }
    }
    $tree['setups'] = array_values($tree['setups'] ?? []);
    return $tree;
}

/**
 * Exposure subgroups for a filter's lights (hierarchy level under filter).
 * Sorted ascending; a new group starts when |v - anchor| / anchor exceeds
 * $tolFrac (anchor = first value of the group). NULL exposures share one
 * group. Same rule feeds the main tree, the wizard modal and the suggester.
 *
 * Returns [['exptime' => ?float, 'lights' => [...]], ...].
 */
function clusterExposures(array $lights, float $tolFrac): array
{
    $withNull = [];
    $withVal = [];
    foreach ($lights as $li) {
        if ($li['exptime'] === null || $li['exptime'] === '') {
            $withNull[] = $li;
        } else {
            $withVal[] = $li;
        }
    }
    usort($withVal, fn($a, $b) => (float)$a['exptime'] <=> (float)$b['exptime']);
    $groups = [];
    $anchor = null;
    foreach ($withVal as $li) {
        $v = (float)$li['exptime'];
        $same = $anchor !== null
            && ($anchor > 0 ? abs($v - $anchor) / $anchor <= $tolFrac : $v == $anchor);
        if (!$same) {
            $groups[] = ['exptime' => $v, 'lights' => []];
            $anchor = $v;
        }
        $groups[count($groups) - 1]['lights'][] = $li;
    }
    if (!empty($withNull)) {
        $groups[] = ['exptime' => null, 'lights' => $withNull];
    }
    return $groups;
}

function fmtExpShort($exptime): string
{
    if ($exptime === null) {
        return '—';
    }
    $v = (float)$exptime;
    $s = $v == floor($v) ? number_format($v, 0) : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    return $s . ' s';
}

/**
 * Diagnostics for every LINKED light in the tree. Returns [file_id => [...]].
 * Pending (hypothetical) rows are ignored, both as lights and as candidates.
 * Candidate pool per light = calibrations linked at filter/session/panel/setup/project
 * levels along its own chain (masters shadow subs per level+type).
 */
function diagnoseProjectTree(array $tree, array $tols): array
{
    $out = [];
    $projectCals = array_values(array_filter(
        $tree['project_links'] ?? [],
        fn($c) => in_array(strtoupper((string)$c['imgtype']), ['DARK', 'FLAT', 'BIAS'], true)
    ));
    foreach ($tree['setups'] as $setup) {
        $setupCals = array_merge($projectCals, $setup['calibrations']);
        foreach ($setup['panels'] as $panel) {
            $panelCals = array_merge($setupCals, $panel['calibrations']);
            foreach ($panel['sessions'] as $session) {
                $sessionCals = array_merge($panelCals, $session['calibrations']);
                foreach ($session['filters'] as $filter) {
                    $pool = array_merge($sessionCals, $filter['calibrations']);
                    $pool = array_values(array_filter(
                        $pool,
                        fn($c) => empty($c['pending']) && !empty($c['enabled'])
                    ));
                    foreach ($filter['lights'] as $light) {
                        if (!empty($light['pending']) || empty($light['enabled'])) {
                            continue;
                        }
                        $darks = array_values(array_filter($pool, fn($c) => strtoupper((string)$c['imgtype']) === 'DARK'));
                        $flats = array_values(array_filter($pool, fn($c) => strtoupper((string)$c['imgtype']) === 'FLAT'));
                        $biases = array_values(array_filter($pool, fn($c) => strtoupper((string)$c['imgtype']) === 'BIAS'));
                        $out[(int)$light['file_id']] = [
                            'dark' => bestCalibStatus($darks, fn($c) => matchDark($light, $c, $tols)),
                            'flat' => bestCalibStatus($flats, fn($c) => matchFlat($light, $c)),
                            'bias' => bestCalibStatus($biases, fn($c) => matchBias($light, $c)),
                        ];
                    }
                }
            }
        }
    }
    return $out;
}

function diagWorst(string $a, string $b): string
{
    $rank = ['green' => 0, 'yellow' => 1, 'red' => 2];
    return ($rank[$a] ?? 0) >= ($rank[$b] ?? 0) ? $a : $b;
}

function diagDot(string $status): string
{
    return $status === 'green' ? '🟢' : ($status === 'yellow' ? '🟡' : '🔴');
}
