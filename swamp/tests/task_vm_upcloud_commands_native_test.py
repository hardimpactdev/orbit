"""Real native provider DAG and resume against a disposable HTTP boundary."""

import json
import unittest
from pathlib import Path

import yaml
from native_harness import NativeTestCase
from task_vm_upcloud_commands_test import (
    IMAGE_ID,
    PUBLIC_KEY,
    SERVER_ID,
    ProviderApi,
    public_intent,
)

ROOT = Path(__file__).resolve().parents[1]


class UpCloudCommandsNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.api = ProviderApi().__enter__()
        self.addCleanup(self.api.__exit__)
        self.secrets = (self.api.token, self.api.private)
        family = "operations/task-vms/upcloud-provider"
        self.copy_behaviors(family)
        scripts = self.repo / "extensions/behaviors" / family / "scripts"
        path = scripts / "upcloud_common.py"
        path.write_text(
            path.read_text().replace("https://api.upcloud.com/1.3/", self.api.origin)
        )
        source = ROOT / "workflows/workflow-task-vm-provider-upcloud.yaml"
        definition = yaml.safe_load(source.read_text())
        target = '${{ "orbit-node-" + string(inputs.intent.gateway_node_id) }}'
        labels = {"node": "${{ string(inputs.intent.gateway_node_id) }}"}
        for job in definition["jobs"]:
            self.assertEqual(job.pop("target"), target)
            self.assertEqual(job.pop("labels"), labels)
            for step in job["steps"]:
                self.assertEqual(step.pop("target"), target)
                self.assertEqual(step.pop("labels"), labels)
        self.workflow = self.repo / "workflows" / source.name
        self.workflow.write_text(yaml.safe_dump(definition, sort_keys=False))
        self.inputs = {
            "operation_id": 7001,
            "kind": "tasks.provision",
            "node_id": 14,
            "intent": public_intent(),
            "execution_secrets": {
                "upcloud_token": {
                    "vault": "orbit-control-inputs",
                    "key": "upcloud-api-gateway-14",
                },
                "provider_bootstrap": {
                    "vault": "orbit-control-inputs",
                    "key": "task-vm-provider-bootstrap-184",
                },
            },
        }
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            "upcloud-api-gateway-14",
            input=self.api.token,
        )
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            "task-vm-provider-bootstrap-184",
            input=json.dumps({"private": self.api.private, "public": PUBLIC_KEY}),
        )

    def run_provider(self, success=True):
        return self.cli(
            "workflow",
            "run",
            "task-vm-provider-upcloud",
            "--input",
            json.dumps(self.inputs),
            success=success,
        )

    def run_id(self, result):
        raw = result.stdout
        for index, value in enumerate(raw):
            if value == "{":
                try:
                    return json.loads(raw[index:])["id"]
                except (ValueError, KeyError):
                    pass
        self.fail("Native result missing")

    def history(self, run_id):
        result = self.cli("workflow", "history", "get", run_id)
        raw = result.stdout
        for index, value in enumerate(raw):
            if value == "{":
                try:
                    return json.loads(raw[index:])
                except ValueError:
                    pass
        self.fail("Native history missing")

    def test_native_create_preserves_tasks_root_and_public_provider_output(self):
        self.cli("workflow", "validate", "task-vm-provider-upcloud")
        result = self.run_provider()
        history = self.history(self.run_id(result))
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(history["inputs"], self.inputs)
        self.assertEqual(self.api.created, 1)
        self.assertTrue(self.api.received_private)
        provider = history["jobs"][0]["steps"][-1]["outputs"]["provider"]
        self.assertEqual(
            provider,
            {
                "provider_id": SERVER_ID,
                "address": "8.8.4.4",
                "ssh_host_key": PUBLIC_KEY,
                "image_fingerprint": IMAGE_ID,
                "running": True,
                "settled": True,
            },
        )
        self.assert_private_absent(json.dumps(history))

    def test_native_resume_lost_create_reply_never_creates_a_second_server(self):
        self.api.lose_create_reply = True
        self.api.hide_inventory_after_create = True
        run_id = self.run_id(self.run_provider(success=False))
        self.assertEqual(self.api.created, 1)
        self.api.lose_create_reply = False
        self.api.hide_inventory_after_create = False
        self.api.calls.clear()
        self.cli("workflow", "resume", "task-vm-provider-upcloud", "--run", run_id)
        self.assertEqual(self.api.created, 1)
        self.assertFalse(any(method == "POST" for method, _path in self.api.calls))

    def test_native_readiness_resume_observes_only_and_never_replays_create(self):
        self.api.pending = True
        run_id = self.run_id(self.run_provider(success=False))
        self.assertEqual(self.api.created, 1)
        self.api.server_record["state"] = "started"
        self.api.calls.clear()
        self.cli(
            "workflow",
            "resume",
            "task-vm-provider-upcloud",
            "--run",
            run_id,
            "--from",
            "observe",
        )
        self.assertEqual(self.api.calls, [("GET", "/server/" + SERVER_ID)])

    def test_native_wrong_root_executor_refuses_before_api_or_secret_action(self):
        self.inputs["node_id"] = 209
        self.run_provider(success=False)
        self.assertEqual(self.api.calls, [])

    def test_native_cross_parent_budget_uses_one_stable_create_definition(self):
        import asyncio
        import copy
        import os
        import socket
        import subprocess
        import threading
        import time
        import urllib.request

        import websockets

        self.workflow.write_text(
            (ROOT / "workflows/workflow-task-vm-provider-upcloud.yaml").read_text()
        )
        entered, release, control = (
            threading.Event(),
            threading.Event(),
            threading.Event(),
        )
        self.addCleanup(release.set)
        self.api.create_entered, self.api.create_release, self.api.control_entered = (
            entered,
            release,
            control,
        )
        self.inputs["intent"]["upcloud"]["max_vms"] = 1
        second = copy.deepcopy(self.inputs)
        second["operation_id"], second["kind"] = 7002, "task_vm.provision"
        second["intent"].update(task_vm_id=185, name="tvm-185")
        second["execution_secrets"]["provider_bootstrap"]["key"] = (
            "task-vm-provider-bootstrap-185"
        )
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            "task-vm-provider-bootstrap-185",
            input=json.dumps({"private": self.api.private, "public": PUBLIC_KEY}),
        )
        self.cli(
            "access", "token", "mint", "fixture-admin", "--principal", "user:fixture"
        )
        admin = self.repo / "admin"
        admin.write_text(
            self.native_json(
                self.cli("access", "token", "reveal", "fixture-admin", "--yes").stdout
            )["token"]
        )
        admin.chmod(0o600)
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        url = f"ws://127.0.0.1:{port}"
        env = dict(os.environ, SWAMP_NO_TELEMETRY="1", SWAMP_NO_UPDATE_CHECK="1")
        for name in (
            "SWAMP_SERVER_URL",
            "SWAMP_SERVE_URL",
            "SWAMP_SERVER_TOKEN",
            "SWAMP_SERVER_TOKEN_FILE",
            "SWAMP_REPO_DIR",
            "SWAMP_CA_CERT",
            "SWAMP_EXTENSIONS_DIR",
        ):
            env.pop(name, None)

        def start(*args):
            log = (self.repo / (args[0] + ".log")).open("w")
            self.addCleanup(log.close)
            process = subprocess.Popen(
                [os.environ.get("SWAMP_BIN", "swamp"), *args],
                cwd=self.repo,
                env=env,
                stdout=log,
                stderr=log,
            )

            def stop():
                process.terminate()
                try:
                    process.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)

            self.addCleanup(stop)
            return process

        control_script = """/usr/bin/python3 - <<'CONTROL'
import os, urllib.request
request=urllib.request.Request(os.environ['URL'],headers={'Authorization':'Bearer '+os.environ['ORBIT_UPCLOUD_TOKEN']})
urllib.request.urlopen(request,timeout=5).close()
CONTROL
"""
        control_workflow = {
            "id": "1ed1b49d-85f4-425d-b41c-4b18d3800dbe",
            "name": "upcloud-independent-control",
            "vaults": ["orbit-control-inputs"],
            "jobs": [
                {
                    "name": "control",
                    "steps": [
                        {
                            "name": "read",
                            "task": {
                                "type": "behavior",
                                "behaviorType": "command/shell",
                                "behaviorName": "upcloud-independent-control",
                                "methodName": "execute",
                                "inputs": {
                                    "run": control_script,
                                    "env": {
                                        "URL": self.api.origin + "_test/control",
                                        "ORBIT_UPCLOUD_TOKEN": '${{ vault.get("orbit-control-inputs","upcloud-api-gateway-14") }}',
                                    },
                                },
                            },
                        }
                    ],
                }
            ],
        }
        control_workflow["jobs"][0]["target"] = "orbit-node-14"
        control_workflow["jobs"][0]["labels"] = {"node": "14"}
        (self.repo / "workflows/workflow-upcloud-independent-control.yaml").write_text(
            yaml.safe_dump(control_workflow, sort_keys=False)
        )

        self.cli("workflow", "validate", "upcloud-independent-control")
        serve = start(
            "serve",
            "--host",
            "127.0.0.1",
            "--port",
            str(port),
            "--auth-mode",
            "token",
            "--admins",
            "user:fixture",
            "--remote-only",
        )
        for _attempt in range(100):
            try:
                urllib.request.urlopen(
                    f"http://127.0.0.1:{port}/ready", timeout=1
                ).close()
                break
            except OSError:
                self.assertIsNone(serve.poll())
                time.sleep(0.1)
        flags = ("--server", url, "--token-file", str(admin))
        enrollment = self.repo / "enrollment"
        enrollment.write_text(
            self.native_json(
                self.cli(
                    "worker",
                    "token",
                    "create",
                    "orbit-node-14",
                    "--duration",
                    "10m",
                    *flags,
                ).stdout
            )["token"]
        )
        enrollment.chmod(0o600)
        self.cli(
            "access",
            "token",
            "mint",
            "fixture-worker",
            "--principal",
            "worker:orbit-node-14",
            *flags,
        )
        token = self.repo / "worker-server"
        token.write_text(
            self.native_json(
                self.cli("access", "token", "reveal", "fixture-worker", "--yes").stdout
            )["token"]
        )
        token.chmod(0o600)
        start(
            "worker",
            "connect",
            url,
            "--token-file",
            str(enrollment),
            "--server-token-file",
            str(token),
            "--cache-dir",
            str(self.repo / "worker-cache"),
            "--label",
            "node=14",
            "--concurrency",
            "3",
        )

        async def overlap():
            events = []
            second_started = asyncio.Event()

            async def run(name, inputs, mark=False):
                async with websockets.connect(
                    url,
                    proxy=None,
                    additional_headers={"Authorization": "Bearer " + admin.read_text()},
                ) as stream:
                    await stream.send(
                        json.dumps(
                            {
                                "type": "workflow.run",
                                "id": "test",
                                "payload": {"workflowIdOrName": name, "inputs": inputs},
                            }
                        )
                    )
                    async for raw in stream:
                        message = json.loads(raw)
                        if message.get("id") != "test":
                            continue
                        self.assertNotEqual(message.get("type"), "error", message)
                        event = message.get("event", {})
                        self.assertNotEqual(event.get("kind"), "error", event)
                        events.append(
                            (
                                name,
                                event.get("kind"),
                                event.get("stepId"),
                                event.get("error"),
                            )
                        )
                        if (
                            mark
                            and event.get("kind") == "step_started"
                            and event.get("stepId") == "create"
                        ):
                            second_started.set()
                        if event.get("kind") in ("completed", "cancelled"):
                            return event["run"]
                    raise AssertionError("Terminal event missing")

            tasks = [asyncio.create_task(run("task-vm-provider-upcloud", self.inputs))]
            try:
                if not await asyncio.to_thread(entered.wait, 20):
                    if tasks[0].done():
                        result = tasks[0].result()
                        history = self.native_json(
                            self.cli(
                                "workflow", "history", "get", result["id"], *flags
                            ).stdout
                        )
                        self.fail(json.dumps(history))
                    self.fail(
                        "First provider run did not reach create; worker="
                        + (self.repo / "worker.log").read_text()[-2000:]
                    )
                tasks.append(
                    asyncio.create_task(run("task-vm-provider-upcloud", second, True))
                )
                await asyncio.wait_for(second_started.wait(), 20)
                before = len(
                    [call for call in self.api.calls if call[1] != "/_test/control"]
                )
                tasks.append(
                    asyncio.create_task(run("upcloud-independent-control", {}))
                )
                try:
                    result = await asyncio.wait_for(asyncio.shield(tasks[2]), 20)
                except TimeoutError:
                    self.fail(json.dumps(events))
                self.assertEqual(result["status"], "succeeded")
                self.assertTrue(control.is_set())
                self.assertEqual(
                    len(
                        [call for call in self.api.calls if call[1] != "/_test/control"]
                    ),
                    before,
                )
                self.assertEqual(self.api.calls.count(("POST", "/server")), 1)
                release.set()
                return await asyncio.wait_for(asyncio.gather(*tasks), 30)
            finally:
                release.set()
                for task in tasks:
                    if not task.done():
                        task.cancel()
                await asyncio.gather(*tasks, return_exceptions=True)

        results = asyncio.run(overlap())
        self.assertEqual(
            [result["status"] for result in results],
            ["succeeded", "failed", "succeeded"],
        )
        self.assertEqual(self.api.created, 1)
        self.assert_private_absent(json.dumps(results))

    def native_json(self, raw):
        for index, value in enumerate(raw):
            if value == "{":
                try:
                    return json.loads(raw[index:])
                except ValueError:
                    pass
        self.fail("Native JSON missing")


if __name__ == "__main__":
    unittest.main()
