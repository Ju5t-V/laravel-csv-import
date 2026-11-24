#!/bin/sh
# Shared bootstrap for the app/horizon/reverb/vite containers.
# Only the container with BOOTSTRAP=1 (app) installs dependencies; the others wait for it.
set -e
cd /var/www/html

if [ "$BOOTSTRAP" = "1" ]; then
    [ -f .env ] || cp .env.example .env
    [ -f vendor/autoload.php ] || composer install --no-interaction --prefer-dist
    grep -q '^APP_KEY=base64' .env || php artisan key:generate --force
    # Random Reverb credentials on first bootstrap (the same way APP_KEY is generated).
    for var in REVERB_APP_KEY REVERB_APP_SECRET; do
        if ! grep -q "^$var=.\+" .env; then
            value=$(php -r 'echo bin2hex(random_bytes(16));')
            if grep -q "^$var=" .env; then
                sed -i "s/^$var=.*/$var=$value/" .env
            else
                printf '%s=%s\n' "$var" "$value" >> .env
            fi
        fi
    done
    mkdir -p database/sqlite
    [ -f database/sqlite/database.sqlite ] || touch database/sqlite/database.sqlite
    php artisan migrate --force
    touch vendor/.bootstrapped
else
    until [ -f vendor/.bootstrapped ]; do sleep 2; done
fi

exec "$@"
