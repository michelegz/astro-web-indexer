# Changelog

## Unreleased

### New Features
- **Image quality metrics** - every light frame now shows HFR, FWHM (in arcseconds), star eccentricity, star count, SNR weight and PSF signal, so you can judge focus and seeing and sort your best frames. Computed automatically during indexing
- **Metrics trend charts** - collapsible card with one chart per metric plus its median line, following the current table order; the header tells how many light frames are included
- **Frame statistics for every frame type** - background level, min/max/mean/median pixel values, bit depth, channel count, color type and Bayer pattern. Also useful to check calibration frames (bias level, flat exposure, sensor type)
- **Choose your table columns** - the old "show advanced fields" checkbox is now a column picker dialog with all advanced and base columns (only the file name stays mandatory). Your choice is remembered via cookie
- **AstroBin export that AstroBin actually understands** - map each of your filter names to its AstroBin equipment ID once in the new mapping page, and exports will carry numeric filter IDs plus the session's mean FWHM. The export dialog warns about unmapped filters with a direct link to fix them
- **Folder tree root** - the sidebar now starts from `/` with the same arrow button as the other folders, so you can always go back to the full archive; the breadcrumb home label is now properly translated

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
