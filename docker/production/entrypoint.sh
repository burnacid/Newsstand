#!/bin/sh
# Container start-up.
#
# 1. As root (the default): make sure Laravel's storage folders exist and belong
#    to www-data. /app/storage is often a host bind mount or an existing volume
#    owned by root, which Laravel can't write to. Then drop to www-data.
# 2. As www-data: check storage is writable, cache config/routes/views, and let
#    the web container (CONTAINER_ROLE=app) run database migrations.
set -e

STORAGE_DIRS="storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache"

if [ "$(id -u)" = "0" ]; then
    # shellcheck disable=SC2086
    mkdir -p $STORAGE_DIRS
    # Only touch what isn't www-data's already (fast on large storage folders).
    find storage bootstrap/cache \! -user www-data -exec chown www-data:www-data {} +

    exec gosu www-data "$0" "$@"
fi

# shellcheck disable=SC2086
mkdir -p $STORAGE_DIRS 2>/dev/null || true
for dir in $STORAGE_DIRS; do
    if [ ! -w "$dir" ]; then
        echo "Newsstand can't write to /app/$dir (running as $(id -un), uid $(id -u))." >&2
        echo "Either start the container as root (the default) so it can fix this itself," >&2
        echo "or give the mounted storage folder to uid 33: chown -R 33:33 <your storage folder>" >&2
        exit 1
    fi
done

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set. Generate one with:" >&2
    echo "  docker compose --env-file .env.production -f docker-compose.production.yml run --rm --no-deps --entrypoint php app artisan key:generate --show" >&2
    exit 1
fi

if [ "${CONTAINER_ROLE:-app}" = "app" ]; then
    php artisan migrate --force --isolated
fi

php artisan optimize

exec "$@"
