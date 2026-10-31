"""Disposable real Docker effects through the same shipped scripts; no fleet access."""

import json
import os
import subprocess
import unittest
import uuid
from pathlib import Path

SCRIPTS = Path(__file__).parent / "scripts"


class DockerEffect(unittest.TestCase):
    def test_private_create_control_replacement_restore_and_retire(self):
        process_id = 900000000 + uuid.uuid4().int % 100000000
        name = f"orbit-process-{process_id}-native-proof"
        secret = "docker-effect-secret-" + uuid.uuid4().hex
        control = {
            "process_id": process_id,
            "runtime": "docker",
            "name": name,
            "on_demand": False,
        }
        installation = {
            **control,
            "desired": "running",
            "retired": None,
            "spec": "a" * 64,
        }
        material = {
            "runtime": "docker",
            "spec": "a" * 64,
            "definition": {
                "image": "python:3.13-slim",
                "command": ["sleep", "300"],
                "restart": "no",
                "working_directory": None,
                "ports": [],
                "mounts": [],
                "environment": {"TOKEN": secret},
            },
        }

        def run(script, value=None, **extra):
            env = dict(
                os.environ,
                ORBIT_PROCESS_CONTROL=json.dumps(value or control),
                ORBIT_PROCESS_INSTALLATION=json.dumps(installation),
                ORBIT_PROCESS_MATERIAL=json.dumps(material),
                **extra,
            )
            reply = subprocess.run(
                ["/usr/bin/python3", str(SCRIPTS / script)],
                env=env,
                text=True,
                capture_output=True,
                timeout=60,
            )
            self.assertNotIn(secret, reply.stdout + reply.stderr)
            self.assertEqual(reply.returncode, 0, reply.stdout + reply.stderr)
            return json.loads(reply.stdout)

        try:
            run("pull_image.py")
            run("create_container.py")
            run("create_container.py")
            self.assertEqual(run("inspect.py")["state"], "created")
            run("control.py", ORBIT_PROCESS_OPERATION="start")
            self.assertEqual(
                run("verify.py", ORBIT_PROCESS_DESIRED="running"),
                {"ready": True, "state": "running"},
            )
            # Stage a replacement while the original stays active, then swap as
            # separate parent-visible actions and restore the exact old spec.
            installation["spec"] = material["spec"] = "b" * 64
            candidate = {**control, "slot": "candidate", "expected_spec": "b" * 64}
            run("create_container.py", candidate)
            old = {**control, "expected_spec": "a" * 64}
            run(
                "rename_container.py",
                old,
                ORBIT_PROCESS_DESTINATION_SLOT="rollback-running",
            )
            rollback = {**old, "slot": "rollback-running"}
            run("control.py", rollback, ORBIT_PROCESS_OPERATION="stop")
            run(
                "rename_container.py",
                candidate,
                ORBIT_PROCESS_DESTINATION_SLOT="canonical",
            )
            run("control.py", ORBIT_PROCESS_OPERATION="start")
            run("verify.py", ORBIT_PROCESS_DESIRED="running")
            run(
                "rename_container.py",
                {**control, "expected_spec": "b" * 64},
                ORBIT_PROCESS_DESTINATION_SLOT="failed",
            )
            run(
                "rename_container.py",
                rollback,
                ORBIT_PROCESS_DESTINATION_SLOT="canonical",
            )
            run("control.py", old, ORBIT_PROCESS_OPERATION="start")
            self.assertEqual(run("inspect.py", old)["spec"], "a" * 64)
            for value in [
                old,
                {**control, "slot": "failed", "expected_spec": "b" * 64},
            ]:
                run("control.py", value, ORBIT_PROCESS_OPERATION="stop")
                run("remove_container.py", value)
                run("remove_container.py", value)
            self.assertFalse(run("inspect.py")["present"])
        finally:
            # Only this test's random exact names, even if an assertion failed.
            subprocess.run(
                [
                    "/usr/bin/docker",
                    "--host=unix:///var/run/docker.sock",
                    "container",
                    "rm",
                    "--force",
                    name,
                    name + "-candidate",
                    name + "-rollback-running",
                    name + "-failed",
                ],
                capture_output=True,
                timeout=30,
                check=False,
            )
