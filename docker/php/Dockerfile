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

# Copy the application source code
COPY src/ .

# Set version
ARG AWI_VERSION=unknown
ENV APP_VERSION=$AWI_VERSION
RUN echo $APP_VERSION > VERSION

# Now, with all files in place, run composer install.
RUN composer install --no-interaction --no-progress --no-dev --optimize-autoloader

# Copy and set permissions for the entrypoint script
COPY docker/php/docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]

CMD ["php-fpm"]