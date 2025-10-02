FROM php:8.3-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    default-mysql-client \
    libzip-dev \
    zip \
    unzip \
 && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql zip

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html

# Copy the entire application source code first.
# This is a robust approach that always works.
COPY src/ .

# Now, with all files in place, run composer install.
RUN composer install --no-interaction --no-progress --no-suggest --no-dev --optimize-autoloader

# Copy and set permissions for the entrypoint script
COPY docker/php/docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]

CMD ["php-fpm"]