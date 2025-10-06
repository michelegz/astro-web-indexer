#!/bin/sh
set -e

# Run database migrations
echo "Running database migrations..."
vendor/bin/phinx migrate

# Execute the command passed to the script (e.g., "php-fpm")
echo "Starting PHP-FPM..."
exec "$@"
