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

import hashlib
import json
import logging
import math
import re
from datetime import timedelta

logger = logging.getLogger('reindex.projects')

ELIGIBLE_IMGTYPES = ('LIGHT', 'DARK', 'FLAT', 'BIAS')


def normalize_imgtype(raw):
    """Canonical IMAGETYP at ingest: cameras/drivers write many variants
    ('Light Frame', 'DarkFrame', 'BIAS', 'DarkFlat'...). Everything downstream
    (projects, star metrics, thumbnails) matches the four exact values, so
    unrecognized files used to vanish silently. Unrecognized input falls back
    to the raw uppercased value (today's behavior for true unknowns)."""
    text = str(raw or '').upper().replace(' ', '').replace('_', '').replace('-', '')
    if not text:
        return 'UNKNOWN'
    if 'DARK' in text:
        return 'DARK'  # DARK, DARKFRAME, DARKFLAT, FLATDARK
    if 'FLAT' in text:
        return 'FLAT'  # FLAT, FLATFRAME, SKYFLAT, DOMEFLAT, FLATFIELD
    if 'BIAS' in text:
        return 'BIAS'  # BIAS, BIASFRAME
    if text.startswith('LIGHT') or text in ('SCIENCE',):
        return 'LIGHT'  # LIGHT, LIGHTFRAME
    if text == 'UNKNOWN':
        return 'UNKNOWN'
    return text

DEFAULT_TOLS = {
    'tol_temp': '2C',
    'tol_rot': '3deg',
    'tol_pos_arcmin': '5',
    'tol_pos_fovfrac': '0.2',
    'tol_fov': '10%',
}

# Tolerances the suggester actually reads. tol_exp is absent on purpose (the
# PHP side carries it for diagnostics/grouping only): including it would
# re-open dismissed rows that can never match. Order is part of the contract
# with projects_functions.php::getProjectSuggestConfigHash().
SUGGEST_TOL_KEYS = ('tol_pos_arcmin', 'tol_pos_fovfrac', 'tol_rot', 'tol_fov')

# Header columns the matcher consumes, raw values only. Order is part of the
# contract with projects_functions.php::getProjectSuggestMatchInputs().
# Appended at the end, never reordered: old hashes stay comparable.
# rotator_angle/readoutm feed matching (flat rotation signal, readout mode);
# meteo columns are display-only and stay out of the hash on purpose.
SUGGEST_MATCH_FIELDS = (
    'imgtype', 'filter', 'instrume', 'telescop', 'cameraid', 'xbinning',
    'ybinning', 'gain', 'offset', 'xpixsz', 'ra', 'dec', 'objctra',
    'objctdec', 'object', 'fov_w', 'fov_h', 'objctrot', 'date_obs',
    'rotator_angle', 'readoutm',
)


def _suggest_tol_token(raw):
    """Canonical form of one tolerance value.

    Mirrors projectSuggestTolToken() in projects_functions.php: numeric prefix
    normalized with string ops only (never float formatting, which differs
    between runtimes), so '5', '5.0' and ' 5.00 ' collapse to one token.
    Unparsable values keep their trimmed raw form.
    """
    text = str(raw).strip()
    num = ''
    for ch in text:
        if ch.isdigit() or ch in '.-':
            num += ch
        else:
            break
    if num in ('', '-', '.', '-.'):
        return text
    sign = ''
    if num.startswith('-'):
        sign = '-'
        num = num[1:]
    if '.' in num:
        int_part, frac = num.split('.', 1)
    else:
        int_part, frac = num, ''
    int_part = int_part.lstrip('0') or '0'
    frac = frac.rstrip('0')
    return sign + int_part + ('.' + frac if frac else '')


def _suggest_config_hash(project, globals_):
    """md5 of the effective tolerances the suggester reads.

    Effective values (override ?? global ?? default), same resolution as
    tol(), so the PHP side hashing resolve_tol() produces the same digest.
    """
    lines = [k + '=' + _suggest_tol_token(tol(project, globals_, k))
             for k in SUGGEST_TOL_KEYS]
    return hashlib.md5('\n'.join(lines).encode('utf-8')).hexdigest()


def _suggest_match_inputs(dcur, file_id):
    """Canonical snapshot of the raw header values feeding the matcher.

    Every column is read as CAST(... AS CHAR) so MySQL produces one text
    rendering for both languages: this driver hands floats back as float
    objects while PDO hands them back as strings, and comparing those would
    silently make every dismissal stale.
    """
    selects = ', '.join('CAST(`%s` AS CHAR) AS `%s`' % (f, f) for f in SUGGEST_MATCH_FIELDS)
    dcur.execute('SELECT %s FROM files WHERE id = %%s LIMIT 1' % selects, (file_id,))
    row = dcur.fetchone()
    if row is None:
        return None
    return '\n'.join('%s=%s' % (f, '~' if row.get(f) is None else row[f])
                     for f in SUGGEST_MATCH_FIELDS)


def _norm(value):
    """Uppercase/trim fingerprint part, '?' when missing."""
    if value is None:
        return '?'
    text = str(value).strip().upper()
    text = ' '.join(text.split())
    return text if text else '?'


def _fnum(value):
    """Canonical numeric fingerprint part, mirroring PHP's (string) cast.

    Integral floats become ints ('100', not '100.0') so both builders match
    byte-identically; anything non-numeric falls back to _norm. '?' when missing.
    """
    if value is None:
        return '?'
    if isinstance(value, str):
        text = ' '.join(value.strip().upper().split())
        if text == '':
            return '?'
        try:
            f = float(text)
        except ValueError:
            return text
    else:
        try:
            f = float(value)
        except (TypeError, ValueError):
            return _norm(value)
    if f.is_integer():
        return str(int(f))
    return repr(f)


def build_setup_fingerprint(meta):
    """Instrument fingerprint: INSTRUME|TELESCOP|CAMERAID|XBINxYBIN|GAIN|XPIXSZ|OFFSET."""
    xb = meta.get('xbinning')
    yb = meta.get('ybinning')
    binning = f"{xb if xb else '?'}X{yb if yb else '?'}"
    parts = [
        _norm(meta.get('instrume')),
        _norm(meta.get('telescop')),
        _norm(meta.get('cameraid')),
        binning,
        _fnum(meta.get('gain')),
        _fnum(meta.get('xpixsz')),
        _fnum(meta.get('offset')),
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
        # lower() before the marker replacements, so 'H'/'M'/'S' are recognised too.
        # Without it "12H34M56S" raised ValueError and the file fell back to the OBJECT
        # bucket, while the PHP twin projectParseRa() lowercases first and gets real
        # coordinates: panels matched on the web were then not recognised by the
        # watcher, and vice versa. The two must parse identically.
        text = str(objctra).strip().lower().replace('h', ':').replace('m', ':').replace('s', '')
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
        # lower() for the same reason as parse_ra_to_deg(): projectParseDec() lowercases
        # before replacing the markers, and the two must not disagree on "12:34:56D".
        text = str(objctdec).strip().lower().replace('d', ':').replace('m', ':').replace('s', '')
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
    """Leading float of strings like '10%', '2C', '3deg', '0.2'. Default on failure.

    Mirrors the PHP projectNumPrefix() regex on purpose, so the web UI and the
    watcher cannot read the same tolerance differently. The old loop here also
    accepted '-' and a leading '.', which PHP does not: a stored '-5' became -5.0
    here and fell back to the default there, and on this side a negative tolerance
    is degenerate — `dist > tol_rot` is then always true, so no panel is ever
    matched. A tolerance is a magnitude and cannot be negative, so refusing the
    sign here is the shared behaviour, not a loosened tolerance.
    """
    m = re.match(r'^[\s]*([0-9]+(?:\.[0-9]+)?)', str(text) if text is not None else '')
    if m:
        try:
            return float(m.group(1))
        except (TypeError, ValueError):
            return default
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
    except Exception as err:
        # Only a missing table may degrade to the defaults: that is a database where
        # the projects migrations have not run yet, and there is nothing to read.
        #
        # Any other failure used to become an empty dict with no log, and that was
        # not harmless. globals_ feeds _suggest_config_hash(), so a transient error
        # (lost connection, permissions, broken dependency) changed the hash and
        # every dismissed suggestion whose stored hash no longer matched was deleted
        # at the stale check below. Failing loudly is the safe direction: the watcher
        # retries, and the admin's global tolerances keep being honoured.
        if getattr(err, 'errno', None) != 1146:  # ER_NO_SUCH_TABLE
            logger.error(
                "global_settings unreadable, not falling back to default tolerances "
                "(this would change the suggest config hash and drop dismissals): %s", err)
            raise
        logger.debug("global_settings not present, using default tolerances: %s", err)
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
            # Normalize both sides: panels created before this rule stored the raw
            # OBJECT string, so 'ngc 7000' could never match a bucket of
            # 'NGC 7000' and the panel was silently never found.
            if p['ra'] is None and normalize_object(p['label_object']) == object_bucket:
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
    dcur.execute(
        "SELECT ps.project_id FROM project_panels pp "
        "JOIN project_setups ps ON ps.id = pp.setup_id WHERE pp.id = %s",
        (panel_id,),
    )
    project_id = dcur.fetchone()['project_id']
    # Mirrors projectFindOrCreateSession(): allocate past the current maximum, then
    # renumber the project by night. The number cannot be picked correctly at insert
    # time, because inserting a night older than the existing ones needs rows that
    # are already stored to move, and MAX+1 gets it backwards (N3 for the oldest of
    # three). Projects hold a handful of sessions, so renumbering is cheap and also
    # repairs anything that drifted.
    dcur.execute(
        "SELECT COALESCE(MAX(ss.session_no), 0) AS n FROM project_sessions ss "
        "JOIN project_panels pp ON pp.id = ss.panel_id "
        "JOIN project_setups ps ON ps.id = pp.setup_id WHERE ps.project_id = %s",
        (project_id,),
    )
    next_no = (dcur.fetchone()['n'] or 0) + 1
    try:
        dcur.execute(
            "INSERT INTO project_sessions (panel_id, astro_night, session_no) "
            "VALUES (%s, %s, %s)",
            (panel_id, night, next_no),
        )
        new_id = dcur.lastrowid
    except Exception as exc:
        # The only unique key here is (panel_id, astro_night): a concurrent writer
        # created this exact session, and its row is the right answer.
        if getattr(exc, 'errno', None) != 1062:
            raise
        dcur.execute(
            "SELECT id FROM project_sessions WHERE panel_id = %s AND astro_night = %s",
            (panel_id, night),
        )
        row = dcur.fetchone()
        if row:
            return row['id'], False
        raise
    renumber_sessions_by_night(dcur, project_id)
    return new_id, True


def renumber_sessions_by_night(dcur, project_id):
    """session_no dense and increasing with astro-night, per project.

    The project tree renders sessions in night order and shows session_no as the N
    prefix, so the two have to agree. Ties on the same night are separated by id so
    two panels of a project never share a number.
    """
    dcur.execute(
        "UPDATE project_sessions ss "
        "JOIN project_panels pp ON pp.id = ss.panel_id "
        "JOIN project_setups ps ON ps.id = pp.setup_id "
        "JOIN (SELECT ss2.id, ROW_NUMBER() OVER "
        "(PARTITION BY ps2.project_id ORDER BY ss2.astro_night, ss2.id) AS rn "
        "FROM project_sessions ss2 "
        "JOIN project_panels pp2 ON pp2.id = ss2.panel_id "
        "JOIN project_setups ps2 ON ps2.id = pp2.setup_id) t ON t.id = ss.id "
        "SET ss.session_no = t.rn WHERE ps.project_id = %s",
        (project_id,),
    )


def already_processed(dcur, project_id, file_id, project=None, globals_=None):
    """Linked already, or a live suggestion row exists.

    A dismissed row blocks the file only while it is still meaningful: it stays
    valid while the matching config and the file headers are unchanged, and goes
    stale as soon as either moves. Stale rows are deleted here so the caller can
    rematch from scratch (a fresh INSERT could otherwise hit the UNIQUE
    (project_id, file_id, level, node_id) index with a different node).
    """
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
        "SELECT id, status, config_hash, match_inputs FROM project_suggestions "
        "WHERE project_id = %s AND file_id = %s",
        (project_id, file_id),
    )
    rows = dcur.fetchall()
    if not rows:
        return False
    if any(r['status'] != 'dismissed' for r in rows):
        return True
    # Only dismissed rows from here on. A NULL component predates the stale
    # tracking: treat it as stale so every dismissal is reconsidered once.
    if project is None or globals_ is None:
        return True
    cur_hash = _suggest_config_hash(project, globals_)
    cur_inputs = _suggest_match_inputs(dcur, file_id)
    stale_ids = [
        r['id'] for r in rows
        if r['config_hash'] is None or r['match_inputs'] is None
        or r['config_hash'] != cur_hash or r['match_inputs'] != cur_inputs
    ]
    for sid in stale_ids:
        dcur.execute("DELETE FROM project_suggestions WHERE id = %s", (sid,))
    return len(stale_ids) != len(rows)


def get_override_setup(dcur, project_id, file_id):
    dcur.execute(
        "SELECT ps.id FROM setup_overrides so JOIN project_setups ps ON ps.id = so.setup_id "
        "WHERE so.file_id = %s AND ps.project_id = %s",
        (file_id, project_id),
    )
    row = dcur.fetchone()
    return row['id'] if row else None


def _insert_suggestion(dcur, project_id, file_id, level, node_id, filter_name, role,
                       reason, config_hash=None, match_inputs=None):
    """Insert a queued suggestion.

    config_hash / match_inputs describe the context the suggestion was computed
    with, so the UI can show it and a dismissed row is comparable. They are not
    the source of truth for staleness: dismissSuggestion() rewrites them, since
    the decision context is the one at dismissal time, not at suggestion time.
    """
    try:
        dcur.execute(
            "INSERT INTO project_suggestions "
            "(project_id, file_id, level, node_id, filter_name, role, reason, config_hash, match_inputs) "
            "VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)",
            (project_id, file_id, level, node_id, filter_name, role, reason,
             config_hash, match_inputs),
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


def find_setup_session(dcur, setup_id, night):
    """First session id of an astro-night anywhere in the setup.

    Panel-agnostic on purpose: flats belong to the night, not the target
    (they are rarely shot pointing at the subject). Deterministic: lowest
    panel_no, then lowest session id. None when absent — never creates rows:
    flats must not sprout panels or orphan sessions.
    """
    dcur.execute(
        "SELECT ss.id FROM project_sessions ss "
        "JOIN project_panels pp ON pp.id = ss.panel_id "
        "WHERE pp.setup_id = %s AND ss.astro_night = %s "
        "ORDER BY pp.panel_no ASC, pp.id ASC, ss.id ASC LIMIT 1",
        (setup_id, night),
    )
    row = dcur.fetchone()
    return row['id'] if row else None


def suggest_file(dcur, project, globals_, meta, file_id):
    """Propose (or auto-link) one file into one project. Returns 'suggested'|'linked'|'skipped'."""
    mode = project['mode']
    if mode in ('manual', 'frozen'):
        return 'skipped'
    if already_processed(dcur, project['id'], file_id, project, globals_):
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
    night = astro_night(meta.get('date_obs'))
    filt = (meta.get('filter') or '').strip() or None

    if imgtype == 'FLAT':
        # Flats skip panel matching entirely: no RA/DEC/FoV/OBJECT check, and
        # never create panels or sessions. Night session anywhere in the
        # setup (first by panel_no), else setup level with the filter kept.
        if night is not None:
            flat_session = find_setup_session(dcur, setup_id, night)
            if flat_session is not None:
                level, node_id = 'session', flat_session
            else:
                level, node_id = 'setup', setup_id
        else:
            level, node_id = 'setup', setup_id
        config_hash = _suggest_config_hash(project, globals_)
        reason = (f"{setup_note}; night {night if night is not None else '?'} "
                  f"(flat session-agnostic); rule FLAT→{level}"
                  + (f" filter {filt}" if filt else "")
                  + f"; cfg:{config_hash[:8]}")
        if mode == 'auto':
            _insert_link(dcur, file_id, level, node_id, filt, 'sub', False)
            logger.info(f"Auto-linked {meta.get('path')} into project '{project['name']}': {reason}")
            return 'linked'
        ok = _insert_suggestion(dcur, project['id'], file_id, level, node_id, filt, 'sub',
                                reason, config_hash, _suggest_match_inputs(dcur, file_id))
        return 'suggested' if ok else 'skipped'

    is_light = imgtype == 'LIGHT'

    if not is_light:
        # Darks/bias are instrument-dependent: they link at setup level, so they
        # need neither a panel nor a session. Running them through find_panel was
        # both dead work (panel_id is unused on this path) and a gate: a dark from
        # a calibration library carries no RA/DEC, so find_panel almost never
        # matched and the file was silently skipped. The session call created an
        # orphan row that nothing links and nothing prunes, which also consumed a
        # session_no out of chronological order.
        level, node_id, filter_name, role = 'setup', setup_id, None, 'sub'
        config_hash = _suggest_config_hash(project, globals_)
        reason = (f"{setup_note}; rule {imgtype}→{level}"
                  + (f" filter {filt}" if filt else "")
                  + f"; cfg:{config_hash[:8]}")
        if mode == 'auto':
            _insert_link(dcur, file_id, level, node_id, filter_name, role, False)
            logger.info(f"Auto-linked {meta.get('path')} into project '{project['name']}': {reason}")
            return 'linked'
        ok = _insert_suggestion(dcur, project['id'], file_id, level, node_id, filter_name, role,
                                reason, config_hash, _suggest_match_inputs(dcur, file_id))
        return 'suggested' if ok else 'skipped'

    # From here on only lights: they need their night session and an existing panel.
    if night is None:
        # Lights strictly need their night session.
        return 'skipped'

    tol_pos_deg = max(_num_prefix(tol(project, globals_, 'tol_pos_arcmin'), 5.0) / 60.0, 1e-6)
    fov_w, fov_h = meta.get('fov_w'), meta.get('fov_h')
    try:
        fov_min = min(float(fov_w), float(fov_h)) / 60.0
        if fov_min > 0:
            tol_pos_deg = max(tol_pos_deg,
                              _num_prefix(tol(project, globals_, 'tol_pos_fovfrac'), 0.2) * fov_min)
    except (TypeError, ValueError):
        # Missing or non-numeric FoV: keep tol_pos_deg as it is. The bare `except` is
        # the behaviour that matters; there is nothing to report afterwards, since
        # tol_pos_deg has already been settled above.
        pass
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

    session_id, _ = find_or_create_session(dcur, panel_id, night)

    level, node_id, filter_name, role = 'filter', session_id, filt, 'sub'

    pos_note = (f"coords {ra:.4f}/{dec:+.4f} ({pos_source})" if ra is not None
                else f"no coords, OBJECT bucket '{object_bucket}'")
    rot_note = 'rot unknown' if rot_unknown else f"rot Δ{rot_d:.1f}°"
    config_hash = _suggest_config_hash(project, globals_)
    # Short hash of the matching config, so the UI can tell which tolerances a
    # suggestion (or an old dismissal) was computed with.
    reason = (f"{setup_note}; panel {sep * 60:.1f}′ away, {rot_note}; "
              f"night {night}; {pos_note}; rule {imgtype}→{level}"
              + (f" filter {filt}" if filt else "")
              + f"; cfg:{config_hash[:8]}")

    if mode == 'auto':
        _insert_link(dcur, file_id, level, node_id, filter_name, role, True)
        logger.info(f"Auto-linked {meta.get('path')} into project '{project['name']}': {reason}")
        return 'linked'

    ok = _insert_suggestion(dcur, project['id'], file_id, level, node_id, filter_name, role,
                            reason, config_hash, _suggest_match_inputs(dcur, file_id))
    return 'suggested' if ok else 'skipped'


FILE_COLUMNS = ("id, path, imgtype, `filter`, exptime, date_obs, instrume, telescop, "
                "cameraid, xbinning, ybinning, gain, `offset`, xpixsz, ccd_temp, ra, `dec`, "
                "objctra, objctdec, `object`, fov_w, fov_h, objctrot, rotator_angle, readoutm")


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

    Never touches frozen projects, manual links, or accepted/pending
    suggestions. Dismissed rows are revisited only when the matching config or
    the file headers changed since they were dismissed (see already_processed).
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
