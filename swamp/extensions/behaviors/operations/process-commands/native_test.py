"""Run shipped Process components through real native command/shell steps in a scratch host."""

import importlib.util
import json
import os
import shutil
import uuid
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
spec = importlib.util.spec_from_file_location(
    "native_harness", ROOT / "tests/native_harness.py"
)
assert spec is not None and spec.loader is not None
harness = importlib.util.module_from_spec(spec)
spec.loader.exec_module(harness)


class NativeProcessFixture(harness.NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/process-commands")
        contracts = self.repo / "extensions/behaviors/operations/processes-schedules"
        contracts.mkdir()
        for name in [
            "process-input.ts",
            "installation-input.ts",
            "required-mount-paths.ts",
        ]:
            shutil.copy(
                ROOT / "extensions/behaviors/operations/processes-schedules" / name,
                contracts / name,
            )
        units = self.repo / "host-units"
        units.mkdir()
        self.unit = units / "orbit-process-17-worker.service"
        self.unit.write_text("[Unit]\nX-Orbit-Process-ID=17\n")
        self.unit.chmod(0o644)
        self.state = self.repo / "state.json"
        self.state.write_text(json.dumps({"active": True, "calls": []}))
        manager = self.repo / "systemctl-fixture"
        manager.write_text(
            "#!/usr/bin/python3\nimport json,sys\nfrom pathlib import Path\np=Path("
            + repr(str(self.state))
            + ')\ns=json.loads(p.read_text())\ns["calls"].append(sys.argv[1:])\nif sys.argv[1]=="stop":s["active"]=False\np.write_text(json.dumps(s))\nif sys.argv[1]=="is-active":\n print("active" if s["active"] else "inactive")\n sys.exit(0 if s["active"] else 3)\n'
        )
        manager.chmod(0o755)
        common = (
            self.repo
            / "extensions/behaviors/operations/process-commands/scripts/process_common.py"
        )
        source = (
            common.read_text()
            .replace("ROOT_UID = 0", "ROOT_UID = " + str(os.getuid()))
            .replace("ROOT_GID = 0", "ROOT_GID = " + str(os.getgid()))
        )
        source = source.replace(
            'Path("/etc/systemd/system")', "Path(" + repr(str(units)) + ")"
        ).replace('"/usr/bin/systemctl"', repr(str(manager)))
        common.write_text(source)
        control = {
            "process_id": 17,
            "runtime": "systemd",
            "name": self.unit.name,
            "on_demand": False,
            "vite": None,
        }
        self.inputs = {"control": control}
        self.workflow = {
            "id": str(uuid.uuid4()),
            "name": "process-command-fixture",
            "inputs": {
                "type": "object",
                "properties": {"control": {"type": "object"}},
                "required": ["control"],
            },
            "jobs": [
                {
                    "name": "runtime",
                    "steps": [
                        {
                            "name": "assets",
                            "writes": True,
                            "task": {
                                "type": "behavior",
                                "behaviorType": "@nckrtl/process-command-assets",
                                "behaviorName": "process-command-assets",
                                "methodName": "read",
                                "inputs": {"control": "${{ inputs.control }}"},
                            },
                        },
                        {
                            "name": "stop",
                            "dependsOn": [
                                {"step": "assets", "condition": {"type": "succeeded"}}
                            ],
                            "task": {
                                "type": "behavior",
                                "behaviorType": "command/shell",
                                "behaviorName": "process-command-shell",
                                "methodName": "execute",
                                "inputs": {
                                    "run": '/usr/bin/python3 "${{ steps.assets.outputs.control_script }}"',
                                    "env": {
                                        "ORBIT_PROCESS_CONTROL": "${{ steps.assets.outputs.control_json }}",
                                        "ORBIT_PROCESS_OPERATION": "stop",
                                    },
                                },
                            },
                        },
                        {
                            "name": "verify",
                            "dependsOn": [
                                {"step": "stop", "condition": {"type": "succeeded"}}
                            ],
                            "task": {
                                "type": "behavior",
                                "behaviorType": "command/shell",
                                "behaviorName": "process-command-shell",
                                "methodName": "execute",
                                "inputs": {
                                    "run": '/usr/bin/python3 "${{ steps.assets.outputs.verify_script }}"',
                                    "env": {
                                        "ORBIT_PROCESS_CONTROL": "${{ steps.assets.outputs.control_json }}",
                                        "ORBIT_PROCESS_DESIRED": "stopped",
                                    },
                                },
                            },
                        },
                    ],
                }
            ],
        }

    def run_components(self, success=True):
        (self.repo / "workflows/workflow-process-command-fixture.yaml").write_text(
            yaml.safe_dump(self.workflow)
        )
        self.cli("workflow", "validate", "process-command-fixture")
        reply = self.cli(
            "workflow",
            "run",
            "process-command-fixture",
            "--stdin",
            input=json.dumps(self.inputs),
            success=success,
        )
        decoder = json.JSONDecoder()
        for index, char in enumerate(reply.stdout):
            if char == "{":
                try:
                    return decoder.raw_decode(reply.stdout[index:])[0]
                except ValueError:
                    pass
        self.fail("Native run result missing")


class NativeProcessComponents(NativeProcessFixture):
    def test_delivered_control_stops_then_verifies_without_boot_policy_mutation(self):
        run = self.run_components()
        self.assertEqual(run["status"], "succeeded")
        state = json.loads(self.state.read_text())
        self.assertFalse(state["active"])
        self.assertIn(["stop", self.unit.name], state["calls"])
        self.assertFalse(
            any(call[0] in ("enable", "disable") for call in state["calls"])
        )
        scripts = self.repo / "extensions/behaviors/operations/process-commands/scripts"
        self.assertTrue((scripts / "process_common.py").exists())

    def test_foreign_unit_blocks_control_and_dependent_verification(self):
        self.unit.write_text("X-Orbit-Process-ID=18\n")
        run = self.run_components(success=False)
        self.assertEqual(run["status"], "failed")
        self.assertEqual(json.loads(self.state.read_text())["calls"], [])
