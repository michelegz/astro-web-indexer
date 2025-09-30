#!/bin/sh

# for some reason pdo_mysql and zip can't be installed during build on qnap (kernel bug?), so we force it here with this quirk

AWI_DIR="/usr/local/share/awi"
PHP_INIT_FLAG="$AWI_DIR/php_init_done"


if [ ! -f "$PHP_INIT_FLAG" ]; then
    echo "Initializing PHP environment..."

    # Creiamo la cartella di lavoro se non esiste
    mkdir -p "$AWI_DIR"
    cd "$AWI_DIR" || exit 1

    # Installiamo le estensioni PHP
    echo "Installing PHP extensions..."
    if docker-php-ext-install pdo_mysql zip; then
        echo "PHP extensions installed successfully"
    else
        echo "ERROR: Failed to install PHP extensions" >&2
        exit 1
    fi

    # Installiamo il pacchetto Composer
    echo "Installing composer package maennchen/zipstream-php..."
    if composer require maennchen/zipstream-php; then
        echo "Composer package installed successfully"
    else
        echo "ERROR: Failed to install composer package" >&2
        exit 1
    fi

    # Creiamo il flag per non ripetere l'inizializzazione
    touch "$PHP_INIT_FLAG"
    echo "PHP environment initialized successfully"

else
    echo "PHP environment already initialized, skipping..."
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
