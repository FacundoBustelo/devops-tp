#!/usr/bin/env bash
# Existing independent project required; never edits .env or destroys any volume.
set -euo pipefail
cd "$(dirname "$0")/../.."
env_file=${1:?Usage: demo.sh PRIVATE_ENV_FILE [EVIDENCE_JSON] [SCREENSHOT_DIRECTORY]}
# Validate project boundary before installing a trap that can recreate application containers.
project=$(python3 - "$env_file" <<'PY'
import sys
from scripts.observability.verify import Verification
print(Verification(sys.argv[1], test=False).values['COMPOSE_PROJECT_NAME'])
PY
)
marker=$(mktemp)
# Python removes this marker immediately before attempting the fault recreation.
compose=(docker compose --env-file "$env_file" -p "$project" -f docker-compose.yml -f compose.monitoring.yaml)
pid=
restore() {
    local status=$?
    trap - EXIT INT TERM
    if [[ -n $pid ]] && kill -0 "$pid" 2>/dev/null; then
        kill -TERM "$pid" 2>/dev/null || true
        wait "$pid" || true
    fi
    if [[ ! -f $marker ]]; then
        if ! "${compose[@]}" up -d --no-build --force-recreate --no-deps --wait app nginx; then
            status=1
            printf 'Automatic restoration failed. Recover with: '
            printf '%q ' "${compose[@]}" up -d --no-build --force-recreate --no-deps --wait app nginx
            printf '\n'
        fi
    fi
    rm -f -- "$marker"
    exit "$status"
}
trap restore EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
args=(--env-file "$env_file" --restored-marker "$marker")
if [[ -n ${2:-} ]]; then args+=(--evidence "$2"); fi
if [[ -n ${3:-} ]]; then args+=(--screenshots "$3"); fi
python3 scripts/observability/demo.py "${args[@]}" &
pid=$!
wait "$pid"
pid=
