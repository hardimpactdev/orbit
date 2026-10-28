"""Run the pinned native Swamp DAG and resume it in a new process after a lost reply."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SWAMP = os.environ.get("SWAMP_BIN", shutil.which("swamp") or "swamp")


class OperationRunnerNativeTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="operation-runner-native-")
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / ".swamp.yaml", self.repo)
        for name in ("automatic-deploy", "operation-runner", "operations"):
            shutil.copytree(ROOT / "extensions/behaviors" / name, self.repo / "extensions/behaviors" / name)
        fixture = self.repo / "extensions/behaviors/_test/executor.ts"
        fixture.parent.mkdir()
        shutil.copy(ROOT / "tests/fixtures/operation-executor.ts", fixture)
        for name, factory, relative in (("operation-runner", "operationRunner", "../_test/executor.ts"), ("operations/firewall", "firewall", "../../_test/executor.ts")):
            path = self.repo / "extensions/behaviors" / name / "mod.ts"
            source = path.read_text().replace(f"...{factory}()", f"...{factory}(() => fixtureHttp)")
            path.write_text(f'import {{ fixtureHttp }} from "{relative}";\n' + source)
        (self.repo / "workflows").mkdir()
        for path in ROOT.glob("workflows/workflow-operation-*.yaml"):
            # This fixture proves receipts and recovery in fresh local processes.
            # Container dispatch is proved separately in services_workers_native_test.
            source = path.read_text()
            source = "\n".join(line for line in source.splitlines()
                               if not line.startswith(("labels:", "queueTimeout:"))) + "\n"
            (self.repo / "workflows" / path.name).write_text(source)
        self.state = {"operation": {"id": 7, "kind": "firewall.allow", "node_id": 2,
                     "status": "queued", "cancel_requested": False, "step_results": {}, "steps": [],
                     "input": {"node_id": 2, "name": "proof", "action": "allow", "source": "192.0.2.1/32", "protocol": "tcp", "port": "65431"}},
                      "runs": 0, "completions": [], "receipt": None, "loseRunReply": False}
        self.save()

    def save(self):
        (self.repo / "native-executor.json").write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / "native-executor.json").read_text())

    def swamp(self, *args, success=True):
        result = subprocess.run([SWAMP, *args, "--json"], cwd=self.repo, text=True,
                                capture_output=True, timeout=45,
                                env={**os.environ, "SWAMP_NO_UPDATE_CHECK": "1", "SWAMP_NO_TELEMETRY": "1"})
        if success:
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        else:
            self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        return result

    def test_runner_dispatches_allow_then_remove_with_recorded_steps(self):
        self.swamp("workflow", "validate", "operation-runner")
        self.swamp("workflow", "validate", "operation-dispatch")
        self.swamp("workflow", "validate", "operation-firewall")
        self.swamp("workflow", "run", "operation-runner")
        state = self.read()
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(state["operation"]["steps"], [{"name": "ufw-converge", "status": "succeeded"}])
        self.assertEqual(state["runs"], 1)
        self.state["operation"].update(id=8, kind="firewall.remove", status="queued")
        self.save()
        self.swamp("workflow", "run", "operation-runner")
        state = self.read()
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(state["completions"][0]["result"], {"backend": "absent"})
        self.assertEqual(state["operation"]["steps"], [{"name": "ufw-remove", "status": "succeeded"}])

    def test_resume_after_lost_run_reply_reuses_the_node_receipt(self):
        self.state["loseRunReply"] = True
        self.save()
        self.swamp("workflow", "run", "operation-runner", success=False)
        self.assertEqual(self.read()["runs"], 1)
        self.assertEqual(self.read()["completions"], [])
        self.swamp("workflow", "resume", "operation-firewall", "--from", "execute")
        state = self.read()
        self.assertEqual(state["runs"], 1)
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(len(state["completions"]), 1)


if __name__ == "__main__":
    unittest.main()
