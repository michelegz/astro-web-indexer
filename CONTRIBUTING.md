# Contributing to Astro Web Indexer

This document provides guidelines for developers who want to build the project from source, make changes, or contribute to the project.

## Development Environment Setup

If you want to build the project from the source code instead of using the pre-built Docker images, you will need the following tools:

- Docker & Docker Compose
- Git
- Node.js and npm (for frontend asset building)
- An environment that can run Bash scripts (like Linux, macOS, or Git Bash/WSL on Windows).

### Quick Start for Development

1.  Clone the repository and its submodules:
    ```bash
    git clone --recurse-submodules https://github.com/michelegz/astro-web-indexer.git
    cd astro-web-indexer
    ```
    > **Note:** This project uses a Git submodule for the XISF library. If you have already cloned the repository without this flag, run `git submodule update --init` inside the project directory to download the necessary files.

2.  Copy the example environment file and adjust as needed:
    ```bash
    cp .env.example .env
    ```

3.  Install frontend dependencies:
    ```bash
    npm install
    ```
4.  Build and start the application using the provided script:
    ```bash
    ./build.sh build
    ```
    > **Note:** This command handles the entire build process, including compiling the CSS, building all Docker images with the correct version tag, and starting the services.

### Development stack

`./build.sh build` builds the images and starts the stack. The containers run
against your working copy: `src/`, the nginx config and the indexer scripts
are mounted into them, so a PHP edit is live on the next page reload, and
`npm run watch` recompiles the CSS without restarting anything.

Rebuild only when you change what the image carries and the mounts do not
cover: the PHP extensions, the Python dependencies, the base images.

Use `./build.sh` rather than a raw `docker compose`: the mounts live in
`docker-compose.dev.yml` and are applied by `build.sh`, not by Compose on its
own.

### Managing the Application with `build.sh`

The `build.sh` script provides several commands to manage the application's lifecycle:
- `./build.sh build`: Builds (or rebuilds) the Docker images and starts the services. Options:
  - `--no-cache`: Forces a complete rebuild without using Docker's cache.
  - `--tag-latest`: Additionally tags the build as 'latest'.
  - `--tag-dev`: Additionally tags the build as 'dev'.
- `./build.sh start`: Starts the containers without rebuilding.
- `./build.sh stop`: Stops the containers.
- `./build.sh logs`: Follows the logs from all running containers.
- `./build.sh save`: Exports the versioned Docker images to a `.tar` archive for manual transfer.
- `./build.sh push`: Pushes the built images to GitHub Container Registry. Options: `--tag-latest`, `--tag-dev`.

### Frontend Development
The project uses Tailwind CSS for styling. The `build.sh` script automatically compiles the required CSS file (`output.css`) from the source files.

If you are actively making changes to the UI, you may want to run the CSS compiler in "watch" mode to automatically regenerate the CSS on every change. To do this, run the following command in a separate terminal:
```bash
npm run watch
```

## Advanced Usage & Scripts

The application's backend logic is handled by two main Python scripts located in `docker/python/`.

### `watch_fs.py` (The Watcher)
This script runs continuously in the background inside the `python` container. Its only job is to monitor the data directory (`FITS_DATA_PATH`) for file changes. When a change is detected, it automatically calls `reindex.py` to update the database.

### `reindex.py` (The Indexer)
This is the core script that scans the data directory, extracts metadata from FITS/XISF files, generates thumbnails, and updates the database records. You can run it manually for specific tasks using `docker exec`:

**Example: Forcing a full re-index**
```bash
docker exec -it awi-python python /opt/scripts/reindex.py /var/fits --force
```

**Manual Execution and Options**

You can run the script manually for specific tasks using `docker exec`. The script accepts several command-line arguments to override default behaviors.

**Example: Forcing a full re-index with 8 workers**
```bash
docker exec -it awi-python python /opt/scripts/reindex.py /var/fits --debug --force --workers 8
```

**Command-Line Arguments:**

| Argument | Environment Variable | Description | Default |
|---|---|---|---|
| `fits_root` | - | **(Required)** The root directory containing image files. Inside the container, this is always `/var/fits`. | - |
| `--host` | `DB_HOST` | The MariaDB host. | `mariadb` |
| `--user` | `DB_USER` | The database username. | `awi_user` |
| `--password` | `DB_PASSWORD` (`DB_PASS` legacy fallback) | The database password. | `awi_password` |
| `--database` | `DB_NAME` | The database name. | `awi_db` |
| `--force` | - | Forces the script to re-process every file, ignoring modification times and hashes. | `false` |
| `--workers` | `INDEXER_WORKERS` | The number of parallel worker processes to use for indexing. | `4` |
| `--thumb-size` | `THUMB_SIZE` | The size (width and height) in pixels for generated thumbnails. | `300` |
| `--skip-cleanup` | - | Prevents the script from marking files as deleted if they are no longer found on disk. | `false` |
| `--retention-days`| `RETENTION_DAYS` | The number of days to keep a soft-deleted file record before it is permanently purged. | `30` |
| `--debug` | `DEBUG` | Enables verbose debug logging for the indexing script. | `false` |
| `--star-metrics` / `--no-star-metrics` | `STAR_METRICS_ENABLED` | Compute star/quality metrics for LIGHT frames (plus pixel statistics for every frame). | `true` |
| `--backfill-star-metrics` | - | Reprocess pixel data of files still missing computed metrics (LIGHT frames without HFR, any frame without pixel statistics), then continue normally. | `false` |
| `--recompute-star-metrics` | - | Recompute star metrics for ALL LIGHT frames, even ones already computed (e.g. after a formula change). Skips thumbnails like backfill. | `false` |
| `--phase-mode` | `INDEXER_PHASE_MODE` | How the indexing is split: `two` (default) writes each file first, so it shows up in the frontend, and computes the star metrics after; `single` does everything in one pass. | `two` |
| `--star-metrics-profile` | `STAR_METRICS_PROFILE` | How the star metrics are measured: `fast` (bright, well resolved stars only, about twice as fast), `default` (5.0 sigma, minimum area 9 px), `sensitive` (every faint source counted, about a third slower). | `default` |

**The two passes**

The default mode writes the row in a fast pass (header, preview, frame statistics) and
computes the star metrics in a slow one, so a big archive becomes browsable long before
the numbers are ready. A row that still owes its metrics carries `files.metrics_pending = 1`
and shows a "metrics pending" marker in the table; a run interrupted in between is picked
up again by the next one, because the flag is committed in batches.

Both modes share the same extraction (`indexer_lib/frame_extract.py`), so they cannot
drift apart. `single` decodes the pixels once instead of twice, at the cost of showing
nothing until the metrics are known.

**The measurement profiles**

A profile sets the two knobs that decide which stars a frame has: the detection
threshold and the minimum area. Measured on this project's own archive (an 8.5 Mpx Ha
frame), `fast` costs 0.54x the time and moves the HFR by -2.2%, `sensitive` costs 1.31x
and moves it by +3.6%. The FWHM does not move: it is measured on the brightest 200
stars, and every profile detects those.

The knobs live in `indexer_lib/star_analysis.py`, which exposes `apply_profile()` and
`resolve_profile()`. An unknown name never raises: it warns and falls back to
`default`. The profile is resolved once by `reindex.py` and handed down to the workers
through the pickled arguments, because the workers are spawned processes and do not
inherit the parent's runtime state.

Changing the profile does not touch the files already indexed: re-measure them with
`--recompute-star-metrics`, or the archive keeps both measurements.


## 📁 Directory Structure
```
astro-web-indexer/
├── docker/                    # Docker configuration files
├── external/                  # Git submodules for external libraries (e.g., XISF)
├── src/                       # Application source code
├── build-css.js               # Helper script for building CSS
├── build.sh                   # Main build script for development
├── CONTRIBUTING.md            # This file
├── docker-compose.yml         # Docker Compose file for development
├── package.json               # Node.js dependencies
└── tailwind.config.js         # Tailwind CSS configuration
```

## How to Contribute

We welcome contributions! All pull requests should be made to the `dev` branch. The `main` branch is reserved for stable releases.

1.  🍴 Fork the repository.
2.  🌿 Create a feature branch from `dev`: `git checkout -b feature/my-new-feature dev`.
3.  💾 Commit your changes: `git commit -am 'feat: Add some feature'`.
4.  ⤴️ Push to your forked repository: `git push origin feature/my-new-feature`.
5.  🔍 Submit a pull request to the `dev` branch of the main repository.

### Database Migrations
This project uses **Phinx** to manage database schema changes. Migrations are applied automatically when the application starts.

If you need to make schema changes, you should create a new migration file using the Phinx CLI. You can run Phinx commands inside the `php` container:
```bash
docker exec -it awi-php vendor/bin/phinx create MyNewMigration
```

### Versioning
This project's version is automatically determined from Git tags. The `build.sh` script reads the latest Git tag (or commit hash) and passes it to Docker during the build process.

To release a new version, simply create and push a new tag before running the build script:
```bash
# Example for version 1.1.0
git tag v1.1.0
git push origin v1.1.0
```

### Bug Reports
Please use the GitHub issue tracker and include:
- A detailed description of the issue.
- Steps to reproduce.
- Expected vs actual behavior.
- Environment details (OS, Docker version, etc.).
