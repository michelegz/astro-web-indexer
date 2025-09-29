# Astro Web Indexer

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

A web-based FITS file indexer and viewer for astronomical data, designed specifically for astronomical observatories and research institutions. This tool helps organize, browse, and analyze FITS (Flexible Image Transport System) files through an intuitive web interface.

![Preview Screenshot](docs/images/preview.png)

## Features

### Core Functionality
- 📁 Browse and search FITS files in a directory structure
- 🔄 Real-time monitoring and automatic indexing of new files
- 🖼️ Built-in preview support for FITS images
- 🔍 Advanced filtering by object, filter type, and image type
- 📥 Bulk download functionality with ZIP compression

### User Experience
- 🌐 Multilingual interface (English and Italian)
- 📱 Responsive design for mobile and desktop
- 🎨 Modern, dark-themed interface
- ⚡ Fast and efficient file browsing

### Technical Features
- 🐳 Dockerized deployment for easy setup
- 🗄️ MariaDB backend for robust data storage
- 🔒 Secure file handling and access control
- 📊 FITS header metadata extraction and indexing

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

All configuration can be done through environment variables. See `.env.example` for available options.

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| NGINX_PORT | The port to expose the web interface | 2080 |
| HEADER_TITLE | The title shown in the header | Astro Web Indexer |
| FITS_DATA_PATH | Path to FITS files directory | ./data/fits |
| ENABLE_FITS_WATCHER | Enable automatic file indexing | true |
| DB_* | Database connection settings | See .env.example |

## Development

For development, you can use the development compose file:

```bash
docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d
```

This will enable:
- Source code hot-reload
- XDebug for PHP debugging
- Development-specific settings

## Directory Structure

```
astro-web-indexer/
├── docker/                    # Docker configuration files
├── src/                      # Application source code
├── scripts/                  # Utility scripts
└── tests/                    # Test files
```

## Performance Considerations

### Hardware Requirements
- **CPU**: Dual core or better
- **RAM**: 2GB minimum, 4GB recommended
- **Storage**: Depends on FITS file collection size
- **Network**: Fast network connection recommended for large files

### Scalability
The application is designed to handle large collections of FITS files efficiently:
- Paginated file browsing
- Optimized database queries
- Thumbnail caching
- Efficient file monitoring

## Contributing

We welcome contributions! Here's how you can help:

1. 🍴 Fork the repository
2. 🌿 Create a feature branch: `git checkout -b feature/my-feature`
3. 💾 Commit your changes: `git commit -am 'Add: my feature'`
4. ⤴️ Push to the branch: `git push origin feature/my-feature`
5. 🔍 Submit a pull request

### Code Style
- Follow PSR-12 for PHP code
- Use meaningful variable and function names
- Add comments for complex logic
- Write tests for new features

### Bug Reports
Please use the GitHub issue tracker and include:
- Detailed description of the issue
- Steps to reproduce
- Expected vs actual behavior
- Environment details

## Support

Need help? Here are some resources:
- 📖 [Documentation](docs/README.md)
- 🐛 [Issue Tracker](../../issues)
- 💬 [Discussions](../../discussions)

## License

This project is licensed under the GNU General Public License v3.0 - see the [LICENSE](LICENSE) file for details.

### Why GPL?
We chose the GPL license to ensure that:
- The software remains free and open source
- Modifications and derivatives are also free and open source
- Users have the freedom to run, study, share, and modify the software

## Credits

Created and maintained by Michele Guzzini.

Special thanks to:
- Centro Astronomico Gianclaudio Ciampechini
- All [contributors](../../contributors)