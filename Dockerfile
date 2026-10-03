# syntax=docker/dockerfile:1

# ---------- Stage 1: build assets (Vite/Tailwind) ----------
FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci || npm install
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# ---------- Stage 2: PHP-FPM runtime ----------
# Selaras dengan composer.lock (symfony 8.1 butuh PHP >= 8.4.1)
# dan mesin dev (PHP 8.4.x). composer.json "php": "^8.2" mencakup 8.4.
FROM php:8.4-fpm-alpine AS runtime

# Ekstensi yang dibutuhkan Laravel + proyek ini (MySQL, GD untuk gambar,
# intl, bcmath untuk perhitungan poin/rupiah).
RUN apk add --no-cache \
        bash \
        git \
        curl \
        icu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        oniguruma-dev \
        mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        intl \
        zip \
        opcache

# Composer resmi.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# OPcache dioptimalkan untuk produksi.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.jit=tracing'; \
        echo 'opcache.jit_buffer_size=64M'; \
    } > /usr/local/etc/php/conf.d/opcache-prod.ini

# Upload Laravel 12 max 5120KB (WASTE_MAX_IMAGE_KB/MITRA_MAX_FILE_KB) + buffer.
RUN { \
        echo 'upload_max_filesize=12M'; \
        echo 'post_max_size=12M'; \
        echo 'memory_limit=256M'; \
        echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# Dependency dulu (layer cache) — hanya file composer.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader \
    && rm -rf /root/.composer

# Salin sisa kode backend.
COPY . .

# Ambil hasil build Vite dari stage 1 (dua lokasi):
# 1. public/build       → dipakai normal.
# 2. /opt/app-build     → cadangan untuk disalin ke volume bersama nginx.
COPY --from=assets /app/public/build ./public/build
RUN mkdir -p /opt/app-build && cp -a ./public/build/. /opt/app-build/

# Jalankan post-autoload (package:discover) setelah semua kode ada.
RUN composer dump-autoload --no-dev --optimize \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
