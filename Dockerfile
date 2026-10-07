# syntax=docker/dockerfile:1

# ---- PHP runtime base: FrankenPHP (Caddy + PHP in one process) with the extensions Ferrite needs
FROM dunglas/frankenphp:1-php8.4-bookworm AS base

RUN install-php-extensions gd exif zip intl bcmath pcntl opcache pdo_mysql pdo_sqlite \
    && apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# ---- PHP dependencies (production only)
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# ---- Front-end assets. The CSS build scans views from vendor/ for classes, so it needs the PHP
# dependencies. Fonts are downloaded here once and served from the image afterwards.
FROM node:22-bookworm-slim AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ---- Final image
FROM base AS app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction \
    && rm /usr/bin/composer \
    && mkdir -p /data /app/bootstrap/cache \
    && chown -R www-data:www-data /data /app/bootstrap/cache

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-ferrite.ini
COPY docker/entrypoint.sh /usr/local/bin/ferrite-entrypoint
RUN chmod +x /usr/local/bin/ferrite-entrypoint

# Everything that changes lives in /data: database, files, uploads in progress, logs and caches.
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=warning \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite \
    LARAVEL_STORAGE_PATH=/data/storage \
    FERRITE_LOCAL_ROOT=/data/files \
    FERRITE_TMP_PATH=/data/tmp \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=sync \
    SERVER_NAME=:8080 \
    XDG_CONFIG_HOME=/data/caddy/config \
    XDG_DATA_HOME=/data/caddy/data

VOLUME /data
EXPOSE 8080

USER www-data

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up >/dev/null || exit 1

ENTRYPOINT ["ferrite-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
