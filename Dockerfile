FROM composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer
FROM php:8.4.26-fpm-bookworm@sha256:6bfef8e416977aa41f48e3e42a40c1e08050d24e4a938c6edb421400bff24601

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_sqlite mbstring intl zip bcmath pcntl opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod 755 /usr/local/bin/app-entrypoint
WORKDIR /var/www/html
ARG APP_UID=1000
ARG APP_GID=1000
RUN groupmod -g "$APP_GID" www-data && usermod -u "$APP_UID" -g "$APP_GID" www-data
ENV COMPOSER_HOME=/tmp/composer
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]
