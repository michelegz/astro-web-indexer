FROM nginx:1.27

# Copy the Nginx configuration file
COPY ./docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Set working directory
WORKDIR /var/www/html

# Copy the application source code
COPY ./src .
