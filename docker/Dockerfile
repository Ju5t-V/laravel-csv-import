FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip curl ca-certificates sqlite3 libsqlite3-dev libzip-dev libicu-dev \
    && docker-php-ext-install pcntl pdo_sqlite intl zip bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /var/www/html
