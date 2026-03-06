#!/bin/sh
set -e

# Run database migrations
echo "Running database migrations..."
vendor/bin/phinx migrate

# Create initial admin user if AUTH_MODE is set and no users exist
if [ "$AUTH_MODE" != "" ] && [ "$AUTH_MODE" != "none" ]; then
    if [ -n "$ADMIN_USER" ] && [ -n "$ADMIN_PASSWORD" ]; then
        php -r "
            require_once '/var/www/html/includes/config.php';
            require_once '/var/www/html/includes/db_functions.php';
            require_once '/var/www/html/includes/auth.php';
            \$conn = connectDB();
            if (!hasAnyUser(\$conn)) {
                createUser(\$conn, getenv('ADMIN_USER'), getenv('ADMIN_PASSWORD'), true, true, []);
                echo \"Initial admin user created.\n\";
            }
        "
    fi
fi

# Execute the command passed to the script (e.g., "php-fpm")
echo "Starting PHP-FPM..."
exec "$@"
