"""Run actual model credential DAGs on a disposable native remote-only serve/worker."""
import asyncio
import copy
import json
import os
import shutil
import socket
import subprocess
import threading
import time
import unittest
import urllib.request

import websockets
import yaml
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
                   "--cache-dir", str(self.repo / "worker-cache"), "--label", "node=209", "--concurrency", "3")

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

    def test_same_router_serializes_across_parents_while_another_router_runs(self):
        """Hold the real revoke command's native lock after its HTTP mutation."""
        entered = threading.Event()
        release = threading.Event()
        self.addCleanup(release.set)

        def barrier():
            entered.set()
            if not release.wait(30):
                raise AssertionError("Native overlap barrier was not released")

        self.router.barrier = barrier
        # Extend only the scratch command, after the shipped mutation completes.
        # This public barrier holds its native lock without stretching any router
        # request's production five-second deadline or relying on startup timing.
        path = self.repo / "workflows/workflow-task-vm-model-revoke.yaml"
        definition = yaml.safe_load(path.read_text())
        step = next(step for step in definition["jobs"][0]["steps"] if step["name"] == "key-revoke")
        step["task"]["inputs"]["run"] += """
/usr/bin/python3 - <<'PY'
import os
import urllib.request
urllib.request.urlopen(os.environ["ORBIT_MODEL_ORIGIN"] + "/_test/held", timeout=35).close()
PY
"""
        path.write_text(yaml.safe_dump(definition, sort_keys=False))
        with Router() as control:
            control_entered = threading.Event()
            control.on_request = control_entered.set
            second = self.inputs("issue")
            second["operation_id"] = 7002
            second["kind"] = "task_vm.enroll"
            second["intent"]["task_vm_id"] = 43
            second["execution_secrets"]["model_key"]["key"] = "task-vm-model-key-43"
            other = copy.deepcopy(second)
            other["operation_id"] = 7003
            other["kind"] = "task_vm.prepare_runtime"
            other["intent"].update(task_vm_id=44, model_router_node_id=4, model_origin=control.origin)
            other["execution_secrets"]["model_key"]["key"] = "task-vm-model-key-44"
            other["execution_secrets"]["model_router_anchor"]["key"] = "model-router-anchor-node-4"
            other["execution_secrets"]["model_router_management"]["key"] = "model-router-management-node-4"
            for key, value in (("task-vm-model-key-43", "4" * 64), ("task-vm-model-key-44", "5" * 64),
                               ("model-router-anchor-node-4", control.anchor),
                               ("model-router-management-node-4", control.management)):
                self.cli("vault", "put", "orbit-control-inputs", key, input=value)
            self.secrets += ("4" * 64, "5" * 64)
            token = (self.repo / "admin").read_text()

            async def overlap():
                second_started = asyncio.Event()

                async def run(name, inputs, mark_started=False):
                    async with websockets.connect(self.url, proxy=None,
                                                  additional_headers={"Authorization": "Bearer " + token}) as socket:
                        await socket.send(json.dumps({"type": "workflow.run", "id": "overlap",
                                                      "payload": {"workflowIdOrName": name, "inputs": inputs}}))
                        async for raw in socket:
                            message = json.loads(raw)
                            if message.get("id") != "overlap":
                                continue
                            self.assertNotEqual(message.get("type"), "error", message)
                            event = message.get("event", {})
                            if mark_started and event.get("kind") == "step_started":
                                second_started.set()
                            if event.get("kind") in ("completed", "cancelled"):
                                return event["run"]
                        raise AssertionError("Native event stream closed before terminal")

                tasks = [asyncio.create_task(run("task-vm-model-revoke", self.inputs("revoke")))]
                try:
                    self.assertTrue(await asyncio.to_thread(entered.wait, 20), "Revoke never reached its barrier")
                    requests_at_barrier = len(self.router.requests)
                    tasks.append(asyncio.create_task(run("task-vm-model-issue", second, True)))
                    tasks.append(asyncio.create_task(run("task-vm-model-issue", other)))
                    await asyncio.wait_for(second_started.wait(), 20)
                    self.assertTrue(await asyncio.to_thread(control_entered.wait, 20),
                                    "Different-router control did not overlap the held mutation")
                    control_run = await asyncio.wait_for(asyncio.shield(tasks[2]), 20)
                    self.assertEqual(control_run["status"], "succeeded")
                    self.assertFalse(release.is_set())
                    self.assertEqual(len(self.router.requests), requests_at_barrier,
                                     "Same-router parent reached HTTP while the mutation held its native lock")
                    release.set()
                    return await asyncio.wait_for(asyncio.gather(*tasks), 30)
                finally:
                    release.set()
                    for task in tasks:
                        if not task.done():
                            task.cancel()
                    await asyncio.gather(*tasks, return_exceptions=True)

            results = asyncio.run(overlap())
            self.assertEqual([result["status"] for result in results], ["succeeded"] * 3)
            self.assertEqual(self.router.keys, [self.router.anchor, self.router.other, "4" * 64])
            self.assertEqual(control.keys, [control.anchor, control.other, "5" * 64])
            self.assertEqual([method for method, _body in self.router.calls], ["PUT", "PATCH"])
            self.assert_private_absent(json.dumps(results))


if __name__ == "__main__":
    unittest.main()
