#!/bin/sh
set -eu

stage=Composer
trap 'echo "[init] Falló la etapa: $stage. Corregir la causa y repetir docker compose up -d --build --wait." >&2' EXIT
echo "[init] Composer: instalar desde composer.lock"
if [ ! -f composer.lock ]; then
    echo "[init] Falta composer.lock; restaurarlo desde el repositorio." >&2
    exit 1
fi
composer install --no-interaction --prefer-dist
stage=configuración
echo "[init] Limpiar caché de configuración"
php artisan config:clear --no-interaction
stage=Laravel
php artisan app:initialize --no-interaction
trap - EXIT
