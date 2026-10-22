#!/usr/bin/env python3
"""Real GIPHY failure / alert / functional recovery. Tokens exist only in this process."""
import argparse
from datetime import datetime, timezone
import json
from pathlib import Path
import signal
import subprocess
import time
from verify import Verification, require, samples, total


def demonstrate(env_file, restored_marker, screenshots=None):
    v = Verification(env_file, test=False)
    require(bool(v.values.get('GIPHY_API_KEY')), 'No local GIPHY key supplied; no fault induced')
    status, _, body = v.http(v.port('nginx', 8080) + '/api/v1/login', method='POST', headers={'Content-Type': 'application/json', 'Accept': 'application/json'}, data=json.dumps({'email': 'user@example.test', 'password': 'contraseña-local'}).encode())
    require(status == 200, 'Local login failed; no fault induced')
    token = json.loads(body)['access_token']
    auth = {'Authorization': 'Bearer ' + token, 'Accept': 'application/json'}

    def search(expected):
        status, headers, _ = v.http(v.port('nginx', 8080) + '/api/v1/gifs?QUERY=cats&LIMIT=1', headers=auth)
        require(status == expected, f'Authenticated search expected {expected}, received {status}')
        return headers

    search(200)
    print('PASS initial authenticated GIPHY search: 200', flush=True)
    v.wait_scrapes()
    altered = v.monitoring + ['-f', 'compose.observability-demo.yaml']
    mutated = False
    restored = False
    report = {'started_at': datetime.now(timezone.utc).isoformat(), 'baseline_search': 200, 'states': []}
    try:
        # Set before calling Compose: partial recreation also requires restoration.
        Path(restored_marker).unlink(missing_ok=True)
        mutated = True
        v.run('up', '-d', '--no-build', '--force-recreate', '--no-deps', '--wait', 'app', 'nginx', compose=altered)
        collected_at = v.wait_scrapes()
        deadline = time.monotonic() + max(0, 300 - (time.time() - collected_at))
        start = time.monotonic()
        initial = total(samples(v.internal()), 'devops_http_requests_total')
        failing = 0
        last_poll = 0.0
        states = set()
        while time.monotonic() < deadline:
            tick = time.monotonic()
            headers = search(503)
            failing += 1
            if failing == 1:
                v.correlation(headers, 503)
            if tick - last_poll >= 5:
                last_poll = tick
                require(v.query('up{job="laravel",instance="nginx:9091"} == 1'), 'Collection failed during API-error demo')
                active = [a for a in v.alerts() if a['labels']['alertname'] == 'ApiHigh5xxRate']
                state = active[0]['state'] if active else 'inactive'
                if state not in states:
                    states.add(state)
                    report['states'].append({'state': state, 'elapsed_seconds': round(tick - start, 1)})
                    print('Observed ApiHigh5xxRate: ' + state, flush=True)
                if state == 'firing':
                    break
            time.sleep(max(0, 1 - (time.monotonic() - tick)))
        else:
            raise RuntimeError('ApiHigh5xxRate did not reach firing within five minutes')
        after = total(samples(v.internal()), 'devops_http_requests_total')
        require(after - initial == failing, 'Failed traffic counter differs from generated traffic')
        result = v.query('sum(devops_http_requests_total{job="laravel",status="503"})')
        require(result and float(result[0]['value'][1]) > 0, 'Prometheus did not collect actual 503 counter growth')
        require('pending' in states, 'Pending was not observed')
        report['failed_requests'] = failing
        report['counter_503'] = result
        report['up'] = v.query('up{job="laravel",instance="nginx:9091"}')
        report['dashboard_firing'] = v.grafana(require_traffic=False)
        if screenshots:
            subprocess.run(['node', 'scripts/observability/screenshot.mjs', env_file, v.port('grafana', 3000), str(Path(screenshots) / 'grafana-firing.png')], check=True)
        print(f'PASS real failure: {failing} authenticated 503s, up=1, alert firing', flush=True)
    finally:
        if mutated:
            v.run('up', '-d', '--no-build', '--force-recreate', '--no-deps', '--wait', 'app', 'nginx', compose=v.monitoring)
            restored = True
            Path(restored_marker).touch(mode=0o600)
            print('Original application configuration restored', flush=True)
    if restored:
        try:
            search(200)
        except Exception:
            raise RuntimeError('Configuración restaurada, recuperación funcional no acreditada') from None
        print('PASS restored authenticated GIPHY search: 200', flush=True)
        collected_at = v.wait_scrapes()
        deadline = time.monotonic() + max(0, 300 - (time.time() - collected_at))
        # Keep real successful traffic while the previous errors leave the two-minute window.
        last_search = 0.0
        while time.monotonic() < deadline:
            if time.monotonic() - last_search >= 5:
                search(200)
                last_search = time.monotonic()
            active = [a for a in v.alerts() if a['labels']['alertname'] == 'ApiHigh5xxRate']
            ratio = v.query('sum(rate(devops_http_requests_total{job="laravel",status=~"5.."}[2m])) / sum(rate(devops_http_requests_total{job="laravel"}[2m]))')
            if not active and ratio and float(ratio[0]['value'][1]) <= 0.2 and v.query('up{job="laravel",instance="nginx:9091"} == 1'):
                break
            time.sleep(5)
        else:
            raise RuntimeError('Configuration restored but alert did not clear within five minutes')
        report['recovered_search'] = 200
        report['active_high5xx_alerts_after_recovery'] = []
        report['dashboard_recovery'] = v.grafana(require_traffic=False)
        require(report['dashboard_recovery'][8]['series'] == 0, 'Recovery dashboard still has active alerts')
        require(float(report['dashboard_recovery'][4]['values'][0]) <= 20, 'Recovery dashboard still has excessive 5xx')
        if screenshots:
            subprocess.run(['node', 'scripts/observability/screenshot.mjs', env_file, v.port('grafana', 3000), str(Path(screenshots) / 'grafana-recovered.png')], check=True)
        print('PASS real recovery: authenticated search 200, stable scrape, alert inactive', flush=True)
        report['finished_at'] = datetime.now(timezone.utc).isoformat()
        return report


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--env-file', required=True)
    parser.add_argument('--restored-marker', required=True)
    parser.add_argument('--evidence')
    parser.add_argument('--screenshots')
    args = parser.parse_args()
    signal.signal(signal.SIGTERM, lambda *_: (_ for _ in ()).throw(KeyboardInterrupt()))
    try:
        report = demonstrate(args.env_file, args.restored_marker, args.screenshots)
        if args.evidence:
            Path(args.evidence).write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n')
    except (Exception, KeyboardInterrupt) as error:
        print('FAIL demo: ' + str(error), flush=True)
        raise SystemExit(1)
