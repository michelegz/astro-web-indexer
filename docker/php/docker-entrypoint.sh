#!/bin/sh

# Initial indexing and watcher if ENABLE_FITS_WATCHER is set to "true"
if [ "$ENABLE_FITS_WATCHER" = "true" ]; then

    # Run initial indexing
    echo "Running initial FITS indexing..."
    python /opt/scripts/reindex_mariadb.py /var/fits
    
    # Start the watcher
    echo "Starting FITS watcher..."
    python /opt/scripts/watch_fits.py /var/fits &
fi

# Start PHP-FPM (original php-fpm container command)
exec docker-php-entrypoint php-fpm