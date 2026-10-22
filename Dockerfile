# syntax=docker/dockerfile:1
FROM composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer

FROM php:8.4.26-fpm-alpine3.24@sha256:78cd8de9970a9cd6ff4d98860a94eb5bf37dd2d5776b4785562dbfad01c29d5a AS php-base
RUN apk add --no-cache icu-libs icu-data-full libzip oniguruma sqlite-libs \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev oniguruma-dev sqlite-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_sqlite mbstring intl zip bcmath pcntl opcache \
    && apk del .build-deps
WORKDIR /var/www/html
COPY --chmod=755 docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
COPY docker/php/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php/app.ini /usr/local/etc/php/conf.d/app.ini
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

# Only manifests invalidate the dependency download/install layers.
FROM php-base AS dependencies
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_HOME=/tmp/composer
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

FROM dependencies AS preparation
COPY app app
COPY bootstrap/app.php bootstrap/providers.php bootstrap/
COPY config config
COPY database/migrations database/migrations
COPY database/seeders database/seeders
COPY public public
COPY resources resources
COPY routes routes
COPY artisan ./
COPY docs/openapi/openapi.json docs/openapi/openapi.json
COPY scripts/docker-init.sh scripts/verify-runtime.php scripts/verify-login.php scripts/
RUN mkdir -p bootstrap/cache storage/passport storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs storage/app/private storage/app/public \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && composer check-platform-reqs --no-dev

FROM dependencies AS development-dependencies
RUN --mount=type=cache,target=/tmp/composer/cache \
    composer install --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

FROM php-base AS runtime
ARG APP_VERSION=0.0.0-dev
ARG VCS_REF=unknown
LABEL org.opencontainers.image.version=$APP_VERSION org.opencontainers.image.revision=$VCS_REF
COPY --from=preparation /var/www/html /var/www/html
RUN rm -f /usr/bin/unzip /usr/local/bin/phpize /usr/local/bin/php-config /usr/local/bin/docker-php-ext-* \
    && rm -rf /usr/local/include/php /usr/local/lib/php/build /usr/src/php* \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod 700 storage/passport
USER www-data
EXPOSE 9000
HEALTHCHECK --interval=5s --timeout=3s --start-period=10s --retries=30 \
    CMD php -r '$socket = @fsockopen("127.0.0.1", 9000, $errno, $error, 2); if (!$socket) { exit(1); } fclose($socket);'

FROM runtime AS development
USER root
RUN apk add --no-cache git unzip
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=development-dependencies /var/www/html/vendor vendor
COPY tests tests
COPY database/factories database/factories
COPY phpunit.xml phpunit.mysql.xml ./
COPY scripts scripts
COPY composer.json composer.lock ./
RUN composer dump-autoload --optimize --no-interaction \
    && composer check-platform-reqs \
    && chown -R www-data:www-data bootstrap/cache
ENV COMPOSER_HOME=/tmp/composer
USER www-data

FROM nginx:1.30.5-alpine@sha256:0985e772fb9f729e6fa0980da05fca5d9c468e870eed43071545afa9d2e27d94 AS nginx
ARG APP_VERSION=0.0.0-dev
ARG VCS_REF=unknown
LABEL org.opencontainers.image.version=$APP_VERSION org.opencontainers.image.revision=$VCS_REF
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/html/public
USER nginx
ENTRYPOINT []
CMD ["nginx", "-g", "daemon off;"]
EXPOSE 8080
HEALTHCHECK --interval=5s --timeout=5s --start-period=10s --retries=30 \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/up
