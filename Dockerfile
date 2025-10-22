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

# Expose default port
EXPOSE 8000

# Start Laravel using PHP built-in server bound to Render's port
CMD ["sh", "-c", "php -d variables_order=EGPCS -S 0.0.0.0:${PORT:-8000} -t public public/index.php"]
