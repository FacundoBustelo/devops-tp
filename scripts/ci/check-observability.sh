#!/usr/bin/env bash
set -euo pipefail
export CI_PROJECT_PREFIX=devops-obs
source "$(dirname "$0")/common.sh"
"${compose[@]}" config --quiet
"${compose[@]}" build app nginx
"${compose[@]}" pull mysql
"${compose[@]}" up -d --no-build --pull never --wait --wait-timeout 180
# Base Compose is functional without Grafana credentials or metrics storage.
"${compose[@]}" exec -T nginx wget -q -O /dev/null http://127.0.0.1:8080/up
"${compose[@]}" exec -T nginx wget -q -O /dev/null http://127.0.0.1:8080/docs
"${compose[@]}" exec -T app php -r 'exit(is_dir("/tmp/devops-observability") ? 1 : 0);'
python3 - "$task_dir/compose.env" <<'PY'
import subprocess, sys
from scripts.observability.verify import Verification, require
v = Verification(sys.argv[1], test=False)
require('prometheus' not in v.run('config', '--services', compose=v.base).split(), 'Base starts monitoring')
status, headers, body = v.http(v.port('nginx', 8080) + '/api/v1/gifs')
require(status == 401 and headers.get('X-Request-ID'), 'Base API is broken')
require('http_request_completed' in v.run('logs', '--no-color', 'app', compose=v.base), 'Base HTTP logging missing')
print('PASS base Compose: API, logs, health, Swagger; no metrics directory or monitoring services', flush=True)
PY
compose+=(-f compose.monitoring.yaml -f compose.observability-test.yaml)
"${compose[@]}" config --quiet
"${compose[@]}" pull prometheus grafana
"${compose[@]}" run --rm --no-deps --entrypoint /bin/promtool prometheus check config /etc/prometheus/prometheus.yml
"${compose[@]}" run --rm --no-deps --entrypoint /bin/promtool prometheus check rules /etc/prometheus/alerts.yml
"${compose[@]}" run --rm --no-deps --workdir /etc/prometheus --entrypoint /bin/promtool prometheus test rules tests/alerts.test.yml
"${compose[@]}" up -d --no-build --pull never --wait --wait-timeout 180
# Explicit test-only seed in disposable APP_ENV=testing database.
"${compose[@]}" exec -T app php /tmp/observability-runtime.php seed
python3 scripts/observability/verify.py --env-file "$task_dir/compose.env"
"${compose[@]}" exec -T nginx wget -q -O - http://127.0.0.1:9091/metrics |
    "${compose[@]}" run --rm -T --no-deps --entrypoint /bin/promtool prometheus check metrics
