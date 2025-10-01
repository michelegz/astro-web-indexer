# Astro Web Indexer

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

A web-based FITS file indexer and viewer for astronomical data, designed specifically for astronomical observatories and research institutions. This tool helps organize, browse, and analyze FITS (Flexible Image Transport System) files through an intuitive web interface.

## Project Status

**Beta Phase:** This project has been tested in a very limited environment and for a single use case. It should be considered in the beta phase. We appreciate any feedback and contributions to improve its stability and features.

## Preview
![Preview Screenshot](docs/images/preview.png)

## Features

### Core Functionality
- 📁 Browse and search FITS files in a directory structure
- 🔄 Real-time monitoring and automatic indexing of new files
- 🖼️ Built-in preview support for FITS images
- 🔍 Advanced filtering by object, filter type, and image type
- 📥 Bulk download functionality with ZIP compression

### User Experience
- 🌐 Multilingual interface (English, Italian, French, Spanish, and German)
- 📱 Responsive design for mobile and desktop
- 🎨 Modern, dark-themed interface
- ⚡ Fast and efficient file browsing

### Technical Features
- 🐳 Dockerized deployment for easy setup
- 🗄️ MariaDB backend for robust data storage
- 🔒 Secure file handling and access control
- 📊 FITS header metadata extraction and indexing

### Duplicate Management

The indexer includes a powerful suite for identifying and managing duplicate files, ensuring a clean and efficient archive.

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

## Requirements

- Docker
- Docker Compose

## Quick Start

1. Clone the repository:
```bash
git clone https://github.com/yourusername/astro-web-indexer.git
cd astro-web-indexer
```

2. Copy the example environment file and adjust as needed:
```bash
cp .env.example .env
```

3. Create the data directory for your FITS files:
```bash
mkdir -p data/fits
```

4. Start the application:
```bash
docker-compose up -d
```

5. Access the application at http://localhost:2080

## Configuration

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

### Database Connection

These variables are shared across all services to connect to the MariaDB container. **Ensure they are consistent everywhere.**

| Variable | Description | Default |
|----------|-------------|---------|
| `DB_HOST` | The hostname of the database service. Should match the service name in `docker-compose.yml`. | `mariadb` |
| `DB_NAME` | The name of the database to be created and used. | `awi_db` |
| `DB_USER` | The username for the database. | `awi_user` |
| `DB_PASSWORD` | The password for the database user. | `awi_password` |
| `MYSQL_ROOT_PASSWORD` | The root password for the MariaDB server. **It is highly recommended to change this.** | `rootpassword` |


## Directory Structure

```
astro-web-indexer/
├── docker/                    # Docker configuration files
├── src/                      # Application source code
├── scripts/                  # Utility scripts
└── tests/                    # Test files
```
## QNAP and Container Station

When building the PHP image on QNAP QTS, you may encounter errors like  
`Cannot change mode to rwxrwxr-x: Bad address`  
during the execution of `docker-php-ext-install pdo_mysql zip` in `docker/php/Dockerfile`.

This issue is likely caused by certain QNAP kernel or storage configurations.  
As a workaround, you can use the `docker-compose-qnap-sample.yml`, which relies on a different Dockerfile and entrypoint script with a few adjustments to bypass the problem.

**Note:** After the container is created, a restart may be required for it to work properly.


## Contributing

We welcome contributions! Here's how you can help:

1. 🍴 Fork the repository
2. 🌿 Create a feature branch: `git checkout -b feature/my-feature`
3. 💾 Commit your changes: `git commit -am 'Add: my feature'`
4. ⤴️ Push to the branch: `git push origin feature/my-feature`
5. 🔍 Submit a pull request

### Bug Reports
Please use the GitHub issue tracker and include:
- Detailed description of the issue
- Steps to reproduce
- Expected vs actual behavior
- Environment details

## License

This project is licensed under the GNU General Public License v3.0 - see the [LICENSE](LICENSE) file for details.

