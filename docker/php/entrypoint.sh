#!/bin/sh
set -eu

# Only runtime directories need to belong to the FPM worker.
mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 775 {} +
find storage bootstrap/cache -type f ! -name '*.key' ! -name '*.pem' -exec chmod 664 {} +
find storage -type f \( -name '*.key' -o -name '*.pem' \) -exec chmod 600 {} +

# Composer/Artisan run with the same identity as FPM; no root-owned dependencies.
if [ "$1" = "php-fpm" ]; then
    exec "$@"
fi
exec su -s /bin/sh www-data -c 'exec "$@"' -- app "$@"
