# <img src="src/assets/logo/default_logo.svg" alt="Astro Web Indexer" width="64" style="vertical-align: middle;"> Astro Web Indexer

*Stop navigating folders. Start exploring your sky.*

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

A web-based file indexer and viewer for astronomical data, supporting both FITS and XISF formats. This tool is particularly useful for shared or remote observatories, but also for any astrophotographer looking to track their imaging sessions over time without getting lost navigating filesystem folders. It helps organize, browse, and analyze image files through an intuitive web interface.

## 🧪 Project Status

**Beta Phase:** This project has been tested in a very limited environment and for a single use case. It should be considered in the beta phase. I appreciate any feedback and contributions to improve its stability and features.

## ▶️ Preview
![Preview Screenshot](docs/images/preview.png)

## ❤️ Support the Project

This project is developed and maintained in my spare time. If you find it useful, please consider supporting its development with a small donation. Thank you!

[![Donate](https://img.shields.io/badge/Donate-PayPal-blue.svg)](https://www.paypal.com/donate/?business=Y85DQZAEYTF94&no_recurring=0&currency_code=EUR)

## 🚀 Features

### Core Functionality
- 📁 Browse and search FITS and XISF files in a directory structure
- 🔍 Advanced filtering by object, filter type, and image type
- 🔄 Real-time monitoring and automatic indexing of new files
- 🖼️ Built-in preview generation for both FITS and XISF images with a non linear STF stretch
- 🔬 **Smart Frame Finder (SFF):** A powerful, context-aware search engine to find the right calibration frames (darks, flats, bias) for your lights using a flexible, tolerance-based rules engine.
- 📥 Bulk download functionality with ZIP compression

### AstroBin Integration
- **CSV Export for Sessions:** Select multiple files (lights, darks, flats, bias) and copy a pre-formatted CSV string to your clipboard, ready to be pasted into AstroBin's session importer.
- **Smart Session Aggregation:** The exporter intelligently groups exposures into "astro-nights" (from noon to noon), correctly handling sessions that span across midnight.
- **Calibration Frame Counting:** Automatically counts the number of selected dark, flat, and bias frames and adds them to the session data.

### Duplicate Management
The indexer includes a powerful suite for identifying and managing duplicate files, ensuring a clean and efficient archive.
- **Content-Based Duplicate Detection:** Files are identified as duplicates based on their content hash (`xxhash`), regardless of their name or location.
- **Smart Duplicate Badge:** The main file table displays an intelligent badge to manage duplicate sets.
- **Interactive Management Modal:** A dedicated interface to view, hide, and restore duplicate files.

### User Experience
- 🌐 Multilingual interface (English, Italian, French, Spanish, and German)
- 📱 Responsive design for mobile and desktop
- 🎨 Modern, dark-themed interface
- ⚡ Fast and efficient file browsing

### Technical Features
- 🐳 Dockerized deployment for easy setup
- 🗄️ MariaDB backend with schema migrations managed by **Phinx**.
- 🔒 Secure file handling and access control
- 📊 Extensive FITS/XISF header metadata extraction and indexing.

*A more detailed description of the SFF, Duplicate Management, and Indexing engine can be found below.*

### 🔬 Smart Frame Finder (SFF)
Finding the right calibration frames (darks, flats, bias) for your light frames can be tedious. The Smart Frame Finder automates this process with a powerful, configurable search engine.
- **Context-Aware Search:** Start a search directly from a `LIGHT` frame to find matching calibration files. The system knows which parameters are relevant for each calibration type (e.g., `EXPTIME` for darks, `FILTER` for flats).
- **Flexible Rules Engine:** Activate and deactivate search criteria on the fly. You can match by `CCD-TEMP`, `BINNING`, `INSTRUME`, and many other FITS headers.
- **Tolerance Sliders:** Don't need an exact match? Use intuitive sliders to define acceptable tolerances for parameters like temperature (`±2°`), date (`±30 days`), or exposure (`±10%`).
- **Dedicated Interface:** The SFF operates in a dedicated modal window, allowing you to build complex queries, view results, and download a `.zip` archive of the selected frames without leaving the main page.

### Duplicate Management
- **Content-Based Duplicate Detection:** Files are identified as duplicates based on their content hash (`xxhash`), regardless of their name or location. If two files have the same hash, they are considered duplicates.
- **Smart Duplicate Badge:** The main file table displays an intelligent badge in the format `Visible / Total` for files that have duplicates.
  - `5 / 5` (Yellow): Indicates 5 duplicates exist, and all are currently visible.
  - `1 / 5` (Gray): Indicates that this is the only visible file out of 5 duplicates. The other 4 have been hidden by the user.
- **Interactive Management Modal:** Clicking the badge opens a detailed modal window where users can:
  - View all duplicate files in a comprehensive table.
  - Designate a "reference" file that cannot be hidden.
  - Select and hide redundant duplicates to declutter the main view.
  - View and restore previously hidden files.
- **Sort by Duplicates:** The main table can be sorted by the number of visible duplicates, making it easy to find and manage files with the most copies.


### Resilient Indexing & Soft-Delete

The indexing engine is designed to be both efficient and resilient, making it suitable for managing large and dynamic data archives.

- **Fast Rescans:** The indexer uses a combination of file modification time (`mtime`) and size to quickly skip files that have not changed since the last scan. This makes subsequent indexing runs extremely fast.
- **Content-Based Identification:** Files are uniquely identified by their `xxhash`, a high-speed hashing algorithm.
- **Soft-Delete Recovery:** When a file is removed from the filesystem, it is not immediately deleted from the database. Instead, it is marked as "deleted" for a configurable retention period (default: 30 days). This provides a safety net against accidental deletions or temporary filesystem unavailability. If the file reappears within the retention period, it is instantly restored without need of full reindexing and hash calculation.

## 📋 Requirements

- Docker
- Docker Compose

## ⚡ Quick Start

> **Note:** This project uses a `build.sh` script to simplify the Docker build process. This script is compatible with Linux, macOS, and Windows (using Git Bash or WSL).

1. Clone the repository and its submodules:
```bash
git clone --recurse-submodules https://github.com/michelegz/astro-web-indexer.git
cd astro-web-indexer
```
> **Note:** This project uses a Git submodule for the XISF library. If you have already cloned the repository without this flag, run `git submodule update --init` inside the project directory to download the necessary files.

2. Copy the example environment file and adjust as needed:
```bash
cp .env.example .env
```

3. Prepare your FITS/XISF data directory.

   By default, the application looks for images inside a `./data/fits` directory. If you want to use this default, create it now:
   ```bash
   mkdir -p data/fits
   ```
   **Alternatively**, if you already have an image folder, you can edit the `.env` file and set `FITS_DATA_PATH` to your custom path (e.g., `FITS_DATA_PATH=/path/to/my/images`).

4. Build and start the application using the provided script:
```bash
./build.sh build
```
This command handles the entire build process, including automatically embedding the application version from Git.

5. Access the application at http://localhost:2080

### Managing the Application

The `build.sh` script provides several commands to manage the application's lifecycle:
- `./build.sh build`: Builds (or rebuilds) the Docker images and starts the services.
- `./build.sh build --no-cache`: Forces a complete rebuild of the images without using Docker's cache. This is useful when you have issues with outdated dependencies.
- `./build.sh start`: Starts the containers without rebuilding.
- `./build.sh stop`: Stops the containers.
- `./build.sh logs`: Follows the logs from all running containers.
- `./build.sh clean`: Stops the containers and removes any temporary files generated during the build.
- `./build.sh save`: Exports the versioned Docker images to a `.tar` archive for manual transfer.


## ⚙️ Configuration

All configuration is handled via environment variables, typically set in a `.env` file.

### Core Application

| Variable | Description | Default |
|----------|-------------|---------|
| `NGINX_PORT` | The port to expose the web interface on the host machine. | `2080` |
| `HEADER_TITLE` | The main title displayed in the application header. | `Astro Web Indexer` |
| `FITS_DATA_PATH` | The **host path** to the directory containing your FITS files. This directory will be mounted into the containers. | `./data/fits` |

### Indexing Service

These variables control the behavior of the Python indexing and watching scripts.

| Variable | Description | Default |
|----------|-------------|---------|
| `RETENTION_DAYS` | The number of days to keep a record of a deleted file in the database before it is permanently purged. Set to `0` to disable purging. | `30` |
| `DEBUG` | Enables verbose debug logging for the indexing scripts. Set to `true` or `false`. | `false` |
| `THUMB_SIZE` | The size (width and height) in pixels for generated thumbnails. | `300` |

### Database Connection

These variables are shared across all services to connect to the MariaDB container. **Ensure they are consistent everywhere.**

| Variable | Description | Default |
|----------|-------------|---------|
| `DB_HOST` | The hostname of the database service. Should match the service name in `docker-compose.yml`. | `mariadb` |
| `DB_NAME` | The name of the database to be created and used. | `awi_db` |
| `DB_USER` | The username for the database. | `awi_user` |
| `DB_PASSWORD` | The password for the database user. | `awi_password` |
| `MYSQL_ROOT_PASSWORD` | The root password for the MariaDB server. **It is highly recommended to change this.** | `rootpassword` |

### 🎨 Custom Logo

You can replace the default logo with your own by mapping a local SVG file. 

1.  Open the `docker-compose.yml` file.
2.  Locate the `php` service.
3.  Uncomment the volume mapping for the custom logo and replace `./path/to/your/logo.svg` with the actual path to your file.

```yaml
services:
  php:
    # ... other settings
    volumes:
      - ${FITS_DATA_PATH:-./data/fits}:/var/fits:ro
      # To use a custom logo, uncomment the following line and
      # replace ./path/to/your/logo.svg with the actual path to your logo file.
      - ./path/to/your/logo.svg:/var/www/html/assets/logo/custom_logo.svg:ro
```
The application will automatically use your logo. If this volume is not mapped, it will fall back to the default logo.


## 🛠️ Advanced Usage & Scripts

The application's backend logic is handled by two main Python scripts located in `docker/python/`.

### `watch_fs.py` (The Watcher)
This script runs continuously in the background inside the `python` container. Its only job is to monitor the data directory (`FITS_DATA_PATH`) for file changes (creations, modifications, deletions). When a change is detected, it waits for a brief cooldown period and then automatically calls `reindex.py` to update the database. You generally do not need to interact with this script directly.

### `reindex.py` (The Indexer)
This is the core script that performs the heavy lifting: it scans the data directory, extracts metadata from FITS/XISF files, generates thumbnails, and updates the database records.

While the watcher runs this script automatically, you may need to run it manually for specific tasks, such as forcing a full re-index of all files. To do this, you can use `docker exec`:

**Example: Forcing a full re-index**
This command is useful if you change the thumbnail generation logic, suspect data corruption, or simply want to ensure everything is perfectly synchronized.

```bash
docker exec -it python-awi python /opt/scripts/reindex.py /var/fits --force
```

**Key Manual Options:**
- `--force`: Forces the script to re-process every file, even if its modification time and size haven't changed.
- `--skip-cleanup`: Prevents the script from marking files as deleted if they are no longer found on disk. This is useful if your data disk is temporarily disconnected.
- `--thumb-size <size>`: Overrides the `THUMB_SIZE` environment variable for a single run, allowing you to test different thumbnail sizes without restarting the container. For example: `--thumb-size 250`.


## 📁 Directory Structure

```
astro-web-indexer/
├── docker/                    # Docker configuration files
├── external/                  # Git submodules for external libraries (e.g., XISF)
└── src/                       # Application source code
```

## 🤝 Contributing

We welcome contributions! All pull requests should be made to the `dev` branch. The `main` branch is reserved for stable releases.

Here's how you can help:

1. 🍴 Fork the repository
2. 🌿 Create a feature branch from `dev`: `git checkout -b feature/my-feature dev`
3. 💾 Commit your changes: `git commit -am 'Add: my feature'`
4. ⤴️ Push to your forked repository: `git push origin feature/my-feature`
5. 🔍 Submit a pull request to the `dev` branch of the main repository.

### Database Migrations
This project uses **Phinx** to manage database schema changes. Migrations are applied automatically when the application starts, ensuring the database is always up-to-date. Developers wishing to contribute schema changes should familiarize themselves with the Phinx workflow.

### Versioning

This project's version is automatically determined from Git tags. The `build.sh` script reads the latest Git tag (or commit hash) and passes it to Docker during the build process. This version is then displayed in the application's footer.

To release a new version, simply create and push a new tag before running the build script:

```bash
# Example for version 1.0.0
git tag v1.0.0
git push origin v1.0.0
```
The next time you run `./build.sh build`, the new version number will be embedded in the application. If no tags are present, a development version based on the commit hash will be used.

### Bug Reports
Please use the GitHub issue tracker and include:
- Detailed description of the issue
- Steps to reproduce
- Expected vs actual behavior
- Environment details

## 📜 License

This project is licensed under the GNU General Public License v3.0 - see the [LICENSE](LICENSE) file for details.

## 🙏 Acknowledgements

This project is built upon the hard work of many open-source projects, including:

- **[XISF Python Library](https://github.com/sergio-dr/xisf)** by Sergio Díaz for XISF file support.
- **[Astropy](https://www.astropy.org/)** for FITS file handling and astronomical calculations.
- **[Watchdog](https://github.com/gorakhargosh/watchdog)** for file system monitoring.
- **PHP**, **Python**, **MariaDB**, and **Nginx** as the core technology stack.
- **[Tailwind CSS](https://tailwindcss.com/)** for the user interface design.


