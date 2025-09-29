#!/bin/sh

# Start the watcher if ENABLE_FITS_WATCHER is set to "true"
if [ "$ENABLE_FITS_WATCHER" = "true" ]; then
    echo "Starting FITS watcher..."
    python /opt/scripts/watch_fits.py /var/fits &
fi

# Start PHP-FPM (original php-fpm container command)
exec docker-php-entrypoint php-fpm