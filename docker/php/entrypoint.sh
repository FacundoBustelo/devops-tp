#!/bin/sh
set -eu
umask 027
if [ "${1:-}" = "php-fpm" ] && [ "${METRICS_ENABLED:-false}" = "true" ]; then
    php scripts/observability/init-metrics.php
fi
exec "$@"
