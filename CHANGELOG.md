# Changelog

## v1.5.0 - unreleased

The release centers on **projects**: planning calibration sessions, matching frames to them
automatically, rejecting bad data on quality metrics, and handing the result to
your processing software as a ready-to-stack archive. On top of that: tile-compressed FITS support, a
DATE-OBS calendar with INSTRUME/TELESCOP filters, and a batch of archive-integrity and
security fixes.

### New Features
- **Projects** - create, rename, archive and delete imaging projects with per-project tolerances and three assign modes: manual, automatic suggestions, and frozen (locked, no new files). A panel on the home page lists them; `projects.php` is the detail view
- **Project tree** - frames organized as setup → panel → session, with collapsible sections, per-project consecutive numbering (S/P/N counters for setups, panels and sessions) and fingerprint details (including `OFFSET`) shown on each setup
- **Automatic project suggestions** - a suggestion engine proposes the right project/panel/session for every new frame and queues them for review in a wizard; suggestions are re-evaluated when the matching context changes, dismissals are remembered and visible, and you can accept, dismiss or create the project inline. Add-to-project also works straight from Smart Frame Finder results, with a two-step preview and custom setups via override
- **Calibration diagnostics** - collapsible L/D/F/B groups per session with letter badges and a legend, coverage badges on flats, recursive subframe summaries, group totals and grand totals on the tree
- **Integration groups** - groups transversal to sessions (exposure subgroups under each filter) with sortable per-metric tables, bar charts following the table order, group hours and metric medians, and a fixed blink player per group (hover preview on rows, opens paused, no more page scroll on frame change). They represent a "preview" of what is going to happen in your pre-processing routine, allowing for a first subframes quality evaluation.
- **Configurable integration grouping criteria** - per project, toggle which of setup, panel, filter, exposure and temperature actually separates groups, and give the grouping its own exposure and temperature tolerance, independent of the ones used for matching. Split on temperature and you get one group per sensor temperature; turn it off and nights at -10°C and -5°C stack into a single integration. Same idea for exposure: 60s and 300s frames either get their own groups or land together. The setup criterion already carries binning and gain, and groups always span sessions, so a multi-night target resolves to one integration instead of a row per night. Defaults reproduce the previous behaviour (everything on except temperature) and empty fields inherit the project tolerance
- **Per-project filter aliases** - tell a project that `H-alpha`, `Ha` and `Hα` are the same physical filter and it merges them everywhere: project tree, integration groups, calibration matching, quality thresholds and the `FILTER_` folders of the export. Matching is case-insensitive, an alias must point straight at the canonical name (chains are rejected with an explanation rather than resolved silently), and the map is per-project, so the same archive can legitimately name its filters differently in two projects. The payoff shows up immediately in the groups: one filter no longer fragments into three half-empty integrations, each with its own medians and its own thresholds
- **Quality rejection thresholds** - auto thresholds from robust median-MAD at 5/4/3.5 sigma (or click a chart bar to set the value yourself), stored per group with manual/auto states, live bar recoloring, an excluded-by-threshold summary and an uncalibrated-metrics disclaimer
- **WBPP-style project ZIP export** - preview modal with size estimate and final confirmation, exporting only effective files (enabled, not pending, not auto-rejected). Includes session-scoped calibration links, calibration scope that survives promote/demote and moves, no-op-aware promote/demote with direction arrows, exposure-derived darkflats with flat coverage, and a size/manifest that matches the ZIP
- **Mosaic support** - cross-setup tile merging with a `TILE_` panel keyword, plus WBPP PRE/POST badges
- **Project permissions** - project-level access gate; inaccessible projects are hidden entirely and `can_download` is enforced on the ZIP export, so view-only users cannot stream files
- **DATE-OBS filter** - observation-date calendar with time and session dots on each day
- **INSTRUME and TELESCOP filters** - the per-page control moved into the pagination bars, and all filter dropdowns now respect the date and exposure filters
- **Tile-compressed FITS** - CFITSIO/FPACK `.fz` files are indexed like any other FITS
- **New header fields** - rotator angle and name, sensor readout mode, and meteo data (cloud cover, dewpoint, humidity, pressure, ambient temperature), available as table columns (hidden by default)
- **Metrics trend charts** - line/bar toggle with an adaptive default per metric
- **Table controls above the main table** - columns button, view controls and top pagination moved out of the header area

### Security Fixes
All of these were reachable on 1.4.0 and are fixed here.
- **A single allowed directory was enough to read the whole archive** - `auth_check.php` (the nginx `auth_request` gate on `/fits/`) percent-decoded `X-Original-URI` and used its first path segment for the permission check, but nginx resolved the real path from the still-encoded one. Asking for `/fits/M33%2F..%2FNGC7000%2F<file>` decoded to `M33/../NGC7000/<file>`, so the check passed on `M33` while the file served came from `NGC7000`: any user granted one directory could read everything. Traversal segments are now refused, so the segment the check reads is the segment that gets served
- **ZIP download could escape the fits root** - the containment test was a bare `str_starts_with($fullPath, realpath($fitsRoot))`, which also accepts a sibling directory whose name merely starts with the root: with `FITS_ROOT=/data/fits`, a path in `/data/fits-archive` passed. The prefix test now requires the separator, and checks both separators so a Windows `realpath()` result still matches a root configured with the other one
- **AstroBin export leaked metadata for files you cannot see** - `export_astrobin_csv.php` read `?ids=` off the query string and ran `WHERE id IN (...)` with no permission filter, so any user could pull object, date, exposure, filter, binning, gain, sensor temperature and FWHM for files under roots they have no access to. The endpoint is now permission-filtered
- **Open redirect on login** - the guard only rejected `..` and a leading `//`, but a browser normalises backslashes to slashes inside a `Location` value, so `/\evil.example` left as `//evil.example`. The redirect is now validated against its normalised form
- **A stale duplicates handler was left reachable** - a superseded inline renderer in `table.php` was never removed and left an unescaped path in the DOM; it is gone

### Major Fixes
- **An empty disk scan no longer soft-deletes the whole archive** - `soft_delete_missing_files()` treated an existing-but-empty fits directory as "every file is gone". That is exactly what a volume Docker created but did not mount looks like, and it marked every live row deleted and rebuilt all duplicate counts around it, with only a routine log line to show for it. An empty scan now aborts with an error and leaves the archive untouched; a genuine mass deletion still proceeds but warns once the absent share reaches 90%. `--skip-cleanup` and `purge_deleted_files` remain the explicit ways out
- **One unreadable FITS no longer stops the rest of the archive from indexing** - a failed `executemany()` batch was logged and then replayed on every later flush, because the batch was only cleared after a successful commit. From the first bad record (truncated file, oversized value, transient disk error) nothing else in the run was inserted, one error line per file, and the process still exited successfully. The failure handler now rolls back and drops the batch, so the run continues
- **The watcher retried a failing reindex every second** - `last_reindex` was stamped only on success, so a failure re-launched the entire archive walk and DB scan once per second, worst of all when the DB was down and that is exactly why it failed. Attempts are now stamped either way, with exponential backoff capped at 5 minutes
- **`RESCAN_INTERVAL` did nothing** - `--rescan-interval` / `RESCAN_INTERVAL` was parsed, stored and documented in `--help` as detecting deletions periodically, and `scan_and_detect()` was fully written, but nothing ever called it: inotify events lost to a queue overflow stayed invisible forever. The periodic walk is now wired into the main loop, self-throttled by the interval
- **Four-channel (OSC) frames rendered as noise** - `stf_autostretch_color` only stretched 3-channel images and returned 4-channel input untouched, so the caller multiplied raw sensor counts by 255 and wrapped them modulo 256. The PNG was valid, just full-range noise, with no error and no warning; every channel is now stretched independently
- **Calibration search was broken on every filtered search** - the endpoint validated filter ids against an undefined `$allFilters`, so any search carrying a filter (which is what the modal always sends) died in `array_keys()` before reaching the database. The filter catalogue is now loaded explicitly, and the id that becomes a column name in the query is whitelisted outright rather than relying on real column names happening to contain no backtick

### Minor Fixes
- The calibration search no longer ships thumbnails inside its JSON response
- AstroBin ids are posted in the request body instead of being put on the request line
- A file whose header cannot be read no longer counts as schema-upgraded, which previously advanced `data_schema_version` and left the new columns NULL forever with nothing left to retry them
- A frame with no `DATE-OBS` no longer prints a PHP deprecation into the Smart Frame Finder panel, which was leaking the server's absolute path and a line number to the browser
- Four API endpoints no longer pay for the full home page bootstrap, and a bad `search_type` is now rejected with JSON
- Toolbar layout uses `ml-auto` instead of `justify-between`
- The ad-hoc regression checks in `tmp/` are now a versioned `tests/` suite (50+ PHP/Python/JS gates) with a single `tests/run.sh` runner

> **Note for existing users:**
> ```bash
> git submodule update --init --recursive  # xisf bumped to v1.0.1
> ./build.sh build                         # required: the Python suggester is baked into the image
> docker compose down && docker compose up -d
> ```
> Migrations run automatically on PHP container start (23 new ones, including the
> project tables, per-project numbering, thresholds and new frame context columns).

## v1.4.0

### New Features
- **Image quality metrics** - every light frame now shows HFR, measured FWHM (in arcseconds), HFR spread, star eccentricity, star count, relative stellar SNR and relative PSF quality, so you can judge focus and seeing and sort your best frames. Computed automatically during indexing
- **Metrics trend charts** - collapsible card with one chart per metric plus its median line, plotting the rows of the current page (pagination is the natural limit)
- **Frame statistics for every frame type** - background level, min/max/mean/median pixel values, bit depth, channel count, color type and Bayer pattern. Also useful to check calibration frames (bias level, flat exposure, sensor type)
- **Choose your table columns** - the old "show advanced fields" checkbox is now a column picker dialog with all advanced and base columns (only the file name stays mandatory). Your choice is remembered via cookie
- **AstroBin export that AstroBin actually understands** - map each of your filter names to its AstroBin equipment ID once in the new mapping page, and exports will carry numeric filter IDs plus the session's mean FWHM. The export dialog warns about unmapped filters with a direct link to fix them


### Fixes & Improvements
- **Filter dropdowns show stale selections** - an active object/filter/type that doesn't exist in the current folder is now shown explicitly ("not in this folder") instead of misleadingly displaying "All" with an empty table
- **Folder tree root** - the sidebar now starts from `/` with the same arrow button as the other folders, so you can always go back to the full archive; the breadcrumb home label is now properly translated
- **Responsive page** - filters, statistics, charts and pagination now adapt to the screen width with no horizontal overflow; the file table scrolls horizontally on mobile while using full width on desktop

> **Note for existing archives:** frames indexed before this version show empty
> metric columns until backfilled (new files are computed automatically). To backfill,
> run once inside the running python container:
> ```bash
> docker exec awi-python python reindex.py /var/fits --backfill-star-metrics
> ```
> Depending on archive size this can take a while; the live watcher
> is not affected. To skip the analysis entirely (e.g. on slow hardware), set
> `STAR_METRICS_ENABLED=false` in your `.env`.

## v1.3.0

### New Features
- **Per-filter exposure statistics** - new collapsible card showing total exposure and percentage for each filter on the currently filtered image set, with color-coded progress bars for easy comparison
- **Exposure time range filter** - new min/max filter in seconds (open-ended: set only the minimum or only the maximum), applied to counts, table, total exposure and per-filter statistics

### Fixes & Improvements
- **Python DB password fallback** - `watch_fs.py` and `reindex.py` now use `DB_PASSWORD` with `DB_PASS` fallback

## v1.2.1

### Fixes & Improvements
- **Reduced watcher CPU usage** — `watch_fs.py` now auto-detects filesystem inotify support via `/proc/mounts`; uses native inotify on Linux (zero CPU idle) and falls back to `PollingObserver` on Docker Desktop/Windows (`fuse.grpcfuse`) or other FUSE/network mounts. Polling interval configurable via `POLL_INTERVAL` env var (default: 30s)
- **Container renamed** from `xyz-awi` to `awi-xyz` (e.g. `awi-mariadb`, `awi-php`, `awi-nginx`, `awi-python`)
- **HTTP security headers** added to nginx: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `Content-Security-Policy`, `X-Robots-Tag`

> **Note for existing users:** run `docker compose down && docker compose up -d` (not just restart) to apply the container rename.

## v1.2.0

### Authentication & Security
- **User authentication system** with two modes: `none` (legacy, no auth) and `full` (login required)
- **Directory-level permissions** — restrict user access to specific root directories
- **Root `/` permission** — grants full access to all directories via a single toggle
- **Admin panel** for user CRUD with directory checkboxes and last-admin protection
- **Protect direct file downloads** — nginx `auth_request` gates all `/fits/` access through PHP session/permission checks
- Auto-creation of initial admin user via `ADMIN_USER` / `ADMIN_PASSWORD` env vars
- CSRF protection on all forms
- i18n for all auth strings (en, it, fr, es, de)

### Features
- **GAIN FITS header indexing** — new `gain` column (camera gain setting), separate from the existing `egain` (electrons per ADU)
- **Schema upgrade system** — lightweight header-only reindex when new fields are added (no thumbnail regeneration)
- Fix: AstroBin CSV export now includes `filter` and uses `gain` instead of `egain`

### Performance
- **Faster ZIP downloads** — `STORE` compression for binary FITS/XISF files + nginx streaming (no buffering)

### Improvements
- **Permission-aware duplicate badges** — duplicate counts reflect only files the user can access
- **Duplicate sorting** — secondary sort by total count when ordering by visible duplicates
- Language selector on login and admin pages
- UI layout fixes for header when auth is enabled

## v1.1.0

Initial public release.
