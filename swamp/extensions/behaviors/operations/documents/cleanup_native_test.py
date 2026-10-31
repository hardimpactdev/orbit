"""Real native command gate with scratch identity/path binding, without live placement."""

import json
import sys
import unittest
from pathlib import Path

import yaml
from cleanup_test import sandbox_source

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


class CleanupNative(NativeTestCase):
    def test_command_invalidates_gate_preserves_lock_and_surfaces_failure(self):
        self.copy_behaviors("operations/documents")
        family = self.repo / "extensions/behaviors/operations/documents"
        for path in family.glob("*.ts"):
            if path.name != "cleanup_input.ts":
                path.unlink()
        script = family / "scripts/prepare_cleanup.py"
        scratch = self.repo / "scratch"
        script.write_text(sandbox_source(scratch))
        workflow = yaml.safe_load(
            (ROOT / "workflows/workflow-documents-prepare-cleanup.yaml").read_text()
        )
        # This fixture exercises native shell/data flow locally. Production targets
        # are retained in the shipped workflow; sudo is omitted only in this copy.
        for step in workflow["jobs"][0]["steps"]:
            step.pop("target")
            step.pop("labels")
        workflow["jobs"][0]["steps"][1]["task"]["inputs"]["run"] = (
            'set -eu\n/usr/bin/python3 -I "$ORBIT_DOCUMENTS_CLEANUP_SCRIPT"\n'
        )
        (self.repo / "workflows/workflow-documents-prepare-cleanup.yaml").write_text(
            yaml.safe_dump(workflow)
        )
        self.cli("workflow", "validate", "documents-prepare-cleanup")
        public = {
            "operation_id": 702,
            "node_id": 14,
            "kind": "gateway.release",
            "intent": {"target_node_id": 17},
            "execution_secrets": {},
        }
        directory = scratch / "project-documents"
        previous = None
        for _ in range(2):
            run = reply(
                self.cli(
                    "workflow",
                    "run",
                    "documents-prepare-cleanup",
                    "--input",
                    json.dumps(public),
                ).stdout
            )
            self.assertEqual(run["status"], "succeeded")
            history = reply(self.cli("workflow", "history", "get", run["id"]).stdout)
            self.assertEqual(history["inputs"], public)
            result = json.loads(history["jobs"][0]["steps"][1]["outputs"]["stdout"])
            self.assertTrue(result["paused"])
            self.assertNotEqual(result["generation"], previous)
            previous = result["generation"]
            lock = (directory / "execution.lock").stat()
            state = json.loads((directory / "generation.json").read_text())
            self.assertEqual(state["lock_inode"], lock.st_ino)
            self.assertIsNone(state["report_id"])
            self.assertFalse((directory / "permit.json").exists())
            (directory / "permit.json").write_text(json.dumps(state))
            (directory / "permit.json").chmod(0o600)
        (directory / "execution.lock").chmod(0o644)
        failed = reply(
            self.cli(
                "workflow",
                "run",
                "documents-prepare-cleanup",
                "--input",
                json.dumps(public),
                success=False,
            ).stdout
        )
        self.assertEqual(failed["status"], "failed")
        self.assertTrue((directory / "permit.json").exists())


if __name__ == "__main__":
    unittest.main()
