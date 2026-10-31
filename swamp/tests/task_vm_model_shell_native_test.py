"""Run actual model credential DAGs on a disposable native remote-only serve/worker."""
import json
import os
import shutil
import socket
import subprocess
import time
import unittest
import urllib.request

from native_harness import NativeTestCase
from task_vm_model_shell_test import ROOT, Router


def reply(raw):
    for index, character in enumerate(raw):
        if character in "{[":
            try:
                return json.loads(raw[index:])
            except ValueError:
                pass
    raise AssertionError("Native JSON missing")


class ModelShellNativeTest(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        for action in ("issue", "revoke"):
            shutil.copy(ROOT / "workflows" / f"workflow-task-vm-model-{action}.yaml", self.repo / "workflows")
        self.router = Router([Router.anchor, Router.other, Router.model, Router.model]).__enter__()
        self.addCleanup(self.router.__exit__)
        self.secrets = (self.router.model, self.router.anchor, self.router.management)
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        for key, value in (("task-vm-model-key-42", self.router.model), ("model-router-anchor-node-3", self.router.anchor),
                           ("model-router-management-node-3", self.router.management)):
            self.cli("vault", "put", "orbit-control-inputs", key, input=value)
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

    def inputs(self, action):
        return {"operation_id": 7001, "node_id": 9,
                "kind": "task_vm.destroy" if action == "revoke" else "task_vm.provision",
                "intent": {"task_vm_id": 42, "gateway_node_id": 209,
                           "model_router_node_id": 3, "model_origin": self.router.origin},
                "execution_secrets": {name: {"vault": "orbit-control-inputs", "key": key}
                                      for name, key in (("model_key", "task-vm-model-key-42"),
                                                        ("model_router_anchor", "model-router-anchor-node-3"),
                                                        ("model_router_management", "model-router-management-node-3"))}}

    def history(self, result):
        return reply(self.cli("workflow", "history", "get", result["id"], *self.flags).stdout)

    def test_actual_revoke_dag_removes_duplicates_with_private_env_and_no_gateway(self):
        result = reply(self.cli("workflow", "run", "task-vm-model-revoke", "--input",
                                json.dumps(self.inputs("revoke")), *self.flags).stdout)
        history = self.history(result)
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(self.router.keys, [self.router.anchor, self.router.other])
        self.assertEqual(len(self.router.calls), 1)
        self.assertEqual(json.loads(history["jobs"][0]["steps"][-1]["outputs"]["stdout"]),
                         {"task_vm_id": 42, "revoked": True})
        self.assert_private_absent(json.dumps(history))

    def test_native_resume_rechecks_access_without_repeating_the_successful_write(self):
        self.router.apply = False
        result = reply(self.cli("workflow", "run", "task-vm-model-revoke", "--input",
                                json.dumps(self.inputs("revoke")), *self.flags, success=False).stdout)
        failed = self.history(result)
        self.assertEqual(failed["status"], "failed")
        steps = {step["name"]: step for step in failed["jobs"][0]["steps"]}
        self.assertEqual(steps["key-revoke"]["status"], "succeeded")
        self.assertEqual(steps["key-access"]["status"], "failed")
        self.assertEqual(steps["result"]["status"], "skipped")
        self.router.applied = self.router.keys.copy()
        resumed = reply(self.cli("workflow", "resume", "task-vm-model-revoke", "--run", result["id"],
                                 "--from", "key-access", *self.flags).stdout)
        self.assertEqual(resumed["status"], "succeeded")
        self.assertEqual(len(self.router.calls), 1)
        self.assert_private_absent(json.dumps(self.history(resumed)))


if __name__ == "__main__":
    unittest.main()
