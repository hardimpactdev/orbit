"""Docker replacement composition, without a second installer state machine."""

import importlib
import json
import os
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).parent / "scripts"))
common = importlib.import_module("process_common")
engine = importlib.import_module("docker_files")
create = importlib.import_module("create_container")
rename = importlib.import_module("rename_container")
remove = importlib.import_module("remove_container")
control = importlib.import_module("control")
pull = importlib.import_module("pull_image")


class DockerComponents(unittest.TestCase):
    def setUp(self):
        self.control = {
            "process_id": 17,
            "runtime": "docker",
            "name": "orbit-process-17-worker",
            "on_demand": False,
        }
        self.admitted = {
            **self.control,
            "desired": "running",
            "retired": None,
            "spec": "a" * 64,
        }
        self.private = {
            "runtime": "docker",
            "spec": "a" * 64,
            "definition": {
                "image": "alpine:3.23",
                "command": ["sleep", "300"],
                "restart": "unless-stopped",
                "working_directory": None,
                "ports": ["127.0.0.1:8080:80", "[::1]:5353:53/udp"],
                "mounts": [
                    {"source": "fixture-volume", "target": "/data", "read_only": False}
                ],
                "environment": {"TOKEN": "private-docker-sentinel"},
            },
        }
        self.containers = {}
        self.calls = []
        self.requests = []
        self.addCleanup(patch.stopall)
        patch.object(common, "command", side_effect=self.command).start()
        patch.object(control, "command", side_effect=self.command).start()
        patch.object(create, "request_create", side_effect=self.request_create).start()

    def command(self, argv):
        self.calls.append(argv)
        self.assertEqual(argv[1], common.SOCKET)
        self.assertNotIn("private-docker-sentinel", repr(argv))
        if argv[2] == "image":
            return SimpleNamespace(returncode=0, stdout="sha256:" + "a" * 64)
        if argv[3] == "ls":
            name = next(
                arg[len("name=^/") : -1] for arg in argv if arg.startswith("name=^/")
            )
            return SimpleNamespace(
                returncode=0, stdout=name if name in self.containers else ""
            )
        if argv[3] == "inspect":
            data = self.containers[argv[-1]]
            return SimpleNamespace(
                returncode=0,
                stdout="\n".join(
                    [
                        "true",
                        "process",
                        str(data.get("owner", 17)),
                        data["spec"],
                        data["state"],
                    ]
                ),
            )
        if argv[3] == "rename":
            self.containers[argv[-1]] = self.containers.pop(argv[-2])
        elif argv[3] in ("start", "stop", "restart"):
            self.containers[argv[-1]]["state"] = (
                "exited" if argv[3] == "stop" else "running"
            )
        elif argv[3] == "rm":
            del self.containers[argv[-1]]
        else:
            self.fail("Unexpected Docker action")
        return SimpleNamespace(returncode=0, stdout="")

    def request_create(self, name, body):
        self.assertNotIn("ORBIT_PROCESS_MATERIAL", os.environ)
        self.requests.append((name, body))
        self.containers[name] = {
            "spec": body["Labels"]["orbit.process.spec"],
            "state": "created",
        }

    def invoke(self, action, value=None, **extra):
        with patch.dict(
            os.environ,
            {
                "ORBIT_PROCESS_INSTALLATION": json.dumps(self.admitted),
                "ORBIT_PROCESS_MATERIAL": json.dumps(
                    {"17": {"installation": self.private}}
                ),
                **extra,
            },
        ):
            return action(value or self.control)

    def test_create_uses_private_body_null_defaults_labels_and_never_starts(self):
        self.invoke(create.create)
        name, body = self.requests[0]
        self.assertEqual(name, self.control["name"])
        self.assertNotIn("WorkingDir", body)
        self.assertEqual(body["Labels"]["orbit.process.id"], "17")
        self.assertEqual(body["HostConfig"]["Mounts"][0]["Type"], "volume")
        self.assertEqual(
            body["HostConfig"]["PortBindings"]["53/udp"],
            [{"HostIp": "::1", "HostPort": "5353"}],
        )
        self.assertEqual(self.containers[name]["state"], "created")
        self.assertFalse(any(call[3] == "start" for call in self.calls))
        self.invoke(create.create)
        self.assertEqual(len(self.requests), 1)

    def test_foreign_owner_and_changed_spec_refuse_without_create(self):
        self.containers[self.control["name"]] = {
            "spec": "a" * 64,
            "state": "running",
            "owner": 18,
        }
        with self.assertRaises(common.Refused):
            self.invoke(create.create)
        self.containers[self.control["name"]]["owner"] = 17
        self.containers[self.control["name"]]["spec"] = "b" * 64
        with self.assertRaises(common.Refused):
            self.invoke(create.create)
        self.assertEqual(self.requests, [])
        self.assertEqual(self.containers[self.control["name"]]["state"], "running")

    def test_replacement_and_restoration_are_explicit_individual_actions(self):
        name = self.control["name"]
        self.containers[name] = {"spec": "b" * 64, "state": "running"}
        old = {**self.control, "expected_spec": "b" * 64}
        candidate = {**self.control, "slot": "candidate", "expected_spec": "a" * 64}
        self.invoke(create.create, candidate)
        self.assertEqual(self.containers[name]["state"], "running")
        self.invoke(
            rename.rename, old, ORBIT_PROCESS_DESTINATION_SLOT="rollback-running"
        )
        rollback = {**old, "slot": "rollback-running"}
        self.assertEqual(
            self.containers[name + "-rollback-running"]["state"], "running"
        )
        self.invoke(control.control, rollback, ORBIT_PROCESS_OPERATION="stop")
        self.invoke(
            rename.rename, candidate, ORBIT_PROCESS_DESTINATION_SLOT="canonical"
        )
        self.invoke(
            control.control,
            {**self.control, "expected_spec": "a" * 64},
            ORBIT_PROCESS_OPERATION="start",
        )
        # Parent recovery moves the failed replacement aside, restores the old name,
        # then starts it; no single component hides those effects.
        self.invoke(
            rename.rename,
            {**self.control, "expected_spec": "a" * 64},
            ORBIT_PROCESS_DESTINATION_SLOT="failed",
        )
        self.invoke(rename.rename, rollback, ORBIT_PROCESS_DESTINATION_SLOT="canonical")
        self.invoke(control.control, old, ORBIT_PROCESS_OPERATION="start")
        self.assertEqual(self.containers[name], {"spec": "b" * 64, "state": "running"})
        self.assertIn(name + "-failed", self.containers)
        mutations = [call[3] for call in self.calls if call[3] not in ("ls", "inspect")]
        self.assertEqual(
            mutations,
            ["rename", "stop", "rename", "start", "rename", "rename", "start"],
        )

    def test_rename_replay_proves_destination_spec_and_never_repeats_mutation(self):
        self.containers[self.control["name"]] = {"spec": "a" * 64, "state": "created"}
        value = {**self.control, "expected_spec": "a" * 64}
        self.invoke(rename.rename, value, ORBIT_PROCESS_DESTINATION_SLOT="candidate")
        self.invoke(rename.rename, value, ORBIT_PROCESS_DESTINATION_SLOT="candidate")
        self.assertEqual(sum(call[3] == "rename" for call in self.calls), 1)
        self.containers[self.control["name"] + "-candidate"]["spec"] = "b" * 64
        with self.assertRaises(common.Refused):
            self.invoke(
                rename.rename, value, ORBIT_PROCESS_DESTINATION_SLOT="candidate"
            )

    def test_remove_refuses_running_then_removes_only_selected_slot_and_its_anonymous_volumes(
        self,
    ):
        self.containers[self.control["name"] + "-candidate"] = {
            "spec": "a" * 64,
            "state": "running",
        }
        value = {**self.control, "slot": "candidate"}
        with self.assertRaises(common.Refused):
            self.invoke(remove.remove, value)
        self.invoke(control.control, value, ORBIT_PROCESS_OPERATION="stop")
        self.invoke(remove.remove, value)
        self.assertEqual(
            self.calls[-1],
            [
                common.DOCKER,
                common.SOCKET,
                "container",
                "rm",
                "--volumes",
                self.control["name"] + "-candidate",
            ],
        )
        self.invoke(remove.remove, value)

    def test_invalid_ports_material_spec_and_slot_refuse_before_create(self):
        self.private["spec"] = "b" * 64
        with self.assertRaises(common.Refused):
            self.invoke(create.create)
        self.private["spec"] = "a" * 64
        self.private["definition"]["ports"] = ["127.0.0.999:8080:80"]
        with self.assertRaises(common.Refused):
            self.invoke(create.create)
        with self.assertRaises(common.Refused):
            self.invoke(create.create, {**self.control, "slot": "rollback-running"})
        self.assertEqual(self.requests, [])
        self.assertEqual(self.calls, [])

    def test_engine_refusal_and_transport_uncertainty_are_not_secret_diagnostics(self):
        response = SimpleNamespace(
            status=400, read=lambda size: b'{"message":"private-docker-sentinel"}'
        )
        connection = SimpleNamespace(
            request=lambda *args, **kwargs: None,
            getresponse=lambda: response,
            close=lambda: None,
        )
        with patch.object(engine, "EngineConnection", return_value=connection):
            with self.assertRaises(common.Refused) as failure:
                engine.request_create(self.control["name"], {})
        self.assertEqual(failure.exception.code, "process.create_failed")
        self.assertNotIn("private-docker-sentinel", str(failure.exception))


if __name__ == "__main__":
    unittest.main()
