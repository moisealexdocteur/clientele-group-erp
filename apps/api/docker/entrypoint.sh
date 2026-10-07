#!/usr/bin/env sh
set -eu

if [ "${APP_RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force --no-interaction
fi

exec docker-php-entrypoint "$@"
