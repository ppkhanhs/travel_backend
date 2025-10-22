FROM php:8.2-fpm

# Install required extensions
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    unzip \
    zip \
    git \
    curl \
    && docker-php-ext-install pdo pdo_pgsql zip

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer manifests and install dependencies
COPY composer.json composer.lock ./

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_MEMORY_LIMIT=-1

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# Copy application source
COPY . .

# Run framework discovery scripts now that source is present
RUN php artisan package:discover --ansi || true

# Ensure proper permissions for writable directories
RUN chown -R www-data:www-data storage bootstrap/cache

# Expose port used by Laravel
EXPOSE 8000

# Start Laravel application using the port provided by the platform
CMD ["sh", "-c", "php artisan serve --host=0.0.0.0 --port=${PORT:-8000}"]
