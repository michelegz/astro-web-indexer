FROM php:8.3-fpm

# Install Python, pip, venv and required dependencies
RUN apt-get update && apt-get install -y \
    python3 \
    python3-pip \
    python3-venv \
    python3-xxhash \
    default-mysql-client \
    libzip-dev \
    zip \
    unzip \
 && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql zip

RUN cd /tmp && (curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer)
RUN composer require maennchen/zipstream-php

# Create virtualenv in /opt/venv
RUN python3 -m venv /opt/venv

# Install Python libraries in the virtualenv
RUN /opt/venv/bin/pip install --no-cache-dir astropy pillow mysql-connector-python watchdog xxhash

# Make virtualenv's python and pip the default
ENV PATH="/opt/venv/bin:$PATH"

# Copy and set permissions for the entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Set the new entrypoint
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]