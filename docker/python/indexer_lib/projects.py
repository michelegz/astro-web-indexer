"""
Project suggestion engine (WBPP-like hierarchy, v1 free).

Logical links only: this module proposes which project/setup/panel/session
each indexed file belongs to. Nothing is moved on disk.

Assignment modes (projects.assign_mode):
- manual  : no suggestions, no automation.
- suggest : new/changed files become `pending` rows in project_suggestions
            for the wizard. Dismissed suggestions are never re-proposed.
- frozen  : project is locked, completely skipped.
- auto    : files are linked directly into project_files (logged).

Manual links and overrides always win: files already linked in a project,
files with an existing suggestion row, and files with a setup_overrides
row are never touched by automation.

Subject identity is coordinates + FoV; OBJECT is display-only / fallback.
Rotation (objctrot) splits panels beyond tolerance; NULL = wildcard.
"""

import json
import logging
import math
from datetime import timedelta

logger = logging.getLogger('reindex.projects')

ELIGIBLE_IMGTYPES = ('LIGHT', 'DARK', 'FLAT', 'BIAS')

DEFAULT_TOLS = {
    'tol_exp_dark': '10%',
    'tol_temp': '2C',
    'tol_rot': '3deg',
    'tol_pos_arcmin': '5',
    'tol_pos_fovfrac': '0.2',
    'tol_fov': '10%',
}


def _norm(value):
    """Uppercase/trim fingerprint part, '?' when missing."""
    if value is None:
        return '?'
    text = str(value).strip().upper()
    text = ' '.join(text.split())
    return text if text else '?'


def build_setup_fingerprint(meta):
    """Instrument fingerprint: INSTRUME|TELESCOP|CAMERAID|XBINxYBIN|GAIN|XPIXSZ."""
    xb = meta.get('xbinning')
    yb = meta.get('ybinning')
    binning = f"{xb if xb else '?'}X{yb if yb else '?'}"
    parts = [
        _norm(meta.get('instrume')),
        _norm(meta.get('telescop')),
        _norm(meta.get('cameraid')),
        binning,
        _norm(meta.get('gain')),
        _norm(meta.get('xpixsz')),
    ]
    return '|'.join(parts)


def setup_label(meta):
    """Short human label for a setup, e.g. 'ASI2600MM + ESPRIT 100'."""
    instr = (meta.get('instrume') or '').strip()
    scope = (meta.get('telescop') or '').strip()
    label = ' + '.join(p for p in (instr, scope) if p)
    return label if label else None


def parse_ra_to_deg(objctra):
    """OBJCTRA ('HH:MM:SS.ss' or degrees float) -> RA degrees. None on failure."""
    if objctra is None:
        return None
    try:
        return float(objctra) % 360.0
    except (TypeError, ValueError):
        pass
    try:
        text = str(objctra).strip().replace('h', ':').replace('m', ':').replace('s', '')
        parts = [float(p) for p in text.split(':')]
        while len(parts) < 3:
            parts.append(0.0)
        h, m, s = parts[:3]
        return (h + m / 60.0 + s / 3600.0) * 15.0 % 360.0
    except (ValueError, AttributeError):
        return None


def parse_dec_to_deg(objctdec):
    """OBJCTDEC ('[+-]DD:MM:SS.ss' or degrees float) -> Dec degrees. None on failure."""
    if objctdec is None:
        return None
    try:
        return float(objctdec)
    except (TypeError, ValueError):
        pass
    try:
        text = str(objctdec).strip().replace('d', ':').replace('m', ':').replace('s', '')
        sign = -1.0 if text.startswith('-') else 1.0
        text = text.lstrip('+-')
        parts = [float(p) for p in text.split(':')]
        while len(parts) < 3:
            parts.append(0.0)
        d, m, s = parts[:3]
        return sign * (abs(d) + m / 60.0 + s / 3600.0)
    except (ValueError, AttributeError):
        return None


def normalize_object(name):
    """OBJECT bucket key: upper/trimmed/collapsed, 'UNKNOWN' when empty."""
    if not name:
        return 'UNKNOWN'
    text = ' '.join(str(name).strip().upper().split())
    return text if text else 'UNKNOWN'


def position_of(meta):
    """(ra, dec, source): header ra/dec first, then OBJCTRA/DEC parse, else (None, None, 'object')."""
    ra, dec = meta.get('ra'), meta.get('dec')
    if ra is not None and dec is not None:
        try:
            return float(ra) % 360.0, float(dec), 'header'
        except (TypeError, ValueError):
            pass
    ra2 = parse_ra_to_deg(meta.get('objctra'))
    dec2 = parse_dec_to_deg(meta.get('objctdec'))
    if ra2 is not None and dec2 is not None:
        return ra2, dec2, 'objct'
    return None, None, 'object'


def haversine_deg(ra1, dec1, ra2, dec2):
    """Angular separation in degrees."""
    r1, d1, r2, d2 = map(math.radians, (ra1, dec1, ra2, dec2))
    a = math.sin((d2 - d1) / 2.0) ** 2 + math.cos(d1) * math.cos(d2) * math.sin((r2 - r1) / 2.0) ** 2
    return math.degrees(2.0 * math.asin(min(1.0, math.sqrt(a))))


def rotation_distance(r1, r2):
    """Circular distance in degrees on 0-360. None input -> (0.0, True unknown)."""
    if r1 is None or r2 is None:
        return 0.0, True
    try:
        d = abs(float(r1) - float(r2)) % 360.0
        return min(d, 360.0 - d), False
    except (TypeError, ValueError):
        return 0.0, True


def astro_night(date_obs):
    """Astro-night (noon-to-noon) date for a DATE_OBS datetime."""
    if date_obs is None:
        return None
    dt = date_obs - timedelta(hours=12)
    return dt.date()


def _num_prefix(text, default):
    """Leading float of strings like '10%', '2C', '3deg', '0.2'. Default on failure."""
    try:
        num = ''
        for ch in str(text).strip():
            if ch.isdigit() or ch in '.-':
                num += ch
            else:
                break
        return float(num) if num not in ('', '-', '.', '-.') else default
    except (TypeError, ValueError):
        return default


def get_projects(dcur):
    dcur.execute("SELECT id, name, tolerances, assign_mode FROM projects")
    projects = []
    for row in dcur.fetchall():
        try:
            overrides = json.loads(row['tolerances']) if row['tolerances'] else {}
            if not isinstance(overrides, dict):
                overrides = {}
        except (TypeError, ValueError):
            overrides = {}
        projects.append({
            'id': row['id'],
            'name': row['name'],
            'overrides': overrides,
            'mode': row['assign_mode'] if row['assign_mode'] in ('manual', 'suggest', 'frozen', 'auto') else 'suggest',
        })
    return projects


def get_globals(dcur):
    try:
        dcur.execute("SELECT setting_key, setting_value FROM global_settings")
        out = {r['setting_key']: r['setting_value'] for r in dcur.fetchall()}
    except Exception:
        out = {}
    for key, default in DEFAULT_TOLS.items():
        out.setdefault(key, default)
    return out


def tol(project, globals_, key):
    if key in project['overrides'] and str(project['overrides'][key]).strip() != '':
        return str(project['overrides'][key])
    return str(globals_.get(key, DEFAULT_TOLS[key]))


def find_setup(dcur, project_id, fingerprint):
    """Setup id by exact fingerprint match, None when absent (never creates)."""
    dcur.execute(
        "SELECT id FROM project_setups WHERE project_id = %s AND fingerprint = %s",
        (project_id, fingerprint),
    )
    row = dcur.fetchone()
    return row['id'] if row else None


def find_panel(dcur, setup_id, ra, dec, rot, fov_w, fov_h,
               tol_pos_deg, tol_rot_deg, tol_fov_frac, object_bucket):
    """(panel_id, sep_deg, rot_dist, rot_unknown): match only, None id when absent.

    Files without coordinates match only NULL-coord panels of the same OBJECT
    bucket. A known file FoV differing from the panel FoV beyond tol_fov_frac
    never matches (e.g. reducer on/off). Never creates rows: suggestions
    anchor to existing panels only.
    """
    dcur.execute(
        "SELECT id, ra, `dec`, rot_mean, fov_w, fov_h, label_object "
        "FROM project_panels WHERE setup_id = %s",
        (setup_id,),
    )
    try:
        file_fov = min(float(fov_w), float(fov_h)) if fov_w and fov_h else None
        if file_fov is not None and file_fov <= 0:
            file_fov = None
    except (TypeError, ValueError):
        file_fov = None
    panels = dcur.fetchall()
    if ra is not None and dec is not None:
        for p in panels:
            if p['ra'] is None or p['dec'] is None:
                continue
            try:
                sep = haversine_deg(ra, dec, float(p['ra']), float(p['dec']))
            except (TypeError, ValueError):
                continue
            if sep > tol_pos_deg:
                continue
            dist, unknown = rotation_distance(rot, p['rot_mean'])
            if not unknown and dist > tol_rot_deg:
                continue  # same center, different orientation -> keep looking
            if file_fov is not None:
                try:
                    panel_fov = min(float(p['fov_w']), float(p['fov_h']))
                except (TypeError, ValueError):
                    panel_fov = None
                if panel_fov is not None and panel_fov > 0:
                    if abs(file_fov - panel_fov) / panel_fov > tol_fov_frac:
                        continue  # same pointing, different image scale -> keep looking
            return p['id'], sep, dist, unknown
    else:
        for p in panels:
            if p['ra'] is None and (p['label_object'] or '') == object_bucket:
                return p['id'], 0.0, 0.0, True
    return None, 0.0, 0.0, rot is None


def find_or_create_session(dcur, panel_id, night):
    dcur.execute(
        "SELECT id FROM project_sessions WHERE panel_id = %s AND astro_night = %s",
        (panel_id, night),
    )
    row = dcur.fetchone()
    if row:
        return row['id'], False
    # Next consecutive session_no within the project (stable: never reused).
    dcur.execute(
        "SELECT COALESCE(MAX(ss.session_no), 0) AS max_no FROM project_sessions ss "
        "JOIN project_panels pp ON pp.id = ss.panel_id "
        "JOIN project_setups ps ON ps.id = pp.setup_id "
        "WHERE ps.project_id = (SELECT ps2.project_id FROM project_setups ps2 "
        "JOIN project_panels pp2 ON pp2.setup_id = ps2.id WHERE pp2.id = %s)",
        (panel_id,),
    )
    next_no = (dcur.fetchone()['max_no'] or 0) + 1
    dcur.execute(
        "INSERT INTO project_sessions (panel_id, astro_night, session_no) VALUES (%s, %s, %s)",
        (panel_id, night, next_no),
    )
    return dcur.lastrowid, True


def already_processed(dcur, project_id, file_id):
    """Linked already, or any suggestion row exists (pending/accepted/dismissed)."""
    dcur.execute(
        "SELECT 1 FROM project_files WHERE file_id = %s AND "
        "(node_id IN (SELECT id FROM project_setups WHERE project_id = %s) AND level = 'setup' "
        "OR node_id IN (SELECT pp.id FROM project_panels pp JOIN project_setups ps ON ps.id = pp.setup_id "
        "WHERE ps.project_id = %s) AND level = 'panel' "
        "OR node_id IN (SELECT ss.id FROM project_sessions ss JOIN project_panels pp ON pp.id = ss.panel_id "
        "JOIN project_setups ps ON ps.id = pp.setup_id WHERE ps.project_id = %s) AND level IN ('session','filter') "
        "OR (level = 'project' AND node_id = %s)) LIMIT 1",
        (file_id, project_id, project_id, project_id, project_id),
    )
    if dcur.fetchone():
        return True
    dcur.execute(
        "SELECT 1 FROM project_suggestions WHERE project_id = %s AND file_id = %s LIMIT 1",
        (project_id, file_id),
    )
    return dcur.fetchone() is not None


def get_override_setup(dcur, project_id, file_id):
    dcur.execute(
        "SELECT ps.id FROM setup_overrides so JOIN project_setups ps ON ps.id = so.setup_id "
        "WHERE so.file_id = %s AND ps.project_id = %s",
        (file_id, project_id),
    )
    row = dcur.fetchone()
    return row['id'] if row else None


def _insert_suggestion(dcur, project_id, file_id, level, node_id, filter_name, role, reason):
    try:
        dcur.execute(
            "INSERT INTO project_suggestions "
            "(project_id, file_id, level, node_id, filter_name, role, reason) "
            "VALUES (%s, %s, %s, %s, %s, %s, %s)",
            (project_id, file_id, level, node_id, filter_name, role, reason),
        )
        return True
    except Exception as err:
        logger.debug(f"Suggestion insert skipped (project {project_id}, file {file_id}): {err}")
        return False


def _insert_link(dcur, file_id, level, node_id, filter_name, role, is_light):
    dcur.execute(
        "INSERT INTO project_files (file_id, level, node_id, filter_name, role, is_light) "
        "VALUES (%s, %s, %s, %s, %s, %s) "
        "ON DUPLICATE KEY UPDATE role = VALUES(role), filter_name = VALUES(filter_name)",
        (file_id, level, node_id, filter_name, role, 1 if is_light else 0),
    )


def suggest_file(dcur, project, globals_, meta, file_id):
    """Propose (or auto-link) one file into one project. Returns 'suggested'|'linked'|'skipped'."""
    mode = project['mode']
    if mode in ('manual', 'frozen'):
        return 'skipped'
    if already_processed(dcur, project['id'], file_id):
        return 'skipped'

    imgtype = (meta.get('imgtype') or '').upper()
    if imgtype not in ELIGIBLE_IMGTYPES:
        return 'skipped'

    override_setup = get_override_setup(dcur, project['id'], file_id)
    fingerprint = build_setup_fingerprint(meta)
    if override_setup is not None:
        setup_id = override_setup
        setup_note = f"manual override to setup {setup_id}"
    else:
        # Strict: suggestions anchor to existing setups only, never create.
        setup_id = find_setup(dcur, project['id'], fingerprint)
        if setup_id is None:
            logger.debug(f"No setup match for {meta.get('path')} "
                         f"in project '{project['name']}' (fp {fingerprint[:48]}).")
            return 'skipped'
        setup_note = f"known setup {setup_label(meta) or fingerprint[:32]}"

    ra, dec, pos_source = position_of(meta)
    tol_pos_deg = max(_num_prefix(tol(project, globals_, 'tol_pos_arcmin'), 5.0) / 60.0, 1e-6)
    fov_w, fov_h = meta.get('fov_w'), meta.get('fov_h')
    try:
        fov_min = min(float(fov_w), float(fov_h)) / 60.0
        if fov_min > 0:
            tol_pos_deg = max(tol_pos_deg,
                              _num_prefix(tol(project, globals_, 'tol_pos_fovfrac'), 0.2) * fov_min)
    except (TypeError, ValueError):
        fov_min = None
    tol_rot = _num_prefix(tol(project, globals_, 'tol_rot'), 3.0)
    tol_fov = _num_prefix(tol(project, globals_, 'tol_fov'), 10.0) / 100.0

    object_bucket = normalize_object(meta.get('object'))

    # Strict: suggestions anchor to existing panels only, never create.
    # Sessions/filters under a known panel are plain time buckets (safe to create).
    found = find_panel(dcur, setup_id, ra, dec, meta.get('objctrot'),
                       fov_w, fov_h, tol_pos_deg, tol_rot, tol_fov, object_bucket)
    if found[0] is None:
        logger.debug(f"No panel match for {meta.get('path')} in project '{project['name']}'.")
        return 'skipped'
    panel_id, sep, rot_d, rot_unknown = found

    night = astro_night(meta.get('date_obs'))
    is_light = imgtype == 'LIGHT'
    if night is None and is_light:
        # Lights strictly need their night session; dateless calibrations
        # fall back to setup level (see below).
        return 'skipped'
    session_id = None
    if night is not None:
        session_id, _ = find_or_create_session(dcur, panel_id, night)

    filt = (meta.get('filter') or '').strip() or None
    if is_light:
        level, node_id, filter_name, role = 'filter', session_id, filt, 'sub'
    elif imgtype == 'FLAT' and night is not None:
        # Flats live in their own night session (instrument match is by setup,
        # filter match for lights is checked by diagnostics, not by level).
        level, node_id, filter_name, role = 'session', session_id, filt, 'sub'
    else:
        # Darks/bias (and dateless flats) live at setup level (instrument-dependent).
        level, node_id, filter_name, role = 'setup', setup_id, filt if imgtype == 'FLAT' else None, 'sub'

    pos_note = (f"coords {ra:.4f}/{dec:+.4f} ({pos_source})" if ra is not None
                else f"no coords, OBJECT bucket '{object_bucket}'")
    rot_note = 'rot unknown' if rot_unknown else f"rot Δ{rot_d:.1f}°"
    reason = (f"{setup_note}; panel {sep * 60:.1f}′ away, {rot_note}; "
              f"night {night if night is not None else '?'}; {pos_note}; rule {imgtype}→{level}"
              + (f" filter {filt}" if filt else ""))

    if mode == 'auto':
        _insert_link(dcur, file_id, level, node_id, filter_name, role, is_light)
        logger.info(f"Auto-linked {meta.get('path')} into project '{project['name']}': {reason}")
        return 'linked'

    ok = _insert_suggestion(dcur, project['id'], file_id, level, node_id, filter_name, role, reason)
    return 'suggested' if ok else 'skipped'


FILE_COLUMNS = ("id, path, imgtype, `filter`, exptime, date_obs, instrume, telescop, "
                "cameraid, xbinning, ybinning, gain, xpixsz, ccd_temp, ra, `dec`, "
                "objctra, objctdec, `object`, fov_w, fov_h, objctrot")


def suggest_projects_for_files(conn, metas):
    """Entry point for freshly indexed files.

    metas: list of dicts with worker params + 'path' (db_id resolved here).
    Returns counts dict. Commits at the end.
    """
    dcur = conn.cursor(dictionary=True)
    try:
        projects = [p for p in get_projects(dcur) if p['mode'] not in ('manual', 'frozen')]
        if not projects or not metas:
            return {'suggested': 0, 'linked': 0, 'skipped': 0}
        globals_ = get_globals(dcur)
        counts = {'suggested': 0, 'linked': 0, 'skipped': 0}
        for meta in metas:
            file_id = meta.get('db_id')
            if file_id is None:
                dcur.execute("SELECT id FROM files WHERE path = %s", (meta.get('path'),))
                row = dcur.fetchone()
                if not row:
                    counts['skipped'] += 1
                    continue
                file_id = row['id']
            for project in projects:
                try:
                    outcome = suggest_file(dcur, project, globals_, meta, file_id)
                except Exception as err:
                    logger.warning(f"Suggest failed for {meta.get('path')} "
                                   f"project '{project['name']}': {err}")
                    outcome = 'skipped'
                counts[outcome] = counts.get(outcome, 0) + 1
        conn.commit()
        logger.info(f"Project suggestions: {counts['suggested']} suggested, "
                    f"{counts['linked']} auto-linked, {counts['skipped']} skipped.")
        return counts
    finally:
        dcur.close()


def suggest_projects_backfill(conn, project_id=None):
    """Retroactive pass over the whole archive (on-demand --suggest-projects).

    Never touches frozen projects, manual links, or dismissed suggestions.
    """
    dcur = conn.cursor(dictionary=True)
    try:
        dcur.execute(
            f"SELECT {FILE_COLUMNS} FROM files "
            "WHERE deleted_at IS NULL AND imgtype IN ('LIGHT','DARK','FLAT','BIAS')",
        )
        metas = dcur.fetchall()
        if project_id is not None:
            projects = [p for p in get_projects(dcur)
                        if p['id'] == project_id and p['mode'] not in ('manual', 'frozen')]
        else:
            projects = None  # resolved inside per-file pass
        counts = {'suggested': 0, 'linked': 0, 'skipped': 0}
        if not metas:
            return counts
        globals_ = get_globals(dcur)
        if projects is None:
            projects = [p for p in get_projects(dcur) if p['mode'] not in ('manual', 'frozen')]
        for meta in metas:
            for project in projects:
                try:
                    outcome = suggest_file(dcur, project, globals_, meta, meta['id'])
                except Exception as err:
                    logger.warning(f"Backfill suggest failed for {meta.get('path')}: {err}")
                    outcome = 'skipped'
                counts[outcome] = counts.get(outcome, 0) + 1
        conn.commit()
        logger.info(f"Project backfill: {counts['suggested']} suggested, "
                    f"{counts['linked']} auto-linked, {counts['skipped']} skipped "
                    f"over {len(metas)} files.")
        return counts
    finally:
        dcur.close()
