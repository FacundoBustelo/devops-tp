#!/usr/bin/env python3
"""Verify actual Nginx/FPM aggregation and security in a disposable Compose project."""
import argparse
import base64
from concurrent.futures import ThreadPoolExecutor
import json
import math
import os
import re
import subprocess
import threading
import time
import uuid
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import Request, urlopen


class Verification:
    def __init__(self, env_file, test=True):
        self.env_file = str(Path(env_file).resolve())
        self.values = dict(line.split('=', 1) for line in Path(env_file).read_text().splitlines() if line and not line.startswith('#'))
        self.environment = {**os.environ, **self.values}
        project = self.values['COMPOSE_PROJECT_NAME']
        if not project.startswith('devops-obs-'):
            raise RuntimeError('Requires an independent devops-obs-* project.')
        self.base = ['docker', 'compose', '--env-file', self.env_file, '-p', project, '-f', 'docker-compose.yml']
        self.monitoring = self.base + ['-f', 'compose.monitoring.yaml']
        self.compose = self.monitoring + (['-f', 'compose.observability-test.yaml'] if test else [])
        self.test = test

    def run(self, *args, compose=None, stdin=None):
        result = subprocess.run((compose or self.compose) + list(args), input=stdin, text=True, capture_output=True, env=self.environment)
        if result.returncode:
            # Deliberately omit command output: it can contain credentials or raw responses.
            raise RuntimeError('Compose command failed: ' + ' '.join(args[:3]))
        return result.stdout

    def port(self, service, port):
        return 'http://' + self.run('port', service, str(port)).strip()

    @staticmethod
    def http(url, method='GET', headers=None, data=None):
        request = Request(url, method=method, headers=headers or {}, data=data)
        try:
            with urlopen(request, timeout=10) as response:
                return response.status, response.headers, response.read()
        except HTTPError as error:
            return error.code, error.headers, error.read()

    def internal(self, path='/metrics', method='GET'):
        # BusyBox wget exits nonzero for 404/503. Used only for successful internal reads.
        return self.run('exec', '-T', 'nginx', 'wget', '-q', '-O', '-', 'http://127.0.0.1:9091' + path)

    def query(self, expression):
        url = 'http://prometheus:9090/api/v1/query?' + urlencode({'query': expression})
        result = json.loads(self.run('exec', '-T', 'nginx', 'wget', '-q', '-O', '-', url))
        require(result['status'] == 'success', 'Prometheus query failed')
        return result['data']['result']

    def alerts(self):
        return json.loads(self.run('exec', '-T', 'nginx', 'wget', '-q', '-O', '-', 'http://prometheus:9090/api/v1/alerts'))['data']['alerts']

    def wait_scrapes(self, timeout=90):
        deadline = time.monotonic() + timeout
        cutoff = time.time()
        observed = set()
        while time.monotonic() < deadline:
            result = self.query('timestamp(up{job="laravel",instance="nginx:9091"}) and (up{job="laravel",instance="nginx:9091"} == 1)')
            if result:
                timestamp = float(result[0]['value'][1])
                if timestamp >= cutoff:
                    observed.add(timestamp)
            if len(observed) >= 2:
                return min(observed)
            time.sleep(2)
        raise RuntimeError('Two new successful scrapes not established')

    def helper(self, action, *args):
        return self.run('exec', '-T', 'app', 'php', '/tmp/observability-runtime.php', action, *args).strip()

    def raw_public(self, path, method='GET'):
        # http.client preserves the supplied request target (including duplicate slashes).
        import http.client
        from urllib.parse import urlsplit
        target = urlsplit(self.port('nginx', 8080))
        connection = http.client.HTTPConnection(target.hostname, target.port, timeout=10)
        connection.request(method, path, headers={
            'Devops-Metrics-Internal': '1', 'X-Devops-Metrics-Internal': '1',
            'X-Forwarded-For': '127.0.0.1', 'X-Forwarded-Port': '9091',
            'X-Forwarded-Host': 'nginx:9091', 'X-Original-URL': '/metrics',
            'X-Rewrite-URL': '/metrics', 'Authorization': 'Bearer forbidden-header-secret',
        })
        response = connection.getresponse()
        status, body = response.status, response.read()
        connection.close()
        return status, body

    def security(self):
        paths = ['/metrics', '/metrics/', '//metrics', '/metrics//', '/%6detrics', '/%6Detrics/', '/metrics%2f', '/%2fmetrics', '/index.php/metrics', '/index.php//metrics', '/index.php?route=/metrics', '/metrics/../metrics', '/METRICS']
        for path in paths:
            for method in ['GET', 'HEAD', 'POST', 'PUT', 'OPTIONS', 'TRACE']:
                status, body = self.raw_public(path, method)
                require(status != 200 and b'devops_http_' not in body and b'# HELP' not in body, 'Public metrics disclosure: ' + path)
        print(f'PASS public security: {len(paths) * 6} raw requests', flush=True)

    def rejected_request_logging(self):
        """A header-only 2 MiB request must be rejected before PHP, without raw logs."""
        import http.client
        from urllib.parse import urlsplit
        marker = 'forbidden-413-secret-' + uuid.uuid4().hex
        target = urlsplit(self.port('nginx', 8080))
        before = samples(self.internal())
        connection = http.client.HTTPConnection(target.hostname, target.port, timeout=10)
        try:
            connection.request('POST', '/api/v1/gifs?token=' + marker, headers={
                'Content-Length': '2097152', 'Expect': '100-continue', 'Connection': 'close',
            })
            response = connection.getresponse()
            require(response.status == 413, 'Oversized request was not rejected with 413')
            require(response.getheader('X-Request-ID') is None, '413 unexpectedly reached Laravel')
            response.read()
        finally:
            connection.close()
        require(samples(self.internal()) == before, 'Nginx 413 changed Laravel metrics')
        # A normal request still produces a correlated application JSON event.
        status, headers, _ = self.http(self.port('nginx', 8080) + '/api/v1/gifs')
        require(status == 401, 'API regressed after 413')
        self.correlation(headers, 401)
        require(self.http(self.port('nginx', 8080) + '/up')[0] == 200, 'Health regressed after 413')
        require(self.http(self.port('nginx', 8080) + '/docs')[0] == 200, 'Swagger regressed after 413')
        require(self.http(self.port('nginx', 8080) + '/metrics')[0] == 404, 'Public metrics became accessible')
        # Wait for request finalization/access logging, including early body rejection.
        deadline = time.monotonic() + 10
        while True:
            nginx_logs = self.run('logs', '--no-color', '--no-log-prefix', 'nginx')
            events = []
            for line in nginx_logs.splitlines():
                try:
                    event = json.loads(line)
                except ValueError:
                    continue
                require(set(event) == {'source', 'status', 'duration_seconds'}, 'Unsafe Nginx access log schema')
                require(event['source'] == 'nginx' and isinstance(event['duration_seconds'], (int, float)), 'Invalid Nginx JSON event')
                events.append(event)
            if any(event['status'] == 413 for event in events):
                break
            require(time.monotonic() < deadline, 'Safe Nginx JSON 413 event missing')
            time.sleep(0.1)
        logs = self.run('logs', '--no-color', '--no-log-prefix')
        for service, directories in [('nginx', ['/var/log', '/tmp']), ('app', ['/var/log', '/tmp', '/var/www/html/storage/logs'])]:
            # Do not follow stdout/stderr symlinks or read devices/pipes. Include all
            # regular files in log/temp directories so an alternate file cannot hide a leak.
            files = self.run('exec', '-T', service, 'find', *directories, '-type', 'f').splitlines()
            if files:
                scan = subprocess.run(self.compose + ['exec', '-T', service, 'grep', '-l', '-F', marker, *files], capture_output=True, env=self.environment)
                require(scan.returncode in [0, 1], 'Could not scan container log/temp files')
                require(scan.returncode == 1, '413 sensitive marker leaked to a container log/temp file')
        require(marker not in logs, '413 sensitive marker leaked to a container log/file')
        require('client intended to send too large body' not in nginx_logs and ', client: ' not in nginx_logs, 'Native HTTP error context leaked')
        print('PASS 413 regression: Content-Length=2097152; pre-Laravel rejection; no marker in all Compose logs or regular log/temp files; safe Nginx JSON 413 and Laravel JSON; health/docs/metrics unchanged', flush=True)

    def concurrency(self):
        before = samples(self.internal())
        require(coherent(before), 'Incoherent initial scrape')
        stop = threading.Event()
        snapshots = []
        errors = []

        def scrape():
            while not stop.is_set():
                try:
                    snapshot = samples(self.internal())
                    require(coherent(snapshot), 'Counter/histogram snapshot mismatch')
                    snapshots.append(snapshot)
                except Exception as error:
                    errors.append(type(error).__name__)
                stop.wait(0.05)

        base_url = self.port('nginx', 8080)
        def request(i):
            status, _, _ = self.http(base_url + f'/api/v1/gifs?q=forbidden-query-secret-{i}')
            require(status == 401, 'Expected deterministic unauthenticated 401')

        thread = threading.Thread(target=scrape)
        thread.start()
        try:
            with ThreadPoolExecutor(max_workers=8) as pool:
                list(pool.map(request, range(200)))
        finally:
            stop.set()
            thread.join()
        after = samples(self.internal())
        require(not errors and snapshots, 'Concurrent scrapes failed')
        require(total(after, 'devops_http_requests_total') - total(before, 'devops_http_requests_total') == 200, 'Counter did not increase by exactly 200')
        require(total(after, 'devops_http_request_duration_seconds_count') - total(before, 'devops_http_request_duration_seconds_count') == 200, 'Histogram count did not increase by exactly 200')
        log = self.run('exec', '-T', 'app', 'cat', '/tmp/observability-fpm.log')
        workers = set(re.findall(r'pid=(\d+) status=401', log))
        require(len(workers) >= 2, 'Requests did not use multiple FPM workers')
        require(coherent(after), 'Incoherent final scrape')
        print(f'PASS 200 requests, concurrency 8, {len(workers)} FPM workers, {len(snapshots)} coherent concurrent scrapes', flush=True)
        return after

    def grafana(self, require_traffic=True):
        url = self.port('grafana', 3000)
        auth = base64.b64encode(('admin:' + self.values['GRAFANA_ADMIN_PASSWORD']).encode()).decode()
        headers = {'Authorization': 'Basic ' + auth}
        status, _, _ = self.http(url + '/api/dashboards/uid/devops-api')
        require(status == 401, 'Grafana dashboard permits anonymous access')
        status, _, body = self.http(url + '/api/dashboards/uid/devops-api', headers=headers)
        require(status == 200, 'Provisioned dashboard missing')
        dashboard = json.loads(body)['dashboard']
        require(dashboard['uid'] == 'devops-api', 'Unexpected dashboard UID')
        findings = []
        evaluated_at = time.time()
        for panel in dashboard['panels']:
            if panel['id'] in [5, 8]:
                expected = ([{'color': 'green', 'value': None}, {'color': 'red', 'value': 20}]
                            if panel['id'] == 5 else [{'color': 'red', 'value': None}, {'color': 'green', 'value': 1}])
                require(panel['fieldConfig']['defaults']['thresholds'] == {'mode': 'absolute', 'steps': expected}, 'Incorrect Grafana error/availability thresholds')
                require(panel['options']['colorMode'] == 'value', 'Grafana stat does not display threshold colors')
            expr = panel['targets'][0]['expr']
            status, _, body = self.http(url + '/api/datasources/proxy/uid/devops-prometheus/api/v1/query?' + urlencode({'query': expr, 'time': evaluated_at}), headers=headers)
            require(status == 200, 'Grafana datasource query failed')
            result = json.loads(body)
            require(result['status'] == 'success', 'Grafana panel query invalid')
            series = result['data']['result']
            findings.append({'panel': panel['title'], 'series': len(series), 'values': [s['value'][1] for s in series]})
            if require_traffic and panel['id'] in range(1, 9):
                require(series and all(math.isfinite(float(s['value'][1])) for s in series), 'Expected real panel samples missing')
        print('PASS Grafana authentication, provisioning and real datasource queries: ' + json.dumps(findings, ensure_ascii=False), flush=True)
        return findings

    def correlation(self, response_headers, expected_status):
        request_id = response_headers.get('X-Request-ID')
        require(request_id is not None, 'Request ID missing')
        # Fixed SQL query by existing context UUID; only minimal metadata leaves container.
        if self.test:
            row = json.loads(self.helper('audit', request_id))
        else:
            code = '''require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $r=App\\Infrastructure\\Audit\\ApiInteraction::where("request_id", trim(stream_get_contents(STDIN)))->sole(); echo json_encode(["request_id"=>$r->request_id,"status"=>$r->status]);'''
            row = json.loads(self.run('exec', '-T', 'app', 'php', '-r', code, stdin=request_id))
        logs = self.run('logs', '--no-color', '--no-log-prefix', 'app')
        events = []
        for line in logs.splitlines():
            try:
                event = json.loads(line)
                if event.get('message') == 'http_request_completed' and event.get('context', {}).get('request_id') == request_id:
                    events.append(event)
            except (ValueError, TypeError):
                continue
        require(row == {'request_id': request_id, 'status': expected_status}, 'Audit correlation mismatch')
        require(len(events) == 1 and events[0]['context']['status'] == expected_status, 'JSON log correlation mismatch')
        print('PASS header / audit / JSON log correlation: ' + request_id, flush=True)

    def verify_runtime(self):
        self.security()
        self.wait_scrapes()
        quiet = self.grafana(require_traffic=False)
        require(quiet[0]['values'] == ['0'] and quiet[7]['values'] == ['1'], 'Idle API must have up=1 and zero traffic')
        require(all(quiet[i]['series'] == 0 for i in [4, 5, 6]), 'No-traffic ratios and latencies must not show fictitious zeros')
        print('PASS actual idle state: up=1, RPS=0, ratio/latencies absent', flush=True)
        self.rejected_request_logging()
        first = self.concurrency()
        identity = json.loads(self.helper('identity'))
        self.run('restart', 'app')
        wait_http(self.port('nginx', 8080) + '/up')
        require(samples(self.internal()) == first, 'FPM restart lost counters')
        print('PASS restart preserves SQLite counters', flush=True)
        self.run('up', '-d', '--no-build', '--force-recreate', '--no-deps', '--wait', 'app', 'nginx')
        require(total(samples(self.internal()), 'devops_http_requests_total') == 0, 'App recreation did not reset counters')
        require(json.loads(self.helper('identity')) == identity, 'Recreation changed audit data or Passport keys')
        print('PASS recreation resets metrics and preserves audit / Passport', flush=True)
        self.concurrency()
        base_url = self.port('nginx', 8080)
        status, headers, _ = self.http(base_url + '/api/v1/gifs')
        require(status == 401, 'Deterministic request failed')
        self.correlation(headers, 401)
        # A real SQLite writer lock must not change the API response or split aggregates.
        before_lock = samples(self.internal())
        lock = subprocess.Popen(self.compose + ['exec', '-T', 'app', 'php', '/tmp/observability-runtime.php', 'lock'], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True, env=self.environment)
        try:
            require(lock.stdout.readline().strip() == 'locked', 'Writer lock fixture failed')
            started = time.monotonic()
            status, _, _ = self.http(base_url + '/api/v1/gifs')
            require(status == 401 and time.monotonic() - started < 2, 'Writer lock changed or excessively delayed the response')
            require(samples(self.internal()) == before_lock, 'Locked transaction partially updated metrics')
        finally:
            lock.wait(timeout=10)
        print('PASS real SQLite lock: bounded wait, unchanged 401, coherent unchanged scrape', flush=True)
        # Real storage failure, restore regardless of assertion outcome.
        self.helper('break')
        try:
            status, _, _ = self.http(base_url + '/api/v1/gifs')
            require(status == 401, 'Storage failure changed API response')
            # Use wget's diagnostic to verify actual HTTP 503 without exposing body.
            result = subprocess.run(self.compose + ['exec', '-T', 'nginx', 'wget', '-S', '-O', '/dev/null', 'http://127.0.0.1:9091/metrics'], capture_output=True, text=True, env=self.environment)
            require('503' in result.stderr, 'Failed storage scrape must return 503')
            deadline = time.monotonic() + 35
            while time.monotonic() < deadline:
                if self.query('up{job="laravel",instance="nginx:9091"} == 0'):
                    break
                time.sleep(2)
            else:
                raise RuntimeError('Prometheus did not observe failed collection as up=0')
        finally:
            self.helper('restore')
        print('PASS inaccessible metrics storage preserves 401; scrape returns 503', flush=True)
        logs = self.run('logs', '--no-color', '--no-log-prefix', 'app', 'nginx')
        require('metrics_recording_failed' in logs and 'metrics_collection_failed' in logs, 'Missing safe storage failure events')
        for secret in ['forbidden-query-secret', 'forbidden-header-secret']:
            require(secret not in logs, 'Native or application log leaked a secret')
        traffic_stop = threading.Event()
        traffic_errors = []
        def traffic():
            while not traffic_stop.is_set():
                try:
                    require(self.http(base_url + '/api/v1/gifs')[0] == 401, 'Deterministic traffic failed')
                except Exception as error:
                    traffic_errors.append(type(error).__name__)
                traffic_stop.wait(0.5)
        traffic_thread = threading.Thread(target=traffic)
        traffic_thread.start()
        try:
            self.wait_scrapes()
            require(not traffic_errors, 'Sustained traffic failed')
            self.grafana()
        finally:
            traffic_stop.set()
            traffic_thread.join()


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def samples(text):
    result = {}
    for line in text.splitlines():
        if not line or line.startswith('#'):
            continue
        name, value = line.rsplit(' ', 1)
        result[name] = float(value)
    return result


def total(snapshot, name):
    return sum(v for key, v in snapshot.items() if key.split('{')[0] == name)


def coherent(snapshot):
    counts = {}
    counters = {}
    buckets = {}
    for key, value in snapshot.items():
        labels = dict(re.findall(r'(\w+)="([^"]*)"', key))
        pair = (labels.get('method'), labels.get('route'))
        if key.startswith('devops_http_requests_total{'):
            counters[pair] = counters.get(pair, 0) + value
        if key.startswith('devops_http_request_duration_seconds_count{'):
            counts[pair] = value
        if key.startswith('devops_http_request_duration_seconds_bucket{'):
            buckets.setdefault(pair, []).append((float(labels['le']), value))
    for pair, values in buckets.items():
        ordered = [value for _, value in sorted(values)]
        if ordered != sorted(ordered) or ordered[-1] != counts.get(pair):
            return False
    return all(counters.get(k, 0) == counts.get(k, 0) for k in counters.keys() | counts.keys())


def wait_http(url, timeout=60):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        try:
            if Verification.http(url)[0] == 200:
                return
        except OSError:
            pass
        time.sleep(1)
    raise RuntimeError('HTTP readiness timeout')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--env-file', required=True)
    parser.add_argument('--grafana-only', action='store_true')
    args = parser.parse_args()
    try:
        verification = Verification(args.env_file)
        if args.grafana_only:
            verification.grafana()
        else:
            verification.verify_runtime()
    except Exception as error:
        print('FAIL: ' + str(error), flush=True)
        raise SystemExit(1)
