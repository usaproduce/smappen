# Smappen — single-stage PHP-FPM image. Apache fronts FPM and serves the
# built React app from public/app. For local dev use docker-compose, which
# adds MySQL + Redis services.
#
# Build context: project root.

FROM php:8.3-fpm-bookworm AS base

# System deps + PHP extensions we actually use.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libpng-dev libonig-dev libxml2-dev \
        libcurl4-openssl-dev libfreetype6-dev libjpeg-dev \
        default-mysql-client cron \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql mysqli mbstring zip gd opcache exif \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# Composer (no global install — pulled in just for layering).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP deps first so dep changes invalidate fewer cache layers.
COPY composer.json composer.lock* /app/
RUN composer install --no-dev --prefer-dist --no-progress --no-scripts || \
    composer install --no-dev --prefer-dist --no-progress

# App code.
COPY . /app

# Bake the SPA build into the image. The frontend folder ships its own
# Node deps via Dockerfile.frontend — we just copy the build output below.
# In CI, run `npm ci && npm run build` in frontend/ before docker build,
# so public/app/ contains the latest bundle.

RUN mkdir -p /app/storage/logs /app/storage/uploads /app/storage/exports /app/storage/backups /app/storage/cache \
    && chown -R www-data:www-data /app/storage

# Opcache for production
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.revalidate_freq=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

EXPOSE 9000
CMD ["php-fpm", "-F"]
