#!/usr/bin/env bash
# Sourced by checks and release smoke tests. Never load the checkout's .env.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
task_dir=$(mktemp -d)
export COMPOSE_PROJECT_NAME="devops-ci-$(basename "$task_dir" | tr '[:upper:].' '[:lower:]-')"
export IMAGE_REPOSITORY="${CI_IMAGE_REPOSITORY:-$COMPOSE_PROJECT_NAME}"
export APP_VERSION="${CI_APP_VERSION:-0.0.0-ci}"
export VCS_REF
VCS_REF=$(git rev-parse HEAD)
python3 - "$task_dir/compose.env" <<'PY'
import base64, secrets, sys
from pathlib import Path
Path(sys.argv[1]).write_text('\n'.join([
    'APP_KEY=base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
    'DB_PASSWORD=' + secrets.token_hex(24),
    'MYSQL_ROOT_PASSWORD=' + secrets.token_hex(24),
]) + '\n')
PY
chmod 600 "$task_dir/compose.env"
set -a
source "$task_dir/compose.env"
set +a
export APP_ENV=testing APP_DEBUG=false APP_NAME=CI APP_URL=http://127.0.0.1
export DB_DATABASE=prex_ci DB_USERNAME=prex_ci HTTP_PORT=0 DB_FORWARD_PORT=0
export GIPHY_API_KEY= GIPHY_CONNECT_TIMEOUT=2 GIPHY_TIMEOUT=5 TRUSTED_PROXIES=
compose=(docker compose --env-file "$task_dir/compose.env" -f docker-compose.yml)
cleanup() {
    local status=$?
    trap - EXIT
    if (( status != 0 )); then
        "${compose[@]}" ps -a || true
        "${compose[@]}" logs --no-color --tail 100 || true
    fi
    if ! "${compose[@]}" down --volumes --remove-orphans --timeout 10; then
        status=1
    fi
    rm -rf -- "$task_dir"
    exit "$status"
}
trap cleanup EXIT
