# Changelog

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
