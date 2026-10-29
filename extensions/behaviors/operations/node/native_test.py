"""Exercise the pinned native runner, node workflow and fresh-process recovery."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]
SWAMP = os.environ.get("SWAMP_BIN", shutil.which("swamp") or "swamp")


class NodeWorkflowTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="node-workflow-")
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / ".swamp.yaml", self.repo)
        for name in ("automatic-deploy", "operation-runner", "operations/node"):
            shutil.copytree(ROOT / "extensions/behaviors" / name,
                            self.repo / "extensions/behaviors" / name)
        for name, factory, relative in (
            ("operation-runner", "operationRunner", "../operations/node/native_fixture.ts"),
            ("operations/node", "node", "./native_fixture.ts"),
        ):
            path = self.repo / "extensions/behaviors" / name / "mod.ts"
            source = path.read_text().replace(f"{factory}()", f"{factory}(() => fixtureHttp)")
            path.write_text(f'import {{ fixtureHttp }} from "{relative}";\n' + source)
        (self.repo / "workflows").mkdir()
        for name in ("operation-runner", "operation-dispatch", "operation-node"):
            source = (ROOT / "workflows" / f"workflow-{name}.yaml").read_text()
            # Process recovery is local; the shipped workflow keeps worker placement.
            source = "\n".join(line for line in source.splitlines()
                               if not line.startswith(("labels:", "queueTimeout:"))) + "\n"
            (self.repo / "workflows" / f"workflow-{name}.yaml").write_text(source)
        self.state = {"operation": {
            "id": 7, "kind": "node.settings", "node_id": 2,
            "status": "queued", "cancel_requested": False, "step_results": {}, "steps": [],
            "input": {"node_id": 2, "root": "/srv/apps", "user": "orbit", "group": "orbit", "create_missing": True},
        }, "runs": 0, "keys": [], "completions": [], "receipt": None,
            "loseRunReply": False, "loseCompletionReply": False, "calls": [], "loseBeforeCommit": False}
        self.save()

    def save(self):
        (self.repo / "native-node.json").write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / "native-node.json").read_text())

    def swamp(self, *args, success=True):
        result = subprocess.run([SWAMP, *args, "--json"], cwd=self.repo, text=True,
                                capture_output=True, timeout=45,
                                env={**os.environ, "SWAMP_NO_UPDATE_CHECK": "1", "SWAMP_NO_TELEMETRY": "1"})
        if success:
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        else:
            self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        return result

    def test_native_dispatch_runs_the_settings_check(self):
        self.swamp("doctor", "extensions")
        for name in ("operation-runner", "operation-dispatch", "operation-node"):
            self.swamp("workflow", "validate", name)
        self.swamp("workflow", "run", "operation-runner")
        state = self.read()
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(state["runs"], 1)
        self.assertEqual(state["operation"]["steps"], [{"name": "node-storage-root", "status": "succeeded"}])

    def test_fresh_process_recovers_a_lost_node_reply(self):
        self.state["loseRunReply"] = True
        self.save()
        self.swamp("workflow", "run", "operation-runner", success=False)
        self.assertEqual(self.read()["runs"], 1)
        self.assertEqual(self.read()["completions"], [])
        self.swamp("workflow", "resume", "operation-node", "--from", "execute")
        state = self.read()
        self.assertEqual(state["runs"], 1)
        self.assertEqual(state["calls"].count("/api/v1/operation-executor/7/run"), 1)
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(len(state["completions"]), 1)

    def test_fresh_process_replays_lost_completion_without_node_work(self):
        self.state["loseCompletionReply"] = True
        self.save()
        self.swamp("workflow", "run", "operation-runner", success=False)
        self.swamp("workflow", "resume", "operation-node", "--from", "execute")
        state = self.read()
        self.assertEqual(state["runs"], 1)
        self.assertEqual(state["calls"].count("/api/v1/operation-executor/7/run"), 1)
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(len(state["completions"]), 1)

    def test_fresh_process_replays_a_completion_that_did_not_commit(self):
        self.state["loseBeforeCommit"] = True
        self.save()
        self.swamp("workflow", "run", "operation-runner", success=False)
        self.assertEqual(self.read()["completions"], [])
        self.swamp("workflow", "resume", "operation-node", "--from", "execute")
        state = self.read()
        self.assertEqual(state["runs"], 1)
        self.assertEqual(len(state["completions"]), 1)
        self.assertEqual(state["operation"]["status"], "succeeded")

if __name__ == "__main__":
    unittest.main()
