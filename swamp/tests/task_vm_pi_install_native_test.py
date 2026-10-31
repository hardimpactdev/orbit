"""Exercise actual script shipping, native guard and resume on a disposable worker."""
import json
import os
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.request
from pathlib import Path

import yaml
from native_harness import NativeTestCase
from task_vm_model_shell_native_test import reply
from task_vm_pi_scripts_test import CONTENT, ROOT, SHA, fixture_scripts


class PiInstallNativeTest(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/task-vm-pi")
        scratch = tempfile.TemporaryDirectory(prefix="orbit-pi-native-", dir=Path.home())
        self.addCleanup(scratch.cleanup)
        self.fixture = Path(scratch.name)
        self.home = self.fixture / "home"
        self.home.mkdir(mode=0o755)
        self.cache = self.fixture / "cache"
        self.cache.mkdir(mode=0o700)
        self.source = self.cache / (SHA + ".bin")
        self.source.write_bytes(CONTENT)
        self.source.chmod(0o600)
        scripts = self.repo / "extensions/behaviors/operations/task-vm-pi/scripts"
        shutil.rmtree(scripts)
        fixture_scripts(scripts, self.home, self.cache)
        workflow = yaml.safe_load((ROOT / "workflows/workflow-task-vm-pi-install.yaml").read_text())
        for step in workflow["jobs"][0]["steps"]:
            inputs = step["task"].get("inputs", {})
            if "run" in inputs:
                # Keep the real shipped scripts; run as the scratch file owner.
                inputs["run"] = inputs["run"].replace("/usr/bin/sudo -n -- ", "")
        (self.repo / "workflows/workflow-task-vm-pi-install.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))
        validation = reply(self.cli("workflow", "validate", "task-vm-pi-install").stdout)
        self.assertTrue(validation["passed"], validation)
        self.cli("access", "token", "mint", "fixture-admin", "--principal", "user:fixture")
        admin = self.repo / "admin"
        admin.write_text(reply(self.cli("access", "token", "reveal", "fixture-admin", "--yes").stdout)["token"])
        admin.chmod(0o600)
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        self.url = f"ws://127.0.0.1:{port}"
        self.flags = ("--server", self.url, "--token-file", str(admin))
        self.environ = dict(os.environ, SWAMP_NO_TELEMETRY="1", SWAMP_NO_UPDATE_CHECK="1")
        for key in ("SWAMP_SERVER_URL", "SWAMP_SERVE_URL", "SWAMP_SERVER_TOKEN", "SWAMP_SERVER_TOKEN_FILE",
                    "SWAMP_REPO_DIR", "SWAMP_CA_CERT", "SWAMP_EXTENSIONS_DIR"):
            self.environ.pop(key, None)
        self.start("serve", "--host", "127.0.0.1", "--port", str(port), "--auth-mode", "token",
                   "--admins", "user:fixture", "--remote-only")
        for _ in range(100):
            try:
                urllib.request.urlopen(f"http://127.0.0.1:{port}/ready", timeout=1).close()
                break
            except OSError:
                time.sleep(.1)
        enrollment = self.repo / "enrollment"
        enrollment.write_text(reply(self.cli("worker", "token", "create", "orbit-node-209",
                                              "--duration", "10m", *self.flags).stdout)["token"])
        enrollment.chmod(0o600)
        self.cli("access", "token", "mint", "fixture-worker", "--principal", "worker:orbit-node-209", *self.flags)
        token = self.repo / "worker-server"
        token.write_text(reply(self.cli("access", "token", "reveal", "fixture-worker", "--yes").stdout)["token"])
        token.chmod(0o600)
        self.start("worker", "connect", self.url, "--token-file", str(enrollment), "--server-token-file", str(token),
                   "--cache-dir", str(self.repo / "worker-cache"), "--label", "node=209")

    def start(self, *args):
        log = (self.repo / (args[0] + ".log")).open("w")
        self.addCleanup(log.close)
        process = subprocess.Popen([os.environ.get("SWAMP_BIN", "swamp"), *args], cwd=self.repo,
                                   env=self.environ, stdout=log, stderr=log)

        def stop():
            process.terminate()
            try:
                process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)

        self.addCleanup(stop)

    def run_install(self, success=True):
        inputs = {"operation_id": 7001, "node_id": 9, "kind": "task_vm.provision",
                  "intent": {"task_vm_id": 42, "guest_node_id": 209, "user": "orbit", "runtime": {"pi_sha256": SHA}}}
        result = reply(self.cli("workflow", "run", "task-vm-pi-install", "--input", json.dumps(inputs),
                                *self.flags, success=success).stdout)
        history = reply(self.cli("workflow", "history", "get", result["id"], *self.flags).stdout)
        return result, {step["name"]: step for step in history["jobs"][0]["steps"]}

    def test_native_assets_install_and_guarded_skip_still_verify(self):
        result, steps = self.run_install()
        self.assertEqual(result["status"], "succeeded")
        self.assertEqual((self.home / ".local/bin/pi-server").read_bytes(), CONTENT)
        self.assertIn("worker-cache", steps["assets"]["outputs"]["install_script"])
        self.assertEqual(json.loads(steps["result"]["outputs"]["stdout"]),
                         {"task_vm_id": 42, "sha256": SHA, "installed": True})
        self.source.unlink()
        result, steps = self.run_install()
        self.assertEqual(result["status"], "succeeded")
        self.assertEqual(steps["install"]["status"], "skipped")
        self.assertEqual(steps["result"]["status"], "succeeded")

    def test_native_corrupt_cache_fails_then_resumes_from_install(self):
        self.source.write_bytes(b"wrong artifact")
        result, steps = self.run_install(success=False)
        self.assertEqual(result["status"], "failed")
        self.assertEqual(steps["install"]["status"], "failed")
        self.assertEqual(steps["result"]["status"], "skipped")
        self.assertFalse((self.home / ".local/bin/pi-server").exists())
        self.source.write_bytes(CONTENT)
        resumed = reply(self.cli("workflow", "resume", "task-vm-pi-install", "--run", result["id"],
                                 "--from", "install", *self.flags).stdout)
        self.assertEqual(resumed["status"], "succeeded")
        self.assertEqual((self.home / ".local/bin/pi-server").read_bytes(), CONTENT)
