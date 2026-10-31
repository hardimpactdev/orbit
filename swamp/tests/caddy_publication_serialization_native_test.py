"""Prove native model serialization for composed Caddy actions on one Node."""

import concurrent.futures
import json
import sys
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tests.native_harness import NativeTestCase


class CaddySerializationNative(NativeTestCase):
    def test_distinct_parent_workflows_serialize_the_same_effect_node(self):
        # Test-only delay makes an overlap observable; production has no sleep.
        script = """import os, pathlib, time
root = pathlib.Path(os.environ["FIXTURE_HOST"])
active = root / "active"
active.mkdir()
try:
    with (root / "audit").open("a") as output:
        output.write("enter\\n")
    time.sleep(0.2)
    with (root / "audit").open("a") as output:
        output.write("leave\\n")
finally:
    active.rmdir()
print('{"ready":true}')
"""
        run = "/usr/bin/python3 - <<'PYTHON'\n" + script + "PYTHON\n"
        for name in ("first", "second"):
            workflow = {
                "id": "d41fa5e4-02c1-4c2d-a2d6-990c04303d8"
                + ("1" if name == "first" else "2"),
                "name": "caddy-" + name,
                "version": 1,
                "jobs": [
                    {
                        "name": "publication",
                        "steps": [
                            {
                                "name": "activate",
                                "task": {
                                    "type": "behavior",
                                    "behaviorType": "command/shell",
                                    "behaviorName": "caddy-node-publication-9",
                                    "methodName": "execute",
                                    "inputs": {
                                        "run": run,
                                        "env": {"FIXTURE_HOST": str(self.repo)},
                                    },
                                },
                            }
                        ],
                    }
                ],
            }
            (self.repo / "workflows" / ("workflow-caddy-" + name + ".yaml")).write_text(
                yaml.safe_dump(workflow)
            )
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            runs = list(
                pool.map(
                    lambda name: self.cli("workflow", "run", "caddy-" + name),
                    ("first", "second"),
                )
            )
        for run_result in runs:
            self.assertEqual(json.loads(run_result.stdout)["status"], "succeeded")
        self.assertEqual(
            (self.repo / "audit").read_text(), "enter\nleave\nenter\nleave\n"
        )
