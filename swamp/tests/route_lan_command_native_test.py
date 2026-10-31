"""Official native script delivery and exact UFW removal in a disposable fixture."""

import json
import os
import sys
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tests.native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[1]
FAMILY = "operations/routes/lan-command"


class RouteLanCommandNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors(FAMILY)
        self.state = self.repo / "ufw-state.json"
        self.audit = self.repo / "ufw-audit.jsonl"
        self.fake = self.repo / "ufw"
        self.fake.write_text("""#!/usr/bin/env python3
import json, os, sys
from pathlib import Path
state_path = Path(os.environ["FIXTURE_UFW_STATE"])
state = json.loads(state_path.read_text())
args = sys.argv[1:]
with open(os.environ["FIXTURE_UFW_AUDIT"], "a") as log:
    log.write(json.dumps(args) + "\\n")
if args == ["status", "numbered"]:
    print("Status: " + state["backend"])
    for index, row in enumerate(state["rows"], 1):
        print(row.replace("{number}", str(index)))
elif len(args) == 3 and args[:2] == ["--force", "delete"]:
    if not state.get("ignore_delete", False):
        del state["rows"][int(args[2]) - 1]
        state_path.write_text(json.dumps(state))
else:
    raise SystemExit(9)
""")
        self.fake.chmod(0o700)
        script = (
            self.repo / "extensions/behaviors" / FAMILY / "scripts/remove_route_lan.py"
        )
        source = script.read_text().replace('"/usr/sbin/ufw"', repr(str(self.fake)))
        source = source.replace(
            '"/run/lock/orbit-firewall.lock"', repr(str(self.repo / "ufw.lock"))
        )
        source = source.replace("LOCK_UID = 0", f"LOCK_UID = {os.getuid()}")
        script.write_text(source)
        self.workflow = {
            "id": "e4ded951-01de-4793-9ebd-f1cbbebd752b",
            "name": "fixture-route-lan-command",
            "vaults": [],
            "inputs": {
                "type": "object",
                "properties": {"route_id": {"type": "integer", "minimum": 1}},
                "required": ["route_id"],
            },
            "jobs": [
                {
                    "name": "withdrawal",
                    "steps": [
                        {
                            "name": "assets",
                            "task": {
                                "type": "behavior",
                                "behaviorType": "@nckrtl/route-lan-commands",
                                "behaviorName": "fixture-route-lan-assets",
                                "methodName": "assets",
                                "inputs": {"route_id": "${{ inputs.route_id }}"},
                            },
                        },
                        {
                            "name": "remove-lan",
                            "dependsOn": [
                                {"step": "assets", "condition": {"type": "succeeded"}}
                            ],
                            "task": {
                                "type": "behavior",
                                "behaviorType": "command/shell",
                                "behaviorName": "fixture-route-lan-remove",
                                "methodName": "execute",
                                "inputs": {
                                    "run": 'set -eu\n/usr/bin/python3 "${{ steps.assets.outputs.remove_script }}"',
                                    "env": {
                                        "ORBIT_ROUTE_LAN_INPUT": "${{ steps.assets.outputs.input_json }}",
                                        "FIXTURE_UFW_STATE": str(self.state),
                                        "FIXTURE_UFW_AUDIT": str(self.audit),
                                    },
                                },
                            },
                        },
                    ],
                }
            ],
        }
        (self.repo / "workflows/workflow-fixture-route-lan-command.yaml").write_text(
            yaml.safe_dump(self.workflow, sort_keys=False)
        )

    def run_lan(self, rows, backend="active", success=True, ignore_delete=False):
        self.state.write_text(
            json.dumps(
                {"rows": rows, "backend": backend, "ignore_delete": ignore_delete}
            )
        )
        result = self.cli(
            "workflow",
            "run",
            "fixture-route-lan-command",
            "--input",
            json.dumps({"route_id": 47}),
            success=success,
        )
        return (
            result,
            json.loads(self.state.read_text()),
            [json.loads(line) for line in self.audit.read_text().splitlines()],
        )

    def test_exact_comments_remove_descending_and_preserve_survivors(self):
        _, state, calls = self.run_lan(
            [
                "[{number}] 10.44.0.9 443/tcp ALLOW IN 10.44.0.1 # orbit:route-47-lan",
                "[{number}] 10.44.0.9 443/tcp ALLOW IN 10.44.0.2 # orbit:route-470-lan",
                "[{number}] 10.44.0.9 443/tcp ALLOW IN 10.44.0.3 # orbit:route-47-lan:foreign",
                "[{number}] 10.44.0.9 443/tcp ALLOW IN 10.44.0.4 # orbit:route-47-lan",
            ]
        )
        self.assertEqual(
            [row.rsplit("# ", 1)[1] for row in state["rows"]],
            ["orbit:route-470-lan", "orbit:route-47-lan:foreign"],
        )
        self.assertEqual(
            [call for call in calls if call[:2] == ["--force", "delete"]],
            [["--force", "delete", "4"], ["--force", "delete", "1"]],
        )

    def test_absent_or_inactive_route_has_no_mutation(self):
        _, state, calls = self.run_lan([], backend="inactive")
        self.assertEqual(state["backend"], "inactive")
        self.assertTrue(all(call == ["status", "numbered"] for call in calls))

    def test_unnumbered_owned_comment_refuses_before_delete(self):
        _, state, calls = self.run_lan(
            ["malformed # orbit:route-47-lan"], success=False
        )
        self.assertEqual(state["rows"], ["malformed # orbit:route-47-lan"])
        self.assertEqual(calls, [["status", "numbered"]])

    def test_failed_absence_verification_is_a_native_failure(self):
        _, state, _ = self.run_lan(
            ["[{number}] 10.44.0.9 443/tcp ALLOW IN 10.44.0.1 # orbit:route-47-lan"],
            success=False,
            ignore_delete=True,
        )
        self.assertEqual(len(state["rows"]), 1)
