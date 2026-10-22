#!/bin/sh
set -eu
# Dependencies and package discovery are part of the build. Secrets stay in the environment.
exec php artisan app:initialize --no-interaction
