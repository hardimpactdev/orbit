"""Pinned native Tool workflow restart proof. Uses a disposable fake Gateway."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]
SWAMP = os.environ.get("SWAMP_BIN", shutil.which("swamp") or "swamp")


class ToolsNativeTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="tools-native-")
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / ".swamp.yaml", self.repo)
        for family in ("automatic-deploy", "operation-runner", "operations/tools"):
            shutil.copytree(ROOT / "extensions/behaviors" / family,
                            self.repo / "extensions/behaviors" / family)
        for family, factory, relative in (("operation-runner", "operationRunner", "../operations/tools/native_fixture.ts"),
                                           ("operations/tools", "tools", "./native_fixture.ts")):
            path = self.repo / "extensions/behaviors" / family / "mod.ts"
            source = path.read_text().replace(f"...{factory}()", f"...{factory}(() => fixtureHttp)")
            path.write_text(f'import {{ fixtureHttp }} from "{relative}";\n' + source)
        (self.repo / "workflows").mkdir()
        for name in ("runner", "dispatch", "tools"):
            shutil.copy(ROOT / f"workflows/workflow-operation-{name}.yaml", self.repo / "workflows")
        self.state = {"operation": {"id": 11, "kind": "tool.install", "node_id": 2,
                      "status": "queued", "cancel_requested": False, "steps": [], "step_results": {},
                      "input": {"node_id": 2, "manager": "vp", "package": "is-number", "platform": "linux",
                                "architecture": "x86_64", "tool_id": None}},
                      "calls": [], "receipts": {}, "mutations": 0, "installed": None, "completions": []}

    def call(self, *args, success=True):
        result = subprocess.run([SWAMP, *args, "--json"], cwd=self.repo,
                                env={**os.environ, "SWAMP_NO_UPDATE_CHECK": "1", "SWAMP_NO_TELEMETRY": "1"},
                                text=True, capture_output=True, timeout=45)
        if success:
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        else:
            self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        return result

    def test_restarts_at_every_uncertain_boundary(self):
        for boundary in ("loseAdmission", "loseRun", "loseCompletion"):
            with self.subTest(boundary=boundary):
                # One fixture and native workflow definition per permanent Operation identity.
                self.state[boundary] = True
                (self.repo / "native-tools.json").write_text(json.dumps(self.state))
                self.call("workflow", "validate", "operation-runner")
                self.call("workflow", "validate", "operation-tools")
                self.call("workflow", "run", "operation-runner", success=False)
                self.call("workflow", "resume", "operation-tools", "--from", "execute")
                state = json.loads((self.repo / "native-tools.json").read_text())
                self.assertEqual(state["operation"]["status"], "succeeded")
                self.assertEqual(state["mutations"], 1)
                self.assertEqual(len(state["completions"]), 1)
                self.assertEqual(state["completions"][0]["result"]["installed_version"], "7.0.0")
                mutation_calls = [c for c in state["calls"] if c["path"].endswith("/run") and c["body"]["key"] == "package-mutate"]
                self.assertTrue(all(c["body"]["name"] == "tool.vp-install" for c in mutation_calls))
                # Create a new native model identity for the next independent failure scenario.
                self.state["operation"]["id"] += 1
                self.state[boundary] = False


if __name__ == "__main__":
    unittest.main()
