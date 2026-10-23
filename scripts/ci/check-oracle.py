#!/usr/bin/env python3
"""Integration against published images: isolated volumes, loopback TLS, no cloud."""
import importlib.machinery
import importlib.util
import ipaddress
import json
import os
from pathlib import Path
import secrets
import shutil
import ssl
import subprocess
import tempfile
import tarfile
import urllib.error
import urllib.request

REPO = Path(__file__).resolve().parents[2]
DEPLOY = REPO / "deploy/oracle"
loader = importlib.machinery.SourceFileLoader("oracle_runtime_ops", str(DEPLOY / "bin/oracle"))
spec = importlib.util.spec_from_loader(loader.name, loader)
ops_module = importlib.util.module_from_spec(spec)
loader.exec_module(ops_module)


def execute(command, **kwargs):
    return subprocess.run(command, check=True, **kwargs)


def main():
    global DEPLOY
    os.umask(0o077)
    project = "oracle-check-" + secrets.token_hex(5)
    restoration = project + "-restore"
    projects = [project, restoration]
    identifiers = execute(["docker", "network", "ls", "-q"], stdout=subprocess.PIPE).stdout.decode().split()
    networks = json.loads(execute(["docker", "network", "inspect", *identifiers], stdout=subprocess.PIPE).stdout) if identifiers else []
    occupied = [ipaddress.ip_network(item["Subnet"]) for network in networks for item in (network.get("IPAM", {}).get("Config") or []) if item.get("Subnet")]
    proxy_subnet = next(ipaddress.ip_network(f"10.213.{number}.0/24") for number in range(1, 255) if not any(ipaddress.ip_network(f"10.213.{number}.0/24").overlaps(item) for item in occupied if item.version == 4))
    proxy_ip = str(proxy_subnet.network_address + 2)
    with tempfile.TemporaryDirectory(prefix="oracle-runtime-", dir=os.environ.get("ORACLE_TEST_TMPDIR")) as temporary:
        work = Path(temporary)
        package = work / "deployment.tar.gz"
        execute([str(DEPLOY / "bin/oracle"), "package", "--output", str(package)])
        installed = work / "installed"
        with tarfile.open(package) as archive:
            archive.extractall(installed, filter="data")
        installed.chmod(0o755)
        for path in installed.rglob("*"):
            path.chmod(0o755 if path.is_dir() else 0o644)
        (installed / "bin/oracle").chmod(0o755)
        DEPLOY = installed
        private = work / "secrets"
        cli = [str(DEPLOY / "bin/oracle"), "--secrets-dir", str(private), "--project", project]
        execute(cli + ["prepare", "--domain", "localhost", "--email", "ops@example.invalid", "--proxy-subnet", str(proxy_subnet)])
        env = dict(os.environ, ORACLE_SECRETS_DIR=str(private), VOLUME_PREFIX=project.replace("-", "_"))
        compose = ["docker", "compose", "--project-directory", str(DEPLOY), "--env-file", str(private / "deploy.env"), "-p", project, "-f", str(DEPLOY / "compose.prod.yaml")]
        cleanup = [(compose.copy(), env.copy())]

        def dc(*args, **kwargs):
            return execute(compose + list(args), env=env, **kwargs)

        def php(source):
            return dc("exec", "-T", "app", "php", input=("<?php require '/var/www/html/vendor/autoload.php'; $a = require '/var/www/html/bootstrap/app.php'; $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); " + source).encode(), stdout=subprocess.PIPE).stdout

        try:
            print("[oracle] Clean initialization with production limits and published images", flush=True)
            execute(cli + ["init"])
            assert not dc("--profile", "https", "ps", "-a", "-q", "caddy", stdout=subprocess.PIPE).stdout.strip()
            initial_ids = dc("ps", "-a", "-q", stdout=subprocess.PIPE).stdout.decode().split()
            for identifier in initial_ids:
                state = json.loads(execute(["docker", "inspect", identifier], stdout=subprocess.PIPE).stdout)[0]
                assert not state["HostConfig"].get("PortBindings"), "Initialization must not publish any port"
            assert php("echo App\\Models\\User::count();").strip() == b"0", "Production must not seed users"
            cfg = json.loads(dc("--profile", "https", "config", "--format", "json", stdout=subprocess.PIPE).stdout)
            for name in ("app", "nginx"):
                image = json.loads(execute(["docker", "image", "inspect", cfg["services"][name]["image"]], stdout=subprocess.PIPE).stdout)[0]
                assert image["Architecture"] == "amd64"
                assert image["Config"]["Labels"]["org.opencontainers.image.revision"] == ops_module.EXPECTED_REVISION
            dc("run", "--rm", "-T", "--no-deps", "--entrypoint", "php-fpm", "app", "-tt", stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            dc("exec", "-T", "nginx", "nginx", "-t")
            mysql = dc("exec", "-T", "mysql", "sh", "-ec", 'export MYSQL_PWD="$MYSQL_PASSWORD"; mysql -N -u"$MYSQL_USER" "$MYSQL_DATABASE" -e "SELECT @@innodb_buffer_pool_size, @@max_connections, @@performance_schema, @@temptable_max_ram"', stdout=subprocess.PIPE).stdout
            assert mysql.strip() == b"134217728\t20\t0\t16777216", mysql
            print("[oracle] FPM, Nginx and MySQL effective configurations: OK", flush=True)

            # Test-only route and local CA; production code/configuration stay immutable.
            route = work / "api.php"
            route.write_text((REPO / "routes/api.php").read_text() + "\nRoute::get('_oracle_probe', fn (Illuminate\\Http\\Request $r) => response()->json(['secure' => $r->secure(), 'ip' => $r->ip(), 'port' => $r->getPort(), 'user' => auth('api')->id()]));\n")
            route.chmod(0o644)
            caddy = work / "Caddyfile"
            caddy.write_text((DEPLOY / "conf/Caddyfile").read_text().replace("email {$ACME_EMAIL}", "local_certs"))
            caddy.chmod(0o644)
            override = work / "compose.test.json"
            override.write_text(json.dumps({"services": {
                "app": {"volumes": [f"{route}:/var/www/html/routes/api.php:ro"]},
                "caddy": {"volumes": [f"{caddy}:/etc/caddy/Caddyfile:ro"], "ports": [
                    {"target": 443, "published": "0", "host_ip": "127.0.0.1", "protocol": "tcp"},
                    {"target": 80, "published": "0", "host_ip": "127.0.0.1", "protocol": "tcp"}]}}}))
            compose.extend(["-f", str(override), "--profile", "https"])
            dc("pull", "caddy")
            dc("up", "-d", "--no-build", "--pull", "never", "--wait", "--wait-timeout", "600")
            dc("exec", "-T", "caddy", "caddy", "validate", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile", stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            endpoint = dc("port", "caddy", "443", stdout=subprocess.PIPE).stdout.decode().strip()
            http_endpoint = dc("port", "caddy", "80", stdout=subprocess.PIPE).stdout.decode().strip()
            ca = work / "root.crt"
            ca.write_bytes(dc("exec", "-T", "caddy", "cat", "/data/caddy/pki/authorities/local/root.crt", stdout=subprocess.PIPE).stdout)
            context = ssl.create_default_context(cafile=str(ca))
            port = endpoint.rsplit(":", 1)[1]
            base = "https://localhost:" + port

            def request(path, data=None, headers=None):
                req = urllib.request.Request(base + path, data=data, headers=headers or {})
                with urllib.request.urlopen(req, context=context, timeout=20) as response:
                    return response.status, response.read()

            assert request("/up")[0] == 200
            status, body = request("/api/_oracle_probe", headers={"X-Forwarded-For": "198.51.100.99", "X-Forwarded-Proto": "http", "X-Forwarded-Port": "1234", "Forwarded": "for=198.51.100.99;proto=http"})
            probe = json.loads(body)
            assert probe["secure"] is True and probe["port"] == 443, probe
            assert probe["ip"] not in ("198.51.100.99", proxy_ip), probe
            # Compare Laravel's result against the TCP peer independently observed by Caddy.
            peers = dc("exec", "-T", "caddy", "sh", "-c", "cat /proc/net/arp", stdout=subprocess.PIPE).stdout.decode()
            assert probe["ip"] in peers, (probe, peers)
            try:
                request("/metrics")
                raise AssertionError("Metrics must be inaccessible")
            except urllib.error.HTTPError as error:
                assert error.code == 404
            class NoRedirect(urllib.request.HTTPRedirectHandler):
                def redirect_request(self, *args):
                    return None
            try:
                urllib.request.build_opener(NoRedirect).open("http://" + http_endpoint + "/up", timeout=10)
                raise AssertionError("HTTP must redirect")
            except urllib.error.HTTPError as error:
                assert error.code == 308 and error.headers["Location"].startswith("https://")
            print("[oracle] TLS verified with local CA; HTTPS, original client IP and spoof rejection: OK", flush=True)

            credentials = {"name": "Deployment validation", "email": "validation-" + secrets.token_hex(6) + "@example.invalid", "password": secrets.token_urlsafe(32)}
            execute(cli + ["provision-user", "--stdin"], input=json.dumps(credentials).encode())
            duplicate = subprocess.run(cli + ["provision-user", "--stdin"], input=json.dumps(credentials).encode(), stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            assert duplicate.returncode != 0
            _, body = request("/api/v1/login", json.dumps({key: credentials[key] for key in ("email", "password")}).encode(), {"Content-Type": "application/json", "Accept": "application/json"})
            token = json.loads(body)["access_token"]
            user_id = json.loads(request("/api/_oracle_probe", headers={"Authorization": "Bearer " + token})[1])["user"]
            assert user_id is not None
            key_hashes = php("echo hash_file('sha256', storage_path('passport/oauth-private.key')).hash_file('sha256', storage_path('passport/oauth-public.key'));")
            dc("up", "-d", "--no-build", "--pull", "never", "--force-recreate", "--no-deps", "app")
            dc("up", "-d", "--no-build", "--pull", "never", "--force-recreate", "--no-deps", "nginx")
            dc("up", "-d", "--no-build", "--pull", "never", "--wait", "--wait-timeout", "600")
            assert php("echo hash_file('sha256', storage_path('passport/oauth-private.key')).hash_file('sha256', storage_path('passport/oauth-public.key'));") == key_hashes
            assert json.loads(request("/api/_oracle_probe", headers={"Authorization": "Bearer " + token})[1])["user"] == user_id
            print("[oracle] User provisioning, duplicate refusal and token/key persistence: OK", flush=True)

            dc("stop", "mysql")
            assert request("/up")[0] == 200, "/up must not be mistaken for SQL readiness"
            failed = subprocess.run(cli + ["health"], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            assert failed.returncode != 0
            dc("up", "-d", "--no-build", "--pull", "never", "--wait", "--wait-timeout", "600", "mysql")
            bad = subprocess.run(compose + ["exec", "-T", "-e", "DB_PASSWORD=" + secrets.token_hex(32), "app", "php"], env=env, input=(DEPLOY / "bin/health.php").read_bytes(), stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            assert bad.returncode != 0, "Application SQL probe must detect invalid credentials"
            execute(cli + ["health"])
            print("[oracle] SQL outage and wrong app credentials detected despite HTTP/FPM health: OK", flush=True)

            init_id = dc("ps", "-a", "-q", "init", stdout=subprocess.PIPE).stdout.decode().strip()
            init_started = execute(["docker", "inspect", "--format", "{{.State.StartedAt}}", init_id], stdout=subprocess.PIPE).stdout
            execute(cli + ["backup", "--destination", str(work / "backups")])
            assert execute(["docker", "inspect", "--format", "{{.State.StartedAt}}", init_id], stdout=subprocess.PIPE).stdout == init_started, "Backup must not rerun init or migrations"
            backup = next((work / "backups").glob("*.gpg"))
            # Stop/remove only this test's containers and networks; keep source volumes.
            dc("down", "--volumes", "--timeout", "30")
            for suffix in ops_module.VOLUMES:
                volume = json.loads(execute(["docker", "volume", "inspect", project.replace("-", "_") + "_" + suffix], stdout=subprocess.PIPE).stdout)[0]
                assert volume["Labels"]["devops.oracle.project"] == project, "External volumes must survive compose down --volumes"
            restore_secrets = work / "restore-secrets"
            restore_secrets.mkdir(mode=0o700)
            shutil.copyfile(private / "backup.passphrase", restore_secrets / "backup.passphrase")
            restore_cli = [str(DEPLOY / "bin/oracle"), "--secrets-dir", str(restore_secrets), "--project", restoration]
            damaged = work / "damaged.gpg"
            data = bytearray(backup.read_bytes())
            data[-10] ^= 1
            damaged.write_bytes(data)
            rejected = subprocess.run(restore_cli + ["restore", str(damaged), "--confirm-empty"], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            assert rejected.returncode != 0 and not (restore_secrets / "app.env").exists(), "Tampered backup must be rejected before restoring secrets/data"
            restore_env = dict(os.environ, ORACLE_SECRETS_DIR=str(restore_secrets), VOLUME_PREFIX=restoration.replace("-", "_"))
            restore_compose = ["docker", "compose", "--project-directory", str(DEPLOY), "--env-file", str(restore_secrets / "deploy.env"), "-p", restoration, "-f", str(DEPLOY / "compose.prod.yaml")]
            cleanup.append((restore_compose.copy(), restore_env.copy()))
            execute(restore_cli + ["restore", str(backup), "--confirm-empty"])
            # Install the same test-only TLS fixture/route on restored containers.
            compose[:] = restore_compose + ["-f", str(override), "--profile", "https"]
            env.clear()
            env.update(restore_env)
            dc("up", "-d", "--no-build", "--pull", "never", "--wait", "--wait-timeout", "600")
            endpoint = dc("port", "caddy", "443", stdout=subprocess.PIPE).stdout.decode().strip()
            base = "https://localhost:" + endpoint.rsplit(":", 1)[1]
            assert php("echo hash_file('sha256', storage_path('passport/oauth-private.key')).hash_file('sha256', storage_path('passport/oauth-public.key'));") == key_hashes
            assert json.loads(request("/api/_oracle_probe", headers={"Authorization": "Bearer " + token})[1])["user"] == user_id
            refusal = subprocess.run(restore_cli + ["restore", str(backup), "--confirm-empty"], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            assert refusal.returncode != 0
            logs = dc("logs", "--no-color", stdout=subprocess.PIPE).stdout
            assert credentials["password"].encode() not in logs and token.encode() not in logs
            dc("stats", "--no-stream")
            ids = dc("ps", "-q", stdout=subprocess.PIPE).stdout.decode().split()
            for identifier in ids:
                item = json.loads(execute(["docker", "inspect", identifier], stdout=subprocess.PIPE).stdout)[0]
                assert not item["State"]["OOMKilled"]
                for bindings in (item["NetworkSettings"]["Ports"] or {}).values():
                    assert not bindings or all(binding["HostIp"] == "127.0.0.1" for binding in bindings)
            print("[oracle] Encrypted backup restored to fresh volumes; existing token and CA retained; overwrite refused: OK", flush=True)
        finally:
            for command, environment in reversed(cleanup):
                subprocess.run(command + ["--profile", "https", "down", "--timeout", "30", "--remove-orphans"], env=environment, check=False)
            # The random test namespace is the only namespace eligible for deletion.
            for name in projects:
                for suffix in ops_module.VOLUMES:
                    subprocess.run(["docker", "volume", "rm", name.replace("-", "_") + "_" + suffix], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)


if __name__ == "__main__":
    main()
