FROM php:8.2-apache

# Install PostgreSQL PHP extensions
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy application files to the Apache document root
COPY . /var/www/html/

# Expose port 80
EXPOSE 80
