"""Static/security checks require Compose v2+, Python 3.11+, but no Docker daemon."""
import base64
import importlib.machinery
import importlib.util
import json
import os
import contextlib
import io
from pathlib import Path
import subprocess
import shutil
import runpy
import signal
import sys
import tempfile
import tarfile
import textwrap
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
DEPLOY = ROOT / "deploy/oracle"
loader = importlib.machinery.SourceFileLoader("oracle_operations", str(DEPLOY / "bin/oracle"))
spec = importlib.util.spec_from_loader(loader.name, loader)
module = importlib.util.module_from_spec(spec)
loader.exec_module(module)


class DeploymentTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temporary = tempfile.TemporaryDirectory(prefix="oracle-static-")
        cls.secrets = Path(cls.temporary.name) / "secrets"
        cls.base = [str(DEPLOY / "bin/oracle"), "--secrets-dir", str(cls.secrets), "--project", "oracle-static-check"]
        cls.prepare = ["prepare", "--domain", "api.example.invalid", "--email", "ops@example.invalid"]
        subprocess.run(cls.base + cls.prepare, check=True, stdout=subprocess.PIPE)
        cls.env = dict(os.environ, ORACLE_SECRETS_DIR=str(cls.secrets), VOLUME_PREFIX="oracle_static_check")
        cls.compose = ["docker", "compose", "--env-file", str(cls.secrets / "deploy.env"), "-f", str(DEPLOY / "compose.prod.yaml")]
        cls.config = json.loads(subprocess.check_output(cls.compose + ["--profile", "https", "config", "--format", "json"], env=cls.env))

    @classmethod
    def tearDownClass(cls):
        cls.temporary.cleanup()

    def test_no_public_ports_builds_monitoring_or_privilege(self):
        self.assertEqual(set(self.config["services"]), {"mysql", "init", "app", "nginx", "caddy"})
        for item in self.config["services"].values():
            self.assertFalse(item.get("ports"))
            self.assertNotIn("build", item)
            self.assertFalse(item.get("privileged", False))
            self.assertRegex(item["image"], r"@sha256:[a-f0-9]{64}$")
            self.assertEqual(item["platform"], "linux/amd64")
        self.assertEqual(self.config["services"]["caddy"]["profiles"], ["https"])
        self.assertEqual(self.config["services"]["mysql"]["networks"], {"database": None})
        for network in ("database", "fastcgi", "proxy"):
            self.assertTrue(self.config["networks"][network]["internal"])

    def test_persistence_permissions_and_secret_boundaries(self):
        for item in self.config["volumes"].values():
            self.assertTrue(item["external"])
            self.assertTrue(item["name"].startswith("oracle_static_check_"))
        app = self.config["services"]["app"]
        init = self.config["services"]["init"]
        mysql = self.config["services"]["mysql"]
        for item in (app, init):
            environment = item["environment"]
            self.assertEqual(environment["APP_ENV"], "production")
            self.assertEqual(environment["APP_DEBUG"], "false")
            self.assertEqual(environment["METRICS_ENABLED"], "false")
            self.assertEqual(environment["TRUSTED_PROXIES"], "172.30.77.2")
            self.assertNotIn("MYSQL_ROOT_PASSWORD", environment)
        self.assertEqual(app["environment"]["DB_PASSWORD"], mysql["environment"]["MYSQL_PASSWORD"])
        self.assertNotEqual(mysql["environment"]["MYSQL_PASSWORD"], mysql["environment"]["MYSQL_ROOT_PASSWORD"])
        self.assertEqual(len(base64.b64decode(app["environment"]["APP_KEY"].removeprefix("base64:"))), 32)
        app_keys = next(v for v in app["volumes"] if v["target"].endswith("/passport"))
        init_keys = next(v for v in init["volumes"] if v["target"].endswith("/passport"))
        self.assertTrue(app_keys["read_only"])
        self.assertFalse(init_keys.get("read_only", False))
        for path in self.secrets.iterdir():
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
        self.assertEqual(self.secrets.stat().st_mode & 0o777, 0o700)

    def test_memory_and_startup_contract(self):
        services = self.config["services"]
        self.assertEqual(sum(int(services[s]["mem_limit"]) for s in ("mysql", "app", "nginx", "caddy")), 704 * 1024**2)
        for item in services.values():
            self.assertGreater(int(item["memswap_limit"]), int(item["mem_limit"]))
        self.assertEqual(services["init"]["restart"], "no")
        self.assertEqual(services["init"]["depends_on"]["mysql"]["condition"], "service_healthy")
        self.assertEqual(services["app"]["depends_on"]["init"]["condition"], "service_completed_successfully")
        self.assertIn("SELECT 1", services["mysql"]["healthcheck"]["test"][1])

    def test_proxy_address_is_reserved_outside_dynamic_range(self):
        import ipaddress
        network = self.config["networks"]["proxy"]["ipam"]["config"][0]
        proxy = ipaddress.ip_address(self.config["services"]["caddy"]["networks"]["proxy"]["ipv4_address"])
        dynamic = ipaddress.ip_network(network["ip_range"])
        self.assertTrue(dynamic.subnet_of(ipaddress.ip_network(network["subnet"])))
        self.assertNotIn(proxy, dynamic)

    @unittest.skipUnless(shutil.which("systemd-analyze"), "systemd-analyze is unavailable")
    def test_systemd_unit_syntax(self):
        with tempfile.TemporaryDirectory(prefix="oracle-units-") as directory:
            paths = []
            for source in (DEPLOY / "systemd").iterdir():
                target = Path(directory) / source.name
                target.write_text(source.read_text().replace("/opt/devops-tp/bin/oracle", str(DEPLOY / "bin/oracle")))
                paths.append(str(target))
            subprocess.run(["systemd-analyze", "verify", "--man=no", *paths], check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)

    def test_publication_requires_explicit_address(self):
        command = self.compose + ["-f", str(DEPLOY / "compose.https.yaml"), "--profile", "https", "config", "--format", "json"]
        missing = dict(self.env)
        missing.pop("PUBLIC_BIND_IP", None)
        result = subprocess.run(command, env=missing, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.assertNotEqual(result.returncode, 0)
        public = json.loads(subprocess.check_output(command, env=dict(missing, PUBLIC_BIND_IP="127.0.0.1")))
        self.assertEqual({p["target"] for p in public["services"]["caddy"]["ports"]}, {80, 443})
        self.assertTrue(all(p["host_ip"] == "127.0.0.1" for p in public["services"]["caddy"]["ports"]))
        for name in ("mysql", "app", "nginx", "init"):
            self.assertFalse(public["services"][name].get("ports"))

    def test_prepare_refuses_to_overwrite_or_use_checkout(self):
        before = {p.name: p.read_bytes() for p in self.secrets.iterdir()}
        result = subprocess.run(self.base + self.prepare, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(before, {p.name: p.read_bytes() for p in self.secrets.iterdir()})
        result = subprocess.run([str(DEPLOY / "bin/oracle"), "--secrets-dir", str(ROOT / ".oracle-forbidden"), *self.prepare], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((ROOT / ".oracle-forbidden").exists())

    def test_prepare_rejects_loopback_and_public_subnets(self):
        for index, subnet in enumerate(("127.0.0.0/24", "192.0.2.0/24", "8.8.8.0/24")):
            directory = Path(self.temporary.name) / f"invalid-{index}"
            result = subprocess.run([str(DEPLOY / "bin/oracle"), "--secrets-dir", str(directory), *self.prepare, "--proxy-subnet", subnet], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse(directory.exists())

    def test_public_configs_have_no_secret_interpolation(self):
        for name in ("nginx", "caddy"):
            environment = self.config["services"][name].get("environment", {})
            self.assertFalse(set(environment) & {"APP_KEY", "DB_PASSWORD", "MYSQL_ROOT_PASSWORD", "GIPHY_API_KEY"})

    def test_package_is_complete_private_and_reproducible(self):
        with tempfile.TemporaryDirectory(prefix="oracle-package-") as temporary:
            work = Path(temporary)
            first, second = work / "first.tar.gz", work / "second.tar.gz"
            for output in (first, second):
                subprocess.run([str(DEPLOY / "bin/oracle"), "package", "--output", str(output)], check=True, stdout=subprocess.PIPE)
            self.assertEqual(first.read_bytes(), second.read_bytes())
            with tarfile.open(first) as archive:
                members = archive.getmembers()
                self.assertEqual({item.name for item in members}, set(module.PACKAGE_FILES))
                self.assertEqual(len(members), len(module.PACKAGE_FILES))
                for item in members:
                    self.assertTrue(item.isfile())
                    self.assertEqual((item.uid, item.gid), (0, 0))
                    self.assertEqual(item.mode, 0o755 if item.name == "bin/oracle" else 0o644)
                installed = work / "installed"
                archive.extractall(installed, filter="data")
            # Apply the documented install modes, then exercise the copied CLI.
            for path in installed.rglob("*"):
                path.chmod(0o755 if path.is_dir() else 0o644)
            (installed / "bin/oracle").chmod(0o755)
            (installed / ".env").write_text("UNLISTED=1\n")
            filtered = work / "filtered.tar.gz"
            subprocess.run([str(installed / "bin/oracle"), "package", "--output", str(filtered)], check=True, stdout=subprocess.PIPE)
            self.assertEqual(first.read_bytes(), filtered.read_bytes(), "Unlisted files must never enter the package")
            secret_dir = work / "secrets"
            subprocess.run([str(installed / "bin/oracle"), "--secrets-dir", str(secret_dir), *self.prepare], check=True, stdout=subprocess.PIPE)
            config = json.loads(subprocess.check_output(["docker", "compose", "--project-directory", str(installed),
                "--env-file", str(secret_dir / "deploy.env"), "-f", str(installed / "compose.prod.yaml"),
                "--profile", "https", "config", "--format", "json"], env=dict(os.environ, ORACLE_SECRETS_DIR=str(secret_dir))))
            for service in config["services"].values():
                for mount in service.get("volumes", []):
                    if mount["type"] == "bind":
                        source = Path(mount["source"])
                        self.assertTrue(source.is_relative_to(installed))
                        self.assertTrue(source.is_file())
                        self.assertEqual(source.stat().st_mode & 0o777, 0o644)

    def test_volume_reuse_refuses_other_project_before_any_creation(self):
        from types import SimpleNamespace
        ops = module.Operations(SimpleNamespace(project="oracle-static-check", secrets_dir=str(self.secrets)))
        result = subprocess.CompletedProcess([], 0, json.dumps([{"Labels": {"devops.oracle.project": "other"}}]).encode(), b"")
        with patch.object(module.subprocess, "run", return_value=result) as invoked:
            with self.assertRaisesRegex(ValueError, "another project"):
                ops.create_volumes()
            self.assertEqual(invoked.call_count, 1)
            self.assertEqual(invoked.call_args.args[0][:3], ["docker", "volume", "inspect"])

    def test_maintenance_attempts_every_restart_on_failure(self):
        from types import SimpleNamespace
        ops = module.Operations(SimpleNamespace(project="oracle-static-check", secrets_dir=str(self.secrets)))
        ops.running = lambda: ["app", "nginx", "caddy"]
        ops.dc = lambda *args, **kwargs: subprocess.CompletedProcess([], 0, b"test-container\n")
        invoked = []
        def start(command, **kwargs):
            invoked.append(command)
            if len(invoked) == 1:
                raise subprocess.CalledProcessError(1, command)
        with patch.object(module, "run", side_effect=start):
            with self.assertRaisesRegex(ValueError, "resume services"):
                with ops.maintenance():
                    pass
        self.assertEqual(len(invoked), 3)

    def test_sigterm_unwinds_maintenance_and_resumes_consumers(self):
        from types import SimpleNamespace
        with patch.object(sys, "argv", [str(DEPLOY / "bin/oracle"), "--help"]), patch("signal.signal") as register, contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaises(SystemExit):
                runpy.run_path(str(DEPLOY / "bin/oracle"), run_name="__main__")
        self.assertEqual(register.call_args.args[0], signal.SIGTERM)
        handler = register.call_args.args[1]
        ops = module.Operations(SimpleNamespace(project="oracle-static-check", secrets_dir=str(self.secrets)))
        ops.running = lambda: ["app", "nginx", "caddy"]
        ops.dc = lambda *args, **kwargs: subprocess.CompletedProcess([], 0, b"test-container\n")
        with patch.object(module, "run") as start:
            with self.assertRaises(SystemExit) as terminated:
                with ops.maintenance():
                    handler(signal.SIGTERM, None)
        self.assertEqual(terminated.exception.code, 143)
        self.assertEqual(start.call_count, 3)


class WorkflowTest(unittest.TestCase):
    def test_oracle_runs_only_for_pr_and_release_gate_is_preserved(self):
        workflow = (ROOT / ".github/workflows/ci.yml").read_text()
        oracle = workflow.split("  oracle:\n", 1)[1].split("  required:\n", 1)[0]
        self.assertIn("if: ${{ github.event_name == 'pull_request' }}", oracle)
        required = workflow.split("  required:\n", 1)[1]
        gate = textwrap.dedent(required.split("        run: |\n", 1)[1])
        for event, oracle_result, expected in (("pull_request", "success", 0), ("pull_request", "failure", 1),
                ("pull_request", "skipped", 1), ("push", "skipped", 0), ("push", "failure", 1)):
            environment = dict(os.environ, QUALITY="success", PHPUNIT="success", OBSERVABILITY="success", ORACLE=oracle_result, EVENT_NAME=event)
            self.assertEqual(subprocess.run(["bash", "-e", "-c", gate], env=environment).returncode, expected)
        for name in ("QUALITY", "PHPUNIT", "OBSERVABILITY"):
            for event, oracle_result in (("pull_request", "success"), ("push", "skipped")):
                environment = dict(os.environ, QUALITY="success", PHPUNIT="success", OBSERVABILITY="success", ORACLE=oracle_result, EVENT_NAME=event)
                environment[name] = "failure"
                self.assertNotEqual(subprocess.run(["bash", "-e", "-c", gate], env=environment).returncode, 0)


if __name__ == "__main__":
    unittest.main()
