# syntax=docker/dockerfile:1
#
# Newsstand production image: FrankenPHP (Caddy + PHP) serving the app.
# The same image runs the web app, the Horizon queue worker and the scheduler
# (see docker-compose.production.yml).

# --- PHP dependencies ---------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# Extensions like pcntl/redis exist in the runtime image, not in this one.
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# --- Front-end assets ---------------------------------------------------------
FROM node:26-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
# Tailwind scans Blade views and imports Flux's CSS from vendor/.
COPY --from=vendor /app/vendor/livewire ./vendor/livewire
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources ./vendor/laravel/framework/src/Illuminate/Pagination/resources
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# --- Runtime ------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4-bookworm

ARG VERSION=dev
LABEL org.opencontainers.image.title="Newsstand" \
      org.opencontainers.image.description="Self-hosted RSS reader for tech and security news" \
      org.opencontainers.image.version="${VERSION}" \
      org.opencontainers.image.source="https://github.com/burnacid/Newsstand" \
      org.opencontainers.image.url="https://hub.docker.com/r/burnacid/newsstand"

RUN install-php-extensions pdo_pgsql pdo_mysql redis pcntl intl zip opcache bcmath \
    && apt-get update \
    && apt-get install -y --no-install-recommends gosu \
    && rm -rf /var/lib/apt/lists/* \
    && gosu nobody true

ENV SERVER_NAME=:8080 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

WORKDIR /app

COPY docker/production/php.ini "$PHP_INI_DIR/conf.d/zz-newsstand.ini"
COPY docker/production/Caddyfile /etc/caddy/Caddyfile
COPY docker/production/entrypoint.sh /usr/local/bin/newsstand-entrypoint

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build

RUN rm -f public/hot public/fonts-manifest.dev.json bootstrap/cache/*.php \
    && mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && sed -i 's/\r$//' /usr/local/bin/newsstand-entrypoint \
    && chmod +x /usr/local/bin/newsstand-entrypoint \
    && mkdir -p /config/psysh \
    && chown -R www-data:www-data storage bootstrap/cache /data/caddy /config/caddy /config/psysh

# The entrypoint starts as root only to prepare /app/storage (often a host
# mount), then runs everything as www-data. Port 8080 needs no extra privileges.
# For one-off commands use: docker compose exec -u www-data app php artisan ...
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1:8080/up') === false ? 1 : 0);"

ENTRYPOINT ["newsstand-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
