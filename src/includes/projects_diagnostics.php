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
 * - dark-light:  exptime within tol_exp + same binning/gain + ccd_temp within tol_temp
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

/**
 * Exposure tolerance in absolute seconds.
 *
 * Accepts "10%" (fraction of the reference exposure), "5s" (absolute
 * seconds) or a bare number (legacy: fraction, as parseTolFraction).
 * $defaultFraction applies to empty/unparsable values.
 */
function expTolSeconds(string $value, float $ref, float $defaultFraction): float
{
    if ($ref <= 0) {
        return 0.0;
    }
    $value = trim($value);
    if ($value === '') {
        return $ref * $defaultFraction;
    }
    if (str_ends_with($value, '%')) {
        $num = (float)substr($value, 0, -1);
        return $ref * ($num >= 0 ? $num / 100.0 : $defaultFraction);
    }
    if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*s$/i', $value, $m)) {
        return max(0.0, (float)$m[1]);
    }
    $num = (float)$value;
    return $ref * ($num >= 0 ? $num : $defaultFraction);
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
    if ($a['gain'] === null && $b['gain'] === null) {
        // Nothing contradicts: pools are chain-scoped to one setup, which
        // already partitions by gain whenever it is known (fingerprint).
        return true;
    }
    if ($a['gain'] === null || $b['gain'] === null) {
        return null; // asymmetric: genuinely suspicious, stays yellow
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
    $tol = parseTolFraction((string)($tols['tol_exp'] ?? '1%'), 0.01);
    $ref = (float)$light['exptime'];
    if ($ref <= 0) {
        return 'yellow';
    }
    if (abs((float)$dark['exptime'] - $ref) > expTolSeconds((string)($tols['tol_exp'] ?? '1%'), $ref, $tol) + 1e-9) {
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

function matchFlat(array $light, array $flat, array $filterAliases = []): string
{
    // Filter identity is canonical: aliased names (Ha/H-alpha) match.
    $lf = canonFilterName($filterAliases, (string)($light['filter'] ?? ''));
    $ff = canonFilterName($filterAliases, (string)($flat['filter'] ?? ''));
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
 * Rotation signal of one flat against its panel: 'ok' when both rotations
 * are known and within tolerance, 'unknown' when a side is missing,
 * 'mismatch' when both are known and apart. Wildcard semantics mirror
 * projectRotDist()/rotation_distance(): a missing side never blocks.
 */
function flatRotSignal(array $flat, $panelRot, float $tolRot): string
{
    [$dist, $unknown] = projectRotDist($flat['objctrot'] ?? null, $panelRot);
    if ($unknown) {
        return 'unknown';
    }
    return $dist <= $tolRot ? 'ok' : 'mismatch';
}

/**
 * Flat verdict plus the rotation signal of the flat that determined it.
 * Candidate ordering mirrors bestCalibStatus exactly (masters shadow subs,
 * first green wins, else first yellow), so the status matches what callers
 * would get from bestCalibStatus. Rotation never changes the verdict: it is
 * reported alongside as [status, rot], with rot null when no usable flat
 * exists at all (badge hidden, like a missing verdict).
 *
 * @return array{0:string,1:?string}
 */
function flatStatusWithRot(array $light, array $flats, $panelRot, array $filterAliases, float $tolRot): array
{
    if (empty($flats)) {
        return ['red', null];
    }
    $masters = array_values(array_filter($flats, fn($c) => ($c['role'] ?? 'sub') === 'master'));
    $pool = $masters !== [] ? $masters : $flats;
    $best = 'red';
    $bestRow = null;
    foreach ($pool as $cand) {
        $status = matchFlat($light, $cand, $filterAliases);
        if ($status === 'green') {
            return ['green', flatRotSignal($cand, $panelRot, $tolRot)];
        }
        if ($status === 'yellow' && $bestRow === null) {
            $best = 'yellow';
            $bestRow = $cand;
        }
    }
    if ($bestRow === null) {
        return ['red', null];
    }
    return [$best, flatRotSignal($bestRow, $panelRot, $tolRot)];
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
    // Filter aliases (per project): filter nodes group by canonical name so
    // 'Ha' and 'H-alpha' share one node, one export folder and one threshold key.
    $filterAliases = getProjectFilterAliases($conn, $projectId);
    $setups = $conn->prepare("SELECT id, fingerprint, label, setup_no FROM project_setups WHERE project_id = :pid ORDER BY id ASC");
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
            "SELECT id, panel_id, astro_night, session_no FROM project_sessions WHERE panel_id IN ($inPanels) ORDER BY astro_night ASC, id ASC"
        );
        $sessions->execute($panelIds);
        $sessionRows = $sessions->fetchAll();
    }

    // All links of this project with file metadata, one query.
    // f.* carries every column the shared file table can render
    // (preview, path/object, star & frame metrics, SFF/duplicates data).
    // project_files has no `id` column, so f.* is unambiguous; file_id
    // aliases f.id for callers using the link-oriented key.
    $links = $conn->prepare(
        "SELECT pf.level, pf.node_id, pf.filter_name, pf.role, pf.is_light, pf.enabled, "
        . "f.*, f.id AS file_id "
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
            . "f.*, f.id AS file_id "
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
                    $key = canonFilterName($filterAliases, (string)($l['filter_name'] ?? $l['filter'] ?? ''));
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
    attachCalibScopes($conn, $tree);
    return $tree;
}

/**
 * Attach session scopes to calibration link rows: `scope_sessions` (ids, for
 * diagnostics) and `scope_nights` (display labels). Links without scope rows
 * apply to the whole chain, so nothing is attached to them. Pending rows
 * never carry scope.
 */
function attachCalibScopes(PDO $conn, array &$tree): void
{
    // Collect every real link identity (levels are implicit in the walk:
    // filter-level links live on their session row).
    $all = [];
    $gather = function (array $rows, string $level, int $node) use (&$all): void {
        foreach ($rows as $r) {
            if (!empty($r['pending'])) {
                continue;
            }
            $fid = (int)($r['file_id'] ?? $r['id'] ?? 0);
            if ($fid > 0) {
                $all[$fid . '|' . $level . '|' . $node] = true;
            }
        }
    };
    $gather($tree['project_links'] ?? [], 'project', 0);
    foreach ($tree['setups'] ?? [] as $setup) {
        $gather($setup['calibrations'] ?? [], 'setup', (int)$setup['id']);
        foreach ($setup['panels'] ?? [] as $panel) {
            $gather($panel['calibrations'] ?? [], 'panel', (int)$panel['id']);
            foreach ($panel['sessions'] ?? [] as $session) {
                $gather($session['calibrations'] ?? [], 'session', (int)$session['id']);
                foreach ($session['filters'] ?? [] as $filter) {
                    $gather($filter['calibrations'] ?? [], 'filter', (int)$session['id']);
                }
            }
        }
    }
    if (empty($all)) {
        return;
    }
    $ph = implode(',', array_fill(0, count($all), '(?,?,?)'));
    $params = [];
    foreach (array_keys($all) as $k) {
        [$f, $l, $n] = explode('|', $k, 3);
        $params[] = (int)$f;
        $params[] = $l;
        $params[] = (int)$n;
    }
    $st = $conn->prepare(
        "SELECT s.file_id, s.level, s.node_id, s.session_id, ss.astro_night, ss.session_no "
        . "FROM project_calib_scope s JOIN project_sessions ss ON ss.id = s.session_id "
        . "WHERE (s.file_id, s.level, s.node_id) IN ($ph)"
    );
    $st->execute($params);
    $map = [];
    foreach ($st->fetchAll() as $r) {
        $k = (int)$r['file_id'] . '|' . $r['level'] . '|' . (int)$r['node_id'];
        $map[$k]['sessions'][] = (int)$r['session_id'];
        $night = (string)$r['astro_night'];
        $map[$k]['nights'][] = $r['session_no'] !== null ? 'N' . (int)$r['session_no'] . ' · ' . $night : $night;
    }
    if (empty($map)) {
        return;
    }
    $attach = function (array &$rows, string $level, int $node) use (&$attach, $map): void {
        foreach ($rows as &$r) {
            if (!empty($r['pending'])) {
                continue;
            }
            $fid = (int)($r['file_id'] ?? $r['id'] ?? 0);
            $k = $fid . '|' . $level . '|' . $node;
            if ($fid > 0 && isset($map[$k])) {
                $r['scope_sessions'] = array_values(array_unique($map[$k]['sessions']));
                $r['scope_nights'] = array_values(array_unique($map[$k]['nights']));
                sort($r['scope_nights']);
            }
        }
        unset($r);
    };
    if (isset($tree['project_links'])) {
        $attach($tree['project_links'], 'project', 0);
    }
    foreach ($tree['setups'] as &$setup) {
        if (isset($setup['calibrations'])) {
            $attach($setup['calibrations'], 'setup', (int)$setup['id']);
        }
        foreach ($setup['panels'] as &$panel) {
            if (isset($panel['calibrations'])) {
                $attach($panel['calibrations'], 'panel', (int)$panel['id']);
            }
            foreach ($panel['sessions'] as &$session) {
                if (isset($session['calibrations'])) {
                    $attach($session['calibrations'], 'session', (int)$session['id']);
                }
                foreach ($session['filters'] as &$filter) {
                    if (isset($filter['calibrations'])) {
                        $attach($filter['calibrations'], 'filter', (int)$session['id']);
                    }
                }
            }
        }
    }
    unset($setup, $panel, $session, $filter);
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
 * Sorted ascending; a new group starts when |v - anchor| exceeds the
 * tolerance resolved from $tolExpRaw against the anchor ("10%" = fraction
 * of anchor, "5s" = absolute seconds, bare number = legacy fraction).
 * NULL exposures share one group. Same rule feeds the main tree, the wizard
 * modal and the suggester.
 *
 * Returns [['exptime' => ?float, 'lights' => [...]], ...].
 */
function clusterExposures(array $lights, string $tolExpRaw, float $defaultFraction = 0.01): array
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
            && ($anchor > 0 ? abs($v - $anchor) <= expTolSeconds($tolExpRaw, $anchor, $defaultFraction) : $v == $anchor);
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
 * Dark vs dark-flat roles per calibration link ("fid:level:node" => 'darkflat';
 * absence means dark). A dark serves flats when some flat in its chain matches
 * it (matchDark != red: exposure within tol_exp plus binning/gain, temperature
 * forgiven as yellow), and stays a DARK whenever it also serves a light
 * (DARK wins ties, e.g. short planetary lights sharing flat exposures) or
 * serves nothing at all (keeps today's red behavior). Chain = the node
 * itself plus everything below it, so setup-level darks see session flats.
 * Pending rows count as intent; scope is ignored here (it stays an
 * applicability filter downstream).
 */
function indexDarkRoles(array $tree, array $tols): array
{
    $roles = [];
    $hasExp = fn($r) => ($r['exptime'] ?? null) !== null && ($r['exptime'] ?? '') !== '';
    $isFlat = fn($r) => strtoupper((string)($r['imgtype'] ?? '')) === 'FLAT';
    $isLight = fn($r) => strtoupper((string)($r['imgtype'] ?? '')) === 'LIGHT';
    $classify = function (array $darkRows, string $level, int $node, array $subLights, array $subFlats) use (&$roles, $tols, $hasExp): void {
        foreach ($darkRows as $d) {
            if (strtoupper((string)($d['imgtype'] ?? '')) !== 'DARK' || !$hasExp($d)) {
                continue;
            }
            $servesFlats = false;
            foreach ($subFlats as $f) {
                if ($hasExp($f) && matchDark($f, $d, $tols) !== 'red') {
                    $servesFlats = true;
                    break;
                }
            }
            if (!$servesFlats) {
                continue;
            }
            $servesLights = false;
            foreach ($subLights as $li) {
                if ($hasExp($li) && matchDark($li, $d, $tols) !== 'red') {
                    $servesLights = true;
                    break;
                }
            }
            if ($servesLights) {
                continue;
            }
            $fid = (int)($d['file_id'] ?? $d['id'] ?? 0);
            if ($fid > 0) {
                $roles[$fid . ':' . $level . ':' . $node] = 'darkflat';
            }
        }
    };
    $filterRows = function (array $sessionNode) use ($isFlat, $isLight): array {
        $lights = [];
        $flats = [];
        foreach ($sessionNode['filters'] ?? [] as $filter) {
            foreach ($filter['lights'] ?? [] as $li) {
                if ($isLight($li)) {
                    $lights[] = $li;
                }
            }
            foreach ($filter['calibrations'] ?? [] as $c) {
                if ($isFlat($c)) {
                    $flats[] = $c;
                }
            }
        }
        foreach ($sessionNode['calibrations'] ?? [] as $c) {
            if ($isFlat($c)) {
                $flats[] = $c;
            }
        }
        return [$lights, $flats];
    };
    foreach ($tree['setups'] ?? [] as $setup) {
        $setupLights = [];
        $setupFlats = [];
        foreach ($setup['calibrations'] ?? [] as $c) {
            if ($isFlat($c)) {
                $setupFlats[] = $c;
            }
        }
        foreach ($setup['panels'] ?? [] as $panel) {
            $panelLights = [];
            $panelFlats = [];
            foreach ($panel['calibrations'] ?? [] as $c) {
                if ($isFlat($c)) {
                    $panelFlats[] = $c;
                }
            }
            foreach ($panel['sessions'] ?? [] as $session) {
                [$sessLights, $sessFlats] = $filterRows($session);
                $panelLights = array_merge($panelLights, $sessLights);
                $panelFlats = array_merge($panelFlats, $sessFlats);
                $classify(
                    array_values(array_filter($session['calibrations'] ?? [], fn($c) => !$isFlat($c) && !$isLight($c))),
                    'session',
                    (int)$session['id'],
                    $sessLights,
                    $sessFlats
                );
                foreach ($session['filters'] ?? [] as $filter) {
                    $classify(
                        array_values(array_filter($filter['calibrations'] ?? [], fn($c) => !$isFlat($c) && !$isLight($c))),
                        'filter',
                        (int)$session['id'],
                        array_values(array_filter($filter['lights'] ?? [], $isLight)),
                        array_values(array_filter($filter['calibrations'] ?? [], $isFlat))
                    );
                }
            }
            $setupLights = array_merge($setupLights, $panelLights);
            $setupFlats = array_merge($setupFlats, $panelFlats);
            $classify(
                array_values(array_filter($panel['calibrations'] ?? [], fn($c) => !$isFlat($c) && !$isLight($c))),
                'panel',
                (int)$panel['id'],
                $panelLights,
                $panelFlats
            );
        }
        $classify(
            array_values(array_filter($setup['calibrations'] ?? [], fn($c) => !$isFlat($c) && !$isLight($c))),
            'setup',
            (int)$setup['id'],
            $setupLights,
            $setupFlats
        );
    }
    foreach ($tree['project_links'] ?? [] as $c) {
        if (strtoupper((string)($c['imgtype'] ?? '')) !== 'DARK' || !$hasExp($c)) {
            continue;
        }
        $allLights = [];
        $allFlats = [];
        foreach ($tree['setups'] ?? [] as $setup) {
            foreach ($setup['panels'] ?? [] as $panel) {
                foreach ($panel['sessions'] ?? [] as $session) {
                    [$sl, $sf] = $filterRows($session);
                    $allLights = array_merge($allLights, $sl);
                    $allFlats = array_merge($allFlats, $sf);
                }
            }
        }
        $servesFlats = false;
        foreach ($allFlats as $f) {
            if ($hasExp($f) && matchDark($f, $c, $tols) !== 'red') {
                $servesFlats = true;
                break;
            }
        }
        if (!$servesFlats) {
            continue;
        }
        $servesLights = false;
        foreach ($allLights as $li) {
            if ($hasExp($li) && matchDark($li, $c, $tols) !== 'red') {
                $servesLights = true;
                break;
            }
        }
        if ($servesLights) {
            continue;
        }
        $fid = (int)($c['file_id'] ?? $c['id'] ?? 0);
        if ($fid > 0) {
            $roles[$fid . ':project:0'] = 'darkflat';
        }
    }
    return $roles;
}

/**
 * Diagnostics for every LINKED light in the tree. Returns [file_id => [...]].
 * Pending (hypothetical) rows are ignored, both as lights and as candidates.
 * Candidate pool per light = calibrations linked at filter/session/panel/setup/project
 * levels along its own chain (masters shadow subs per level+type).
 */
function diagnoseProjectTree(array $tree, array $tols, array $filterAliases = []): array
{
    $out = [];
    // Darkflat rows never serve lights: partitioning first also fixes master
    // shadowing (a master darkflat no longer hides matching sub darks).
    $darkRoles = indexDarkRoles($tree, $tols);
    $roleOf = function (array $c) use ($darkRoles): string {
        $fid = (int)($c['file_id'] ?? $c['id'] ?? 0);
        $level = (string)($c['level'] ?? '');
        $node = (int)($c['node_id'] ?? 0);
        if ($fid <= 0 || $level === '') {
            return 'dark';
        }
        return $darkRoles[$fid . ':' . $level . ':' . $node] ?? 'dark';
    };
    $projectCals = array_values(array_filter(
        $tree['project_links'] ?? [],
        fn($c) => in_array(strtoupper((string)$c['imgtype']), ['DARK', 'FLAT', 'BIAS'], true)
    ));
    $tolRot = projectNumPrefix((string)($tols['tol_rot'] ?? '3deg'), 3.0);
    foreach ($tree['setups'] as $setup) {
        $setupCals = array_merge($projectCals, $setup['calibrations']);
        foreach ($setup['panels'] as $panel) {
            $panelCals = array_merge($setupCals, $panel['calibrations']);
            $panelRot = $panel['rot_mean'] ?? null;
            foreach ($panel['sessions'] as $session) {
                $sid = (int)$session['id'];
                $sessionCals = array_merge($panelCals, $session['calibrations']);
                foreach ($session['filters'] as $filter) {
                    $pool = array_merge($sessionCals, $filter['calibrations']);
                    $pool = array_values(array_filter(
                        $pool,
                        // Session scope: a scoped link above session level only
                        // applies to lights of the listed sessions.
                        fn($c) => empty($c['pending']) && !empty($c['enabled'])
                            && (empty($c['scope_sessions']) || in_array($sid, $c['scope_sessions'], true))
                    ));
                    foreach ($filter['lights'] as $light) {
                        if (!empty($light['pending']) || empty($light['enabled'])) {
                            continue;
                        }
                        $darks = array_values(array_filter($pool, fn($c) => strtoupper((string)$c['imgtype']) === 'DARK' && $roleOf($c) !== 'darkflat'));
                        $flats = array_values(array_filter($pool, fn($c) => strtoupper((string)$c['imgtype']) === 'FLAT'));
                        $biases = array_values(array_filter($pool, fn($c) => strtoupper((string)$c['imgtype']) === 'BIAS'));
                        [$flatStatus, $flatRot] = flatStatusWithRot($light, $flats, $panelRot, $filterAliases, $tolRot);
                        $out[(int)$light['file_id']] = [
                            'dark' => bestCalibStatus($darks, fn($c) => matchDark($light, $c, $tols)),
                            'flat' => $flatStatus,
                            'flat_rot' => $flatRot,
                            'bias' => bestCalibStatus($biases, fn($c) => matchBias($light, $c)),
                        ];
                    }
                }
            }
        }
    }
    return $out;
}

/**
 * Bias + dark coverage for every ENABLED linked flat:
 * [file_id => ['bias' => green|yellow|red, 'dark' => green|yellow|red]].
 * Darks and darkflats are equivalent here (same pool, matched by exposure
 * like lights do); bias is matched by binning/gain. Each flat is checked
 * against the rows along its own chain (masters shadow subs, same as
 * lights); above session level there is no session context, so scope is
 * ignored for them (lenient). No candidate at all => red, mirroring the
 * light convention (missing candidate, not broken data: uncovered flats
 * still export, WBPP decides).
 */
function diagnoseFlatCoverage(array $tree, array $tols, array $darkRoles): array
{
    $out = [];
    $isFlatEnabled = fn($c) => strtoupper((string)($c['imgtype'] ?? '')) === 'FLAT'
        && empty($c['pending']) && !empty($c['enabled']);
    $projectCals = array_values(array_filter(
        $tree['project_links'] ?? [],
        fn($c) => in_array(strtoupper((string)$c['imgtype']), ['DARK', 'FLAT', 'BIAS'], true)
    ));
    $inScope = function (array $c, ?int $sid): bool {
        if (empty($c['pending']) && !empty($c['enabled'])
            && ($sid === null || empty($c['scope_sessions']) || in_array($sid, $c['scope_sessions'], true))) {
            return true;
        }
        return false;
    };
    $checkFlat = function (array $flat, array $pool, ?int $sid) use (&$out, $tols, $inScope): void {
        $darks = array_values(array_filter(
            $pool,
            fn($c) => strtoupper((string)$c['imgtype']) === 'DARK' && $inScope($c, $sid)
        ));
        $biases = array_values(array_filter(
            $pool,
            fn($c) => strtoupper((string)$c['imgtype']) === 'BIAS' && $inScope($c, $sid)
        ));
        $out[(int)$flat['file_id']] = [
            'bias' => bestCalibStatus($biases, fn($c) => matchBias($flat, $c)),
            'dark' => bestCalibStatus($darks, fn($c) => matchDark($flat, $c, $tols)),
        ];
    };
    foreach ($tree['setups'] ?? [] as $setup) {
        $setupCals = array_merge($projectCals, $setup['calibrations'] ?? []);
        foreach ($setupCals as $flat) {
            if ($isFlatEnabled($flat)) {
                $checkFlat($flat, $setupCals, null);
            }
        }
        foreach ($setup['panels'] ?? [] as $panel) {
            $panelCals = array_merge($setupCals, $panel['calibrations'] ?? []);
            foreach ($panel['calibrations'] ?? [] as $flat) {
                if ($isFlatEnabled($flat)) {
                    $checkFlat($flat, $panelCals, null);
                }
            }
            foreach ($panel['sessions'] ?? [] as $session) {
                $sid = (int)$session['id'];
                $sessionCals = array_merge($panelCals, $session['calibrations'] ?? []);
                foreach ($session['calibrations'] ?? [] as $flat) {
                    if ($isFlatEnabled($flat)) {
                        $checkFlat($flat, $sessionCals, $sid);
                    }
                }
                foreach ($session['filters'] ?? [] as $filter) {
                    $pool = array_merge($sessionCals, $filter['calibrations'] ?? []);
                    foreach ($filter['calibrations'] ?? [] as $flat) {
                        if ($isFlatEnabled($flat)) {
                            $checkFlat($flat, $pool, $sid);
                        }
                    }
                }
            }
        }
    }
    return $out;
}

/**
 * All file ids linked in a project tree (lights + calibrations at every
 * level, including project_links). Pending rows are skipped unless
 * $includePending is true. Sorted, unique integers.
 */
function getProjectTreeFileIds(array $tree, bool $includePending = false): array
{
    $ids = [];
    $collect = function (array $rows) use (&$ids, $includePending): void {
        foreach ($rows as $r) {
            if (!empty($r['pending']) && !$includePending) {
                continue;
            }
            $fid = (int)($r['file_id'] ?? $r['id'] ?? 0);
            if ($fid > 0) {
                $ids[$fid] = true;
            }
        }
    };
    $collect($tree['project_links'] ?? []);
    foreach ($tree['setups'] ?? [] as $setup) {
        $collect($setup['calibrations'] ?? []);
        foreach ($setup['panels'] ?? [] as $panel) {
            $collect($panel['calibrations'] ?? []);
            foreach ($panel['sessions'] ?? [] as $session) {
                $collect($session['calibrations'] ?? []);
                foreach ($session['filters'] ?? [] as $filter) {
                    $collect($filter['lights'] ?? []);
                    $collect($filter['calibrations'] ?? []);
                }
            }
        }
    }
    $out = array_keys($ids);
    sort($out);
    return $out;
}

/**
 * Duplicate links index: file ids linked at more than one level, mapped to
 * human labels (setup S{n}, panel P{n}, session {night}, ...). Used for the
 * ⧉×n warning marker. Pending rows are ignored.
 */
function indexDuplicateLinks(array $tree): array
{
    $byFile = [];
    $add = function (array $rows, string $label) use (&$byFile): void {
        foreach ($rows as $r) {
            if (!empty($r['pending'])) {
                continue;
            }
            $fid = (int)($r['file_id'] ?? $r['id'] ?? 0);
            if ($fid > 0) {
                $byFile[$fid][] = $label;
            }
        }
    };
    $add($tree['project_links'] ?? [], 'project');
    foreach ($tree['setups'] ?? [] as $setup) {
        $slabel = 'setup S' . (int)($setup['setup_no'] ?? $setup['id']);
        $add($setup['calibrations'] ?? [], $slabel);
        foreach ($setup['panels'] ?? [] as $panel) {
            $plabel = 'P' . (int)($panel['panel_no'] ?? $panel['id']);
            $add($panel['calibrations'] ?? [], 'panel ' . $plabel);
            foreach ($panel['sessions'] ?? [] as $session) {
                $night = (string)($session['astro_night'] ?? '');
                $add($session['calibrations'] ?? [], 'session ' . $night);
                foreach ($session['filters'] ?? [] as $filter) {
                    $add($filter['lights'] ?? [], 'session ' . $night);
                    $fname = trim((string)($filter['name'] ?? ''));
                    $add($filter['calibrations'] ?? [], 'filtro ' . ($fname !== '' ? $fname : '—') . ' · ' . $night);
                }
            }
        }
    }
    $out = [];
    foreach ($byFile as $fid => $labels) {
        if (count($labels) > 1) {
            $out[$fid] = array_values(array_unique($labels));
        }
    }
    return $out;
}

/**
 * Display grouping for calibration rows: flats by filter, darks by exposure
 * (same tolerance rule as matching) and temperature, bias all together.
 * Returns ordered [['kind' => flat|dark|bias|other, 'label' => ?string,
 * 'rows' => [...]]] for direct rendering; single-group nodes render without
 * a header. Pure display: matching and diagnostics are unaffected.
 */
function groupCalibrations(array $cals, string $tolExp, string $tolTemp, array $filterAliases = [], array $darkRoles = [], ?string $level = null, ?int $node = null): array
{
    $flats = $darks = $bias = $other = [];
    foreach ($cals as $c) {
        switch (strtoupper((string)($c['imgtype'] ?? ''))) {
            case 'FLAT':
                $flats[] = $c;
                break;
            case 'DARK':
                $darks[] = $c;
                break;
            case 'BIAS':
                $bias[] = $c;
                break;
            default:
                $other[] = $c;
        }
    }
    $groups = [];
    if (!empty($flats)) {
        $byFilter = [];
        foreach ($flats as $f) {
            $canon = canonFilterName($filterAliases, (string)($f['filter'] ?? ''));
            $key = mb_strtolower($canon);
            if (!isset($byFilter[$key])) {
                $byFilter[$key] = ['label' => $canon !== '' ? $canon : '—', 'rows' => []];
            }
            $byFilter[$key]['rows'][] = $f;
        }
        uksort($byFilter, fn($a, $b) => strcasecmp($a === '' ? '—' : $a, $b === '' ? '—' : $b));
        foreach ($byFilter as $g) {
            $groups[] = ['kind' => 'flat'] + $g;
        }
    }
    if (!empty($darks)) {
        // Dark vs dark-flat: same exposure clustering, separate groups so
        // short calibration darks never hide among light darks (and vice
        // versa). Without level/node context every dark stays a dark.
        $darkSubs = [];
        $darkFlatSubs = [];
        foreach ($darks as $d) {
            $fid = (int)($d['file_id'] ?? $d['id'] ?? 0);
            $role = ($level !== null && $node !== null && $fid > 0)
                ? ($darkRoles[$fid . ':' . $level . ':' . $node] ?? 'dark')
                : 'dark';
            if ($role === 'darkflat') {
                $darkFlatSubs[] = $d;
            } else {
                $darkSubs[] = $d;
            }
        }
        foreach (['dark' => $darkSubs, 'darkflat' => $darkFlatSubs] as $kind => $rows) {
            if (empty($rows)) {
                continue;
            }
            foreach (clusterExposures($rows, $tolExp, 0.01) as $eg) {
                foreach (bucketCalibTemps($eg['lights'], $tolTemp) as $tb) {
                    $medExp = projectMedian(array_column($tb['rows'], 'exptime'));
                    $medTemp = projectMedian(array_column($tb['rows'], 'ccd_temp'));
                    $groups[] = [
                        'kind' => $kind,
                        'label' => darkCalGroupLabel($medExp, $medTemp),
                        'rep_exp' => $medExp,
                        'rep_temp' => $medTemp,
                        'rows' => $tb['rows'],
                    ];
                }
            }
        }
    }
    if (!empty($bias)) {
        $groups[] = ['kind' => 'bias', 'label' => null, 'rows' => array_values($bias)];
    }
    if (!empty($other)) {
        $byType = [];
        foreach ($other as $c) {
            $t = strtoupper((string)($c['imgtype'] ?? ''));
            $byType[$t]['label'] = $t !== '' ? $t : '—';
            $byType[$t]['rows'][] = $c;
        }
        ksort($byType);
        foreach ($byType as $g) {
            $groups[] = ['kind' => 'other'] + $g;
        }
    }
    return $groups;
}

/**
 * Bucket rows by ccd_temp with an absolute tolerance (anchor clustering like
 * clusterExposures, but absolute degrees). Null temps share one group.
 * Returns [['temp' => ?float, 'rows' => [...]]] sorted ascending.
 */
function bucketCalibTemps(array $rows, string $tolTempRaw): array
{
    $tol = parseTolAbs($tolTempRaw, 2.0);
    $withNull = [];
    $withVal = [];
    foreach ($rows as $r) {
        if ($r['ccd_temp'] === null || $r['ccd_temp'] === '') {
            $withNull[] = $r;
        } else {
            $withVal[] = $r;
        }
    }
    usort($withVal, fn($a, $b) => (float)$a['ccd_temp'] <=> (float)$b['ccd_temp']);
    $groups = [];
    $anchor = null;
    foreach ($withVal as $r) {
        $v = (float)$r['ccd_temp'];
        if ($anchor === null || abs($v - $anchor) > $tol + 1e-9) {
            $groups[] = ['temp' => $v, 'rows' => []];
            $anchor = $v;
        }
        $groups[count($groups) - 1]['rows'][] = $r;
    }
    if (!empty($withNull)) {
        $groups[] = ['temp' => null, 'rows' => $withNull];
    }
    return $groups;
}

function darkCalGroupLabel($medExp, $medTemp = null): string
{
    // Header shows the exposure/temperature pair like light groups
    // (e.g. "[DARK] 300 s, 0 °C (5)"); temperature lives on only when known.
    $label = fmtExpShort($medExp);
    if ($medTemp !== null && $medTemp !== '') {
        $label .= ', ' . repTempDisplay($medTemp);
    }
    return $label;
}

/**
 * Shared representative-value rules (tree labels, export folders, WBPP
 * keyword values): medians, never anchors. Exposure: integer when integral,
 * else 1 decimal. Temperature: always integer, -0 normalized to 0.
 */
function repTempInt($medTemp): ?int
{
    if ($medTemp === null || $medTemp === '') {
        return null;
    }
    $t = (int)round((float)$medTemp);
    return $t === 0 ? 0 : $t;
}

function repTempDisplay($medTemp): string
{
    $t = repTempInt($medTemp);
    return $t === null ? '—' : $t . ' °C';
}

/**
 * Temperature range display for merged (non-temp-split) groups: "−10…+20 °C",
 * collapsing to the single value when min and max round equally. Exposes
 * bimodal distributions (e.g. cooler failure one night) that a median hides.
 */
function repTempRange($minTemp, $maxTemp): string
{
    $a = repTempInt($minTemp);
    $b = repTempInt($maxTemp);
    if ($a === null || $b === null) {
        return '—';
    }
    if ($a === $b) {
        return $a . ' °C';
    }
    return $a . '…' . $b . ' °C';
}

function repExpToken($medExp): string
{
    if ($medExp === null || $medExp === '') {
        return 'EXPS_X';
    }
    $r = round((float)$medExp, 1);
    $s = $r == floor($r) ? (string)(int)$r : rtrim(rtrim(number_format($r, 2, '.', ''), '0'), '.');
    return 'EXPS_' . $s;
}

function repTempToken($medTemp): ?string
{
    $t = repTempInt($medTemp);
    if ($t === null) {
        return null;
    }
    return 'TEMPC_' . ($t < 0 ? 'm' . abs($t) : (string)$t);
}

/**
 * One-line group header: "[DARK] 300 s, 0.0 °C (5)". No middle-dot
 * separators; bias/other groups show type and count only.
 */
function calGroupTitle(array $g): string
{
    $type = $g['kind'] === 'other' ? (string)($g['label'] ?? '') : strtoupper((string)$g['kind']);
    $rest = ($g['kind'] === 'other' || ($g['label'] ?? null) === null) ? '' : ' ' . $g['label'];
    return '[' . $type . ']' . $rest . ' (' . count($g['rows']) . ')';
}

/**
 * Short session label with progressive number: "N3 · 2024-05-01" (night only
 * when session_no is missing, e.g. partially migrated rows). Sessions use N
 * so they can never be confused with setup S numbers.
 */
function sessionShortLabel(array $session): string
{
    $night = (string)($session['astro_night'] ?? '');
    if (!isset($session['session_no']) || $session['session_no'] === null) {
        return $night;
    }
    return 'N' . (int)$session['session_no'] . ' · ' . $night;
}

/**
 * Median of a numeric list, ignoring null/empty values. Null when empty.
 */
function projectMedian(array $values): ?float
{
    $nums = [];
    foreach ($values as $v) {
        if ($v !== null && $v !== '') {
            $nums[] = (float)$v;
        }
    }
    if (empty($nums)) {
        return null;
    }
    sort($nums);
    $n = count($nums);
    $mid = intdiv($n, 2);
    return $n % 2 === 1 ? $nums[$mid] : ($nums[$mid - 1] + $nums[$mid]) / 2.0;
}

/**
 * Numeric values of a list, ignoring null/empty entries.
 */
function projectNums(array $values): array
{
    $nums = [];
    foreach ($values as $v) {
        if ($v !== null && $v !== '') {
            $nums[] = (float)$v;
        }
    }
    return $nums;
}

/**
 * Min/max of a numeric list, ignoring null/empty values. Null when empty.
 */
function projectMin(array $values): ?float
{
    $nums = projectNums($values);
    return empty($nums) ? null : min($nums);
}

function projectMax(array $values): ?float
{
    $nums = projectNums($values);
    return empty($nums) ? null : max($nums);
}

/**
 * Cross-setup tile clustering for multi-setup mosaics: panels of DIFFERENT
 * setups sharing sky position/rotation/FoV become one tile. Same rules as
 * panel matching (projectFindPanel): angular separation within tol_pos,
 * rotation within tol_rot, FoV within tol_fov. Panels without coordinates
 * never merge; same-setup panels never merge (they were kept apart at
 * assignment time by design).
 *
 * $panels: flat list of ['id','setup_id','setup_no','panel_no','ra','dec',
 * 'rot_mean','fov_w','fov_h','label']. $posTols: raw tolerance strings
 * (tol_pos_arcmin, tol_pos_fovfrac, tol_rot, tol_fov).
 *
 * Returns ['tiles' => [['panels' => [...], 'multi' => bool], ...],
 * 'panelTile' => [panelId => tileIdx]]. Tile order (T1..) follows first
 * appearance, so numbering is stable for a given tree.
 */
function panelFovMinDeg(?array $p): ?float
{
    if ($p === null) {
        return null;
    }
    if (($p['fov_w'] ?? null) !== null && ($p['fov_h'] ?? null) !== null
        && (float)$p['fov_w'] > 0 && (float)$p['fov_h'] > 0) {
        return min((float)$p['fov_w'], (float)$p['fov_h']) / 60.0;
    }
    return null;
}

/**
 * Parsed position/rotation/FoV tolerances shared by panel matching and tile
 * clustering. Returns [arcminTolDeg, fovFrac, tolRotDeg, tolFovFrac].
 */
function parsePosTols(array $posTols): array
{
    return [
        max(projectNumPrefix((string)($posTols['tol_pos_arcmin'] ?? '5'), 5.0) / 60.0, 1e-6),
        projectNumPrefix((string)($posTols['tol_pos_fovfrac'] ?? '0.2'), 0.2),
        projectNumPrefix((string)($posTols['tol_rot'] ?? '3deg'), 3.0),
        projectNumPrefix((string)($posTols['tol_fov'] ?? '10%'), 10.0) / 100.0,
    ];
}

/**
 * Why two panels of DIFFERENT setups do not share a tile. Null when they
 * match (same rules as projectFindPanel). Same-setup pairs never merge by
 * design; panels without coordinates never merge either.
 *
 * Returns null or ['code' => 'same_setup|coords|pos|rot|fov',
 * 'actual' => float|null, 'limit' => float|null] with degrees (pos/rot) or
 * fractions (fov) for display.
 */
function crossPanelMismatch(array $a, array $b, array $posTols): ?array
{
    if ((int)($a['setup_id'] ?? 0) === (int)($b['setup_id'] ?? 0)) {
        return ['code' => 'same_setup', 'actual' => null, 'limit' => null];
    }
    if (($a['ra'] ?? null) === null || ($a['dec'] ?? null) === null
        || ($b['ra'] ?? null) === null || ($b['dec'] ?? null) === null) {
        return ['code' => 'coords', 'actual' => null, 'limit' => null];
    }
    [$arcminTol, $fovFrac, $tolRot, $tolFov] = parsePosTols($posTols);
    $knownFovs = [];
    foreach ([panelFovMinDeg($a), panelFovMinDeg($b)] as $f) {
        if ($f !== null) {
            $knownFovs[] = $f;
        }
    }
    $tolPos = $arcminTol;
    if (!empty($knownFovs)) {
        $tolPos = max($tolPos, $fovFrac * min($knownFovs));
    }
    $sep = projectHaversine((float)$a['ra'], (float)$a['dec'], (float)$b['ra'], (float)$b['dec']);
    if ($sep > $tolPos) {
        return ['code' => 'pos', 'actual' => $sep, 'limit' => $tolPos];
    }
    [$dist, $unknown] = projectRotDist($a['rot_mean'] ?? null, $b['rot_mean'] ?? null);
    if (!$unknown && $dist > $tolRot) {
        return ['code' => 'rot', 'actual' => $dist, 'limit' => $tolRot];
    }
    if (count($knownFovs) === 2 && max($knownFovs) > 0
        && abs($knownFovs[0] - $knownFovs[1]) / max($knownFovs) > $tolFov) {
        return [
            'code' => 'fov',
            'actual' => abs($knownFovs[0] - $knownFovs[1]) / max($knownFovs),
            'limit' => $tolFov,
        ];
    }
    return null;
}

function clusterCrossSetupPanels(array $panels, array $posTols): array
{
    $matches = function (array $a, array $b) use ($posTols): bool {
        return crossPanelMismatch($a, $b, $posTols) === null;
    };
    $tiles = [];
    $panelTile = [];
    foreach ($panels as $p) {
        $placed = false;
        foreach ($tiles as $ti => $t) {
            if ($matches($t['rep'], $p)) {
                $tiles[$ti]['panels'][] = $p;
                $panelTile[(int)$p['id']] = $ti;
                $placed = true;
                break;
            }
        }
        if (!$placed) {
            $panelTile[(int)$p['id']] = count($tiles);
            $tiles[] = ['rep' => $p, 'panels' => [$p]];
        }
    }
    foreach ($tiles as $ti => $t) {
        $setups = [];
        foreach ($t['panels'] as $p) {
            $setups[(int)$p['setup_no']] = true;
        }
        $tiles[$ti]['multi'] = count($setups) > 1;
        unset($tiles[$ti]['rep']);
    }
    return ['tiles' => $tiles, 'panelTile' => $panelTile];
}

/**
 * Unmerged cross-setup panel pairs with the blocking reason: for every pair
 * of panels from different setups that did NOT land in the same tile, the
 * first failing criterion from crossPanelMismatch(). Powers the "why not
 * merged" hint in the grouping UI. Same-setup pairs are skipped (they never
 * merge by design, no explanation needed).
 *
 * Returns [['a' => panel, 'b' => panel, 'reason' => [...]], ...] in panel order.
 */
function tileMergeHints(array $panels, array $posTols, array $panelTile): array
{
    $out = [];
    $n = count($panels);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $a = $panels[$i];
            $b = $panels[$j];
            if ((int)($a['setup_id'] ?? 0) === (int)($b['setup_id'] ?? 0)) {
                continue;
            }
            $ta = $panelTile[(int)($a['id'] ?? 0)] ?? null;
            $tb = $panelTile[(int)($b['id'] ?? 0)] ?? null;
            if ($ta !== null && $ta === $tb) {
                continue;
            }
            $reason = crossPanelMismatch($a, $b, $posTols);
            if ($reason === null) {
                continue;
            }
            if ($reason['code'] === 'same_setup') {
                continue;
            }
            $out[] = ['a' => $a, 'b' => $b, 'reason' => $reason];
        }
    }
    return $out;
}

/**
 * Localized one-line explanation of a tileMergeHints() reason. Position in
 * arcminutes, rotation in degrees, FoV as percent difference.
 */
function tileHintReasonText(array $reason): string
{
    switch ($reason['code'] ?? '') {
        case 'pos':
            return __('projects_tiles_why_pos', [
                'actual' => number_format((float)($reason['actual'] ?? 0) * 60, 1),
                'limit' => number_format((float)($reason['limit'] ?? 0) * 60, 1),
            ]);
        case 'rot':
            return __('projects_tiles_why_rot', [
                'actual' => number_format((float)($reason['actual'] ?? 0), 1),
                'limit' => number_format((float)($reason['limit'] ?? 0), 1),
            ]);
        case 'fov':
            return __('projects_tiles_why_fov', [
                'actual' => number_format((float)($reason['actual'] ?? 0) * 100, 1),
                'limit' => number_format((float)($reason['limit'] ?? 0) * 100, 1),
            ]);
        case 'coords':
            return __('projects_tiles_why_coords');
        default:
            return '';
    }
}

/**
 * Flat panel list of a project tree with setup context, in tree order.
 * Shared by tile clustering here and the export builder.
 */
function flatTreePanels(array $tree): array
{
    $out = [];
    foreach ($tree['setups'] ?? [] as $setup) {
        foreach ($setup['panels'] ?? [] as $panel) {
            $out[] = [
                'id' => (int)$panel['id'],
                'setup_id' => (int)$setup['id'],
                'setup_no' => (int)($setup['setup_no'] ?? $setup['id']),
                'panel_no' => (int)($panel['panel_no'] ?? $panel['id']),
                'ra' => $panel['ra'] ?? null,
                'dec' => $panel['dec'] ?? null,
                'rot_mean' => $panel['rot_mean'] ?? null,
                'fov_w' => $panel['fov_w'] ?? null,
                'fov_h' => $panel['fov_h'] ?? null,
                'label_object' => $panel['label_object'] ?? null,
            ];
        }
    }
    return $out;
}

/**
 * Integration groups: enabled linked LIGHTS sharing the active split levels,
 * transversal to sessions (i.e. stackable sets, multi-night by design).
 * Returns stable-sorted groups with covered nights and total exposure.
 *
 * $grouping (from getProjectGrouping(), defaults = historical behaviour):
 * split_setup/split_panel/split_filter ON = separate pools per level;
 * split_exposure ON = clusterExposures() with exp_tol (else one bucket);
 * split_temp ON = absolute-°C buckets with temp_tol (else median display only).
 * NULL/empty exp_tol/temp_tol inherit $tolExpRaw/$tolTempRaw.
 * merge_tiles ON (with split_setup OFF and split_panel ON) pools panels of
 * different setups sharing sky position/rotation/FoV into one tile
 * (clusterCrossSetupPanels with $posTols); session/night never splits and
 * binning/gain ride inside the setup fingerprint.
 *
 * Thresholds resolve per exposure bucket on the representative coordinates
 * (first light's setup/panel; filter/exptime normalized to NULL when their
 * split is OFF and shared across temp buckets and tiles), with fallback to
 * the legacy full key so pre-existing rows keep working. Merged
 * setups/panels/tiles therefore share thresholds by design.
 * Each light carries 'auto_off' (fails stored thresholds); counts, exposure
 * and medians cover effectively included files (enabled AND passing).
 */
function getIntegrationGroups(array $tree, string $tolExpRaw, array $thresholdMap = [], string $tolTempRaw = '2C', ?array $grouping = null, ?array $posTols = null, array $filterAliases = []): array
{
    $g = $grouping ?? (function_exists('defaultProjectGrouping') ? defaultProjectGrouping() : [
        'split_setup' => true, 'split_panel' => true, 'split_filter' => true,
        'split_exposure' => true, 'split_temp' => false, 'merge_tiles' => false,
        'exp_tol' => null, 'temp_tol' => null,
    ]);
    $expTolEff = trim((string)($g['exp_tol'] ?? ''));
    if ($expTolEff === '') {
        $expTolEff = $tolExpRaw;
    }
    $tempTolEff = trim((string)($g['temp_tol'] ?? ''));
    if ($tempTolEff === '') {
        $tempTolEff = $tolTempRaw;
    }
    // Tile mode: split_setup OFF + split_panel ON + merge_tiles ON pools
    // panels of different setups sharing sky position/rotation/FoV.
    $tileMode = function_exists('groupingTilesEffective') ? groupingTilesEffective($g)
        : (!empty($g['merge_tiles']) && empty($g['split_setup']) && !empty($g['split_panel']));
    $panelTile = [];
    $tileLabels = [];
    if ($tileMode) {
        $flatPanels = flatTreePanels($tree);
        $clust = clusterCrossSetupPanels($flatPanels, $posTols ?? []);
        $panelTile = $clust['panelTile'];
        foreach ($clust['tiles'] as $ti => $t) {
            $rep = $t['panels'][0];
            $coords = ($rep['ra'] !== null && $rep['dec'] !== null)
                ? number_format((float)$rep['ra'], 3) . ' / ' . number_format((float)$rep['dec'], 3) : '?';
            $snos = [];
            foreach ($t['panels'] as $p) {
                $snos[(int)$p['setup_no']] = true;
            }
            ksort($snos);
            $tileLabels[$ti] = [
                'no' => $ti + 1,
                'setups' => array_keys($snos),
                'label' => $coords . (($rep['label_object'] ?? null) !== null && $rep['label_object'] !== ''
                    ? ' ' . $rep['label_object'] : ''),
                'multi' => $t['multi'],
            ];
        }
    }
    $pools = [];
    foreach ($tree['setups'] ?? [] as $setup) {
        $setupLabel = ($setup['label'] !== null && $setup['label'] !== '')
            ? (string)$setup['label'] : substr((string)$setup['fingerprint'], 0, 48);
        foreach ($setup['panels'] as $panel) {
            $coords = ($panel['ra'] !== null && $panel['dec'] !== null)
                ? number_format((float)$panel['ra'], 3) . ' / ' . number_format((float)$panel['dec'], 3) : '?';
            $panelLabel = 'P' . (int)($panel['panel_no'] ?? $panel['id']) . ' (' . $coords . ')';
            if ($panel['label_object'] !== null && $panel['label_object'] !== '') {
                $panelLabel .= ' ' . $panel['label_object'];
            }
            $tileIdx = $tileMode ? ($panelTile[(int)$panel['id']] ?? null) : null;
            foreach ($panel['sessions'] as $session) {
                foreach ($session['filters'] as $filter) {
                    foreach ($filter['lights'] as $li) {
                        if (!empty($li['pending'])) {
                            continue;
                        }
                        if (strtoupper((string)($li['imgtype'] ?? '')) !== 'LIGHT') {
                            continue;
                        }
                        // Manually disabled files are excluded from integration
                        // groups entirely; threshold-rejected ones stay grey.
                        if (empty($li['enabled'])) {
                            continue;
                        }
                        $fname = trim((string)($li['filter_name'] ?? $li['filter'] ?? ''));
                        $canon = canonFilterName($filterAliases, $fname);
                        $key = (!empty($g['split_setup']) ? (int)$setup['id'] : '*')
                            . '|' . ($tileMode && $tileIdx !== null ? 'T' . $tileIdx
                                : (!empty($g['split_panel']) ? (int)$panel['id'] : '*'))
                            . '|' . (!empty($g['split_filter']) ? strtoupper($canon) : '*');
                        if (!isset($pools[$key])) {
                            $pools[$key] = [
                                'setup_id' => (int)$setup['id'],
                                'setup_no' => (int)($setup['setup_no'] ?? $setup['id']),
                                'setup_label' => $setupLabel,
                                'panel_id' => (int)$panel['id'],
                                'panel_no' => (int)($panel['panel_no'] ?? $panel['id']),
                                'panel_label' => $panelLabel,
                                'filter' => $canon,
                                'filter_raw' => $fname,
                                'merged_setup' => empty($g['split_setup']),
                                'merged_panel' => empty($g['split_panel']),
                                'merged_filter' => empty($g['split_filter']),
                                'tile' => $tileIdx !== null ? ($tileLabels[$tileIdx] ?? null) : null,
                                'lights' => [],
                            ];
                        }
                        if ($tileIdx !== null && $pools[$key]['tile'] !== null) {
                            $sn = (int)($setup['setup_no'] ?? $setup['id']);
                            if (!in_array($sn, $pools[$key]['tile']['setups'], true)) {
                                $pools[$key]['tile']['setups'][] = $sn;
                                sort($pools[$key]['tile']['setups']);
                            }
                        }
                        $li['night'] = (string)$session['astro_night'];
                        $li['link_key'] = (int)$li['file_id'] . ':' . ($li['level'] ?? 'filter') . ':' . (int)$session['id'];
                        $pools[$key]['lights'][] = $li;
                    }
                }
            }
        }
    }
    $groups = [];
    $pushGroup = function (array $pool, $exptime, array $bucketLights, array $tols, string $tkey) use (&$groups): void {
        $egLights = [];
        $effective = [];
        foreach ($bucketLights as $li) {
            // Manually disabled files are excluded from integration groups entirely.
            if (empty($li['enabled'])) {
                continue;
            }
            $li['auto_off'] = lightThresholdRejected($li, $tols);
            $egLights[] = $li;
            if (empty($li['auto_off'])) {
                $effective[] = $li;
            }
        }
        $nights = [];
        $exp = 0.0;
        foreach ($effective as $li) {
            $exp += (float)($li['exptime'] ?? 0);
            if (isset($li['night'])) {
                $nights[$li['night']] = true;
            }
        }
        $nightList = array_keys($nights);
        sort($nightList);
        $groups[] = [
            'setup_id' => $pool['setup_id'],
            'setup_no' => $pool['setup_no'],
            'panel_id' => $pool['panel_id'],
            'tkey' => $tkey,
            'thresholds' => $tols,
            'setup_label' => $pool['setup_label'],
            'panel_label' => $pool['panel_label'],
            'panel_no' => $pool['panel_no'],
            'filter' => $pool['filter'],
            'merged_setup' => $pool['merged_setup'],
            'merged_panel' => $pool['merged_panel'],
            'merged_filter' => $pool['merged_filter'],
            'tile' => $pool['tile'] ?? null,
            'exptime' => $exptime,
            'rep_exp' => projectMedian(array_column($egLights, 'exptime')),
            'rep_temp' => projectMedian(array_column($egLights, 'ccd_temp')),
            'temp_min' => projectMin(array_column($egLights, 'ccd_temp')),
            'temp_max' => projectMax(array_column($egLights, 'ccd_temp')),
            'nights' => $nightList,
            'lights' => $egLights,
            'count' => count($effective),
            'exposure' => $exp,
            'medians' => [
                'hfr' => projectMedian(array_column($effective, 'hfr')),
                'fwhm' => projectMedian(array_column($effective, 'fwhm')),
                'hfr_sd' => projectMedian(array_column($effective, 'hfr_sd')),
                'eccentricity' => projectMedian(array_column($effective, 'eccentricity')),
                'star_count' => projectMedian(array_column($effective, 'star_count')),
                'snr_weight' => projectMedian(array_column($effective, 'snr_weight')),
                'psf_signal' => projectMedian(array_column($effective, 'psf_signal')),
            ],
        ];
    };
    foreach ($pools as $pool) {
        $expBuckets = !empty($g['split_exposure'])
            ? clusterExposures($pool['lights'], $expTolEff)
            : [['exptime' => null, 'lights' => $pool['lights']]];
        foreach ($expBuckets as $eg) {
            // Thresholds are shared across temp buckets (never keyed by temp).
            // Filter component is canonical; the legacy key keeps the raw
            // first-seen name so pre-alias threshold rows still resolve.
            $repFilter = $pool['filter'] !== '' ? $pool['filter'] : null;
            $rawFilter = ($pool['filter_raw'] ?? $pool['filter']) !== '' ? ($pool['filter_raw'] ?? $pool['filter']) : null;
            $tkey = groupThresholdKey(
                $pool['setup_id'],
                $pool['panel_id'],
                empty($g['split_filter']) ? null : $repFilter,
                !empty($g['split_exposure']) ? $eg['exptime'] : null
            );
            $legacyKey = groupThresholdKey(
                $pool['setup_id'],
                $pool['panel_id'],
                $rawFilter,
                $eg['exptime']
            );
            $tols = $thresholdMap[$tkey] ?? $thresholdMap[$legacyKey]
                ?? array_fill_keys(array_keys(groupThresholdDirs()), null);
            if (!empty($g['split_temp'])) {
                foreach (bucketCalibTemps($eg['lights'], $tempTolEff) as $tb) {
                    $pushGroup($pool, $eg['exptime'], $tb['rows'], $tols, $tkey);
                }
            } else {
                // One group per exposure bucket, median temperature for info only.
                $pushGroup($pool, $eg['exptime'], $eg['lights'], $tols, $tkey);
            }
        }
    }
    usort($groups, fn($a, $b) =>
        [$a['setup_no'], $a['panel_no'], $a['filter'], (float)($a['exptime'] ?? -1), (float)($a['rep_temp'] ?? -9999)]
        <=> [$b['setup_no'], $b['panel_no'], $b['filter'], (float)($b['exptime'] ?? -1), (float)($b['rep_temp'] ?? -9999)]);
    return $groups;
}

/**
 * file_id => true for lights flagged auto_off in integration groups.
 * Used to paint threshold-rejected rows red in the main project tree.
 */
function indexAutoOffLights(array $intGroups): array
{
    $out = [];
    foreach ($intGroups as $grp) {
        foreach ($grp['lights'] as $li) {
            if (!empty($li['auto_off'])) {
                $out[(int)$li['file_id']] = true;
            }
        }
    }
    return $out;
}

/**
 * Mark tree light rows contained in $autoOff (file_id set) with auto_off.
 * Operates in place on a copy (PHP arrays are value types: reassign result).
 */
function markTreeAutoOff(array $tree, array $autoOff): array
{
    foreach ($tree['setups'] ?? [] as $si => $setup) {
        foreach ($setup['panels'] as $pi => $panel) {
            foreach ($panel['sessions'] as $sesi => $session) {
                foreach ($session['filters'] as $fi => $filter) {
                    foreach ($filter['lights'] as $lii => $li) {
                        if (isset($autoOff[(int)$li['file_id']])) {
                            $tree['setups'][$si]['panels'][$pi]['sessions'][$sesi]['filters'][$fi]['lights'][$lii]['auto_off'] = true;
                        }
                    }
                }
            }
        }
    }
    return $tree;
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
