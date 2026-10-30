# syntax=docker/dockerfile:1

# =============================================================================
# Stage: base — runtime PHP + dependensi sistem yang dipakai semua stage lain.
# =============================================================================
FROM php:8.4-fpm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        curl \
        libpng-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
        libicu-dev \
        zip \
        unzip \
        nginx \
        supervisor \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && git config --global --add safe.directory /var/www

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# =============================================================================
# Stage: vendor — dependensi PHP + hasil generate Wayfinder (routes/actions).
# Perintah artisan di stage ini memakai `.env` sementara (DB sqlite/dummy)
# supaya build tidak butuh koneksi database/redis sungguhan.
# =============================================================================
FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist

COPY . .

RUN composer dump-autoload --no-dev --optimize \
    && cp .env.example .env \
    && sed -i \
        -e 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' \
        -e 's/^SESSION_DRIVER=.*/SESSION_DRIVER=file/' \
        -e 's/^CACHE_STORE=.*/CACHE_STORE=file/' \
        -e 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=sync/' \
        .env \
    && touch database/database.sqlite \
    && php artisan key:generate --ansi \
    && php artisan wayfinder:generate --with-form \
    && rm -f .env database/database.sqlite

# =============================================================================
# Stage: frontend — build aset Vite. Butuh `resources/js/routes` (hasil
# Wayfinder) dari stage `vendor` karena banyak halaman Vue meng-import-nya.
# =============================================================================
FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
COPY --from=vendor /var/www/resources/js/routes ./resources/js/routes
COPY --from=vendor /var/www/resources/js/actions ./resources/js/actions
COPY --from=vendor /var/www/resources/js/wayfinder ./resources/js/wayfinder

RUN npm run build

# =============================================================================
# Stage: app — image final: kode + vendor + aset sudah baked in, siap jalan
# tanpa bind-mount kode dari host. nginx + php-fpm berjalan dalam satu
# container lewat supervisor (lihat docker/supervisord.conf) supaya
# `public/` dan symlink `storage:link` tidak perlu dibagi lintas container.
# =============================================================================
FROM base AS app

COPY --from=vendor /var/www /var/www
COPY --from=frontend /app/public/build /var/www/public/build
COPY --from=frontend /app/resources/js/routes /var/www/resources/js/routes
COPY --from=frontend /app/resources/js/actions /var/www/resources/js/actions
COPY --from=frontend /app/resources/js/wayfinder /var/www/resources/js/wayfinder

COPY nginx/conf.d/app.conf /etc/nginx/conf.d/app.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN rm -f /etc/nginx/sites-enabled/default \
    && chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/app/private storage/app/public storage/app/backups \
        storage/framework/cache/data storage/framework/sessions \
        storage/framework/testing storage/framework/views storage/logs \
    && chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache /var/www/public

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
