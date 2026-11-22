#!/bin/sh
# Container start-up: cache config/routes/views for this environment, and let
# the web container (CONTAINER_ROLE=app) run database migrations.
set -e

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
