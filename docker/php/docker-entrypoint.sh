#!/bin/sh

# Set the working directory
cd /var/www/html || exit 1

# Check if vendor directory exists. If not, run composer install.
if [ ! -d "vendor" ]; then
    echo "Vendor directory not found. Installing composer dependencies..."
    composer install --no-interaction --no-progress --no-suggest
else
    echo "Vendor directory already exists, skipping composer install."
fi

# Execute the command passed to the script (e.g., "php-fpm")
exec "$@"
