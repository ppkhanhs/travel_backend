FROM php:8.2-fpm

# Cài các extension Laravel cần
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    unzip \
    zip \
    git \
    curl \
    && docker-php-ext-install pdo pdo_pgsql zip

# Cài Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Tạo thư mục dự án
WORKDIR /var/www/html

# Copy toàn bộ mã nguồn
COPY . .

# Cài đặt Laravel
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# Set quyền
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Cổng chạy PHP-FPM
EXPOSE 8000

CMD ["php-fpm"]

