#!/usr/bin/env bash
set -euo pipefail
kind=${1:?Use quality, sqlite or mysql}
case "$kind" in quality|sqlite|mysql) ;; *) exit 2 ;; esac
if [[ $kind == quality ]]; then
    python3 -B -m unittest discover -s "$(dirname "$0")/../../tests/ci" -v
fi
source "$(dirname "$0")/common.sh"
compose+=(-f compose.dev.yaml)
if [[ $kind == mysql ]]; then
    compose+=(-f compose.audit-test.yaml)
fi
"${compose[@]}" config --quiet
"${compose[@]}" build app
test_run=("${compose[@]}" run --rm --no-deps --pull never -e APP_ENV=testing
    -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e CACHE_STORE=array -e SESSION_DRIVER=array)
case "$kind" in
    quality)
        "${test_run[@]}" app vendor/bin/pint --test
        "${test_run[@]}" app vendor/bin/phpunit --filter OpenApiDocumentationTest --cache-directory=/tmp/phpunit-cache --display-phpunit-notices
        ;;
    sqlite)
        "${test_run[@]}" app vendor/bin/phpunit -c phpunit.xml --cache-directory=/tmp/phpunit-cache --display-phpunit-notices
        ;;
    mysql)
        "${compose[@]}" pull mysql
        "${compose[@]}" up -d --no-build --pull never --wait --wait-timeout 180 mysql
        "${compose[@]}" run --rm --no-deps --pull never -e APP_ENV=testing -e LOG_CHANNEL=null app \
            vendor/bin/phpunit -c phpunit.mysql.xml --cache-directory=/tmp/phpunit-cache --display-phpunit-notices
        ;;
esac
