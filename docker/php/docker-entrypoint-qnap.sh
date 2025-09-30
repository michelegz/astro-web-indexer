#!/bin/sh

# for some reason pdo_mysql and zip can't be installed during build on qnap (kernel bug?), so we force it here
cd /tmp
docker-php-ext-install pdo_mysql zip

# Initial indexing and watcher if ENABLE_FITS_WATCHER is set to "true"
if [ "$ENABLE_FITS_WATCHER" = "true" ]; then

    # Run initial indexing and start watcher afterward, all in background
    echo "Running initial FITS indexing and starting watcher afterward..."
    (python /opt/scripts/reindex_mariadb.py /var/fits && \
     python /opt/scripts/watch_fits.py /var/fits) &
else
    # Run initial indexing in background without watcher
    echo "Running initial FITS indexing in background (watcher disabled)..."
    python /opt/scripts/reindex_mariadb.py /var/fits &
fi

# Start PHP-FPM (original php-fpm container command)
exec docker-php-entrypoint php-fpm
