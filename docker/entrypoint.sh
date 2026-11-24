#!/bin/sh
# Shared bootstrap for the app/horizon/reverb/vite containers.
# Only the container with BOOTSTRAP=1 (app) installs dependencies; the others wait for it.
set -e
cd /var/www/html

if [ "$BOOTSTRAP" = "1" ]; then
    [ -f .env ] || cp .env.example .env
    [ -f vendor/autoload.php ] || composer install --no-interaction --prefer-dist
    grep -q '^APP_KEY=base64' .env || php artisan key:generate --force
    mkdir -p database/sqlite
    [ -f database/sqlite/database.sqlite ] || touch database/sqlite/database.sqlite
    php artisan migrate --force
    touch storage/.bootstrapped
else
    until [ -f storage/.bootstrapped ]; do sleep 2; done
fi

exec "$@"
