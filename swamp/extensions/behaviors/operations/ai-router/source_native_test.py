"""Native metadata + built-in shell; all filesystem paths are scratch-bound.

This local fixture omits remote placement and sudo, retaining the shipped Python
and native output path. It makes no live fleet or proxy transport claim.
"""

import ast
import json
import sys
import unittest
from pathlib import Path

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[4]))
from tests.native_harness import ROOT, NativeTestCase


def reply(raw):
    for index, character in enumerate(raw):
        if character == "{":
            try:
                return json.loads(raw[index:])
            except ValueError:
                pass
    raise AssertionError("Native JSON missing")


class SourceNative(NativeTestCase):
    def test_real_command_removes_only_selected_source_and_reports_failure(self):
        self.copy_behaviors("operations/ai-router")
        # Other family files have unrelated dependencies. Load only this contribution.
        family = self.repo / "extensions/behaviors/operations/ai-router"
        for path in family.glob("*.ts"):
            if path.name != "source_input.ts":
                path.unlink()
        scratch = self.repo / "scratch"
        for name in ["ai-router", "proxycli", "unrelated"]:
            path = scratch / name
            path.mkdir(parents=True)
            (path / "collector.py").write_text("source")
        script = family / "scripts/remove_source.py"
        tree = ast.parse(script.read_text())
        matches = [
            node
            for node in tree.body
            if isinstance(node, ast.Assign)
            and len(node.targets) == 1
            and isinstance(node.targets[0], ast.Name)
            and node.targets[0].id == "ROOT"
        ]
        self.assertEqual(len(matches), 1)
        self.assertEqual(ast.literal_eval(matches[0].value.args[0]), "/var/lib/orbit")
        matches[0].value.args[0] = ast.Constant(str(scratch))
        script.write_text(ast.unparse(ast.fix_missing_locations(tree)))
        workflow = yaml.safe_load(
            (ROOT / "workflows/workflow-ai-router-remove-source.yaml").read_text()
        )
        for step in workflow["jobs"][0]["steps"]:
            step.pop("target")
            step.pop("labels")
        workflow["jobs"][0]["steps"][1]["task"]["inputs"]["run"] = (
            'set -eu\n/usr/bin/python3 -I "$ORBIT_AI_SOURCE_SCRIPT"\n'
        )
        (self.repo / "workflows/workflow-ai-router-remove-source.yaml").write_text(
            yaml.safe_dump(workflow)
        )
        self.cli("workflow", "validate", "ai-router-remove-source")
        public = {
            "operation_id": 701,
            "node_id": 14,
            "kind": "ai_router.teardown",
            "intent": {"target_node_id": 17, "retired": False},
            "execution_secrets": {},
        }
        for expected in [True, False]:
            run = reply(
                self.cli(
                    "workflow",
                    "run",
                    "ai-router-remove-source",
                    "--input",
                    json.dumps(public),
                ).stdout
            )
            self.assertEqual(run["status"], "succeeded")
            history = reply(self.cli("workflow", "history", "get", run["id"]).stdout)
            self.assertEqual(history["inputs"], public)
            self.assertEqual(
                json.loads(history["jobs"][0]["steps"][1]["outputs"]["stdout"]),
                {"removed": expected},
            )
        self.assertTrue((scratch / "proxycli/collector.py").exists())
        self.assertTrue((scratch / "unrelated/collector.py").exists())
        (scratch / "ai-router").symlink_to(scratch / "unrelated")
        failed = reply(
            self.cli(
                "workflow",
                "run",
                "ai-router-remove-source",
                "--input",
                json.dumps(public),
                success=False,
            ).stdout
        )
        self.assertEqual(failed["status"], "failed")
        self.assertTrue((scratch / "unrelated/collector.py").exists())


if __name__ == "__main__":
    unittest.main()
