"""Run the production Route LAN workflow with disposable UFW and lock boundaries."""

import json
import os
import sys
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tests.native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[1]



class RouteLanCommandNative(NativeTestCase):
    def setUp(self):
        super().setUp()
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
        self.workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-route-lan-remove.yaml').read_text())
        step = self.workflow['jobs'][0]['steps'][0]
        step.pop('target')
        step.pop('labels')
        command = step['task']['inputs']
        command['run'] = command['run'].replace('sudo --preserve-env=ORBIT_ROUTE_ID,ORBIT_ROUTE_DESTINATION ', '')
        command['run'] = command['run'].replace('"/usr/sbin/ufw"', repr(str(self.fake)))
        command['run'] = command['run'].replace('"/run/lock/orbit-firewall.lock"', repr(str(self.repo / 'ufw.lock')))
        command['env'].update({'FIXTURE_UFW_STATE': str(self.state), 'FIXTURE_UFW_AUDIT': str(self.audit)})
        (self.repo / 'workflows/workflow-operation-route-lan-remove.yaml').write_text(yaml.safe_dump(self.workflow, sort_keys=False))

    def run_lan(self, rows, backend="active", success=True, ignore_delete=False):
        self.state.write_text(
            json.dumps(
                {"rows": rows, "backend": backend, "ignore_delete": ignore_delete}
            )
        )
        result = self.cli(
            "workflow",
            "run",
            "operation-route-lan-remove",
            "--input",
            json.dumps({"operation_id": 1, "node_id": 9, "kind": "route.lan_remove", "intent": {"route_id": 47, "node_id": 9, "destination": "10.44.0.9"}, "execution_secrets": {}}),
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
                "[{number}] 10.44.0.99 443/tcp ALLOW IN 10.44.0.4 # orbit:route-47-lan",
            ]
        )
        self.assertEqual(
            [row.rsplit("# ", 1)[1] for row in state["rows"]],
            ["orbit:route-470-lan", "orbit:route-47-lan:foreign", "orbit:route-47-lan"],
        )
        self.assertEqual(
            [call for call in calls if call[:2] == ["--force", "delete"]],
            [["--force", "delete", "4"], ["--force", "delete", "1"]],
        )

    def test_inactive_backend_refuses_without_claiming_persistent_absence(self):
        rows = ["[{number}] 10.44.0.9 443/tcp ALLOW IN 10.44.0.1 # orbit:route-47-lan"]
        _, state, calls = self.run_lan(rows, backend="inactive", success=False)
        self.assertEqual(state["rows"], rows)
        self.assertEqual(calls, [["status", "numbered"]])

    def test_missing_owned_rule_on_active_backend_has_no_mutation(self):
        _, _, calls = self.run_lan([])
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
