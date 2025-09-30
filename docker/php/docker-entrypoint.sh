#!/bin/sh

AWI_DIR="/usr/local/share/awi"
PHP_INIT_FLAG="$AWI_DIR/php_init_done"

if [ ! -f "$PHP_INIT_FLAG" ]; then
    echo "Installing composer..."
    cd /var/www/html || exit 1
    
    if composer require maennchen/zipstream-php; then
        mkdir -p "$AWI_DIR"
        touch "$PHP_INIT_FLAG"
        echo "Composer installed successfully"
    else
        echo "ERROR: Failed to install composer" >&2
        exit 1
    fi
else
    echo "Composer already installed, skipping..."
fi


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