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
docker exec -it python-awi python /opt/scripts/reindex.py /var/fits --force
```

**Manual Execution and Options**

You can run the script manually for specific tasks using `docker exec`. The script accepts several command-line arguments to override default behaviors.

**Example: Forcing a full re-index with 8 workers**
```bash
docker exec -it python-awi python /opt/scripts/reindex.py /var/fits --debug --force --workers 8
```

**Command-Line Arguments:**

| Argument | Environment Variable | Description | Default |
|---|---|---|---|
| `fits_root` | - | **(Required)** The root directory containing image files. Inside the container, this is always `/var/fits`. | - |
| `--host` | `DB_HOST` | The MariaDB host. | `mariadb` |
| `--user` | `DB_USER` | The database username. | `awi_user` |
| `--password` | `DB_PASS` | The database password. | `awi_password` |
| `--database` | `DB_NAME` | The database name. | `awi_db` |
| `--force` | - | Forces the script to re-process every file, ignoring modification times and hashes. | `false` |
| `--workers` | `INDEXER_WORKERS` | The number of parallel worker processes to use for indexing. | `4` |
| `--thumb-size` | `THUMB_SIZE` | The size (width and height) in pixels for generated thumbnails. | `300` |
| `--skip-cleanup` | - | Prevents the script from marking files as deleted if they are no longer found on disk. | `false` |
| `--retention-days`| `RETENTION_DAYS` | The number of days to keep a soft-deleted file record before it is permanently purged. | `30` |
| `--debug` | `DEBUG` | Enables verbose debug logging for the indexing script. | `false` |


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
docker exec -it php-awi vendor/bin/phinx create MyNewMigration
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
