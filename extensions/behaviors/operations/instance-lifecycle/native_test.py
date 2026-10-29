"""Prove lifecycle recovery with the pinned Swamp CLI in separate native processes."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]
FAMILY = Path(__file__).resolve().parent
SWAMP = os.environ.get("SWAMP_BIN", shutil.which("swamp") or "swamp")


class LifecycleNativeTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="instance-lifecycle-native-")
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / ".swamp.yaml", self.repo)
        for name in ("automatic-deploy", "operation-runner"):
            shutil.copytree(ROOT / "extensions/behaviors" / name, self.repo / "extensions/behaviors" / name)
        family = self.repo / "extensions/behaviors/operations/instance-lifecycle"
        shutil.copytree(FAMILY, family)
        runner = self.repo / "extensions/behaviors/operation-runner/mod.ts"
        runner.write_text('import { fixtureHttp } from "../operations/instance-lifecycle/fixtures/native.ts";\n' +
                          runner.read_text().replace("...operationRunner()", "...operationRunner(() => fixtureHttp)"))
        module = family / "mod.ts"
        module.write_text('import { fixtureHttp } from "./fixtures/native.ts";\n' +
                          module.read_text().replace("...instanceLifecycle()", "...instanceLifecycle(() => fixtureHttp)"))
        (self.repo / "workflows").mkdir()
        for name in ("operation-runner", "operation-dispatch", "operation-instance-lifecycle"):
            shutil.copy(ROOT / f"workflows/workflow-{name}.yaml", self.repo / "workflows")
        # Local native recovery exercises process persistence, without pretending
        # to prove remote placement. Production keeps migration worker placement.
        workflow = self.repo / "workflows/workflow-operation-instance-lifecycle.yaml"
        workflow.write_text(workflow.read_text().replace("labels: { pool: migration }\n", ""))
        descriptor = lambda key, transport, name, input: dict(key=key, step=key, transport=transport,
                         name=name, input=input, required=True, resumable=True)
        self.state = {"operation": {"id": 100, "kind": "instance.rename", "node_id": 9,
            "input": {"instance_id": 501, "domain": None}, "status": "queued", "cancel_requested": False,
            "result": None, "error_code": None, "steps": [], "step_results": {}, "execution_context": {"descriptors": [
                descriptor("rename-inspect", "instance_lifecycle", "instance.inspect-development-source", {"checkout": "/srv/apps/demo/proof"}),
                descriptor("lifecycle-finish", "gateway", "instance.lifecycle-state", {"action": "finish", "operation_id": 100})]}},
            "receipts": {}, "invocations": {}, "effects": {}, "completions": [], "calls": [],
            "loseRunReply": None, "loseCompletionReply": False, "loseClaimReply": False,
            "childStatus": "queued", "childAdmitted": False, "childAdmissions": 0}
        self.save()

    def save(self):
        (self.repo / "native-lifecycle.json").write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / "native-lifecycle.json").read_text())

    def swamp(self, *args, success=True):
        result = subprocess.run([SWAMP, *args, "--json"], cwd=self.repo, text=True, capture_output=True,
                                timeout=45, env={**os.environ, "SWAMP_NO_UPDATE_CHECK": "1", "SWAMP_NO_TELEMETRY": "1"})
        self.assertEqual(result.returncode == 0, success, result.stdout + result.stderr)
        return result

    def test_validate_and_dispatch(self):
        for name in ("operation-runner", "operation-dispatch", "operation-instance-lifecycle"):
            self.swamp("workflow", "validate", name)
        self.swamp("workflow", "run", "operation-runner")
        state = self.read()
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(state["effects"], {"rename-inspect": 1, "lifecycle-finish": 1})
        self.assertEqual(state["operation"]["steps"], [
            {"name": "rename-inspect", "status": "succeeded"}, {"name": "lifecycle-finish", "status": "succeeded"}])

    def test_resume_admitted_operation_with_the_same_claim(self):
        self.state["loseClaimReply"] = True
        self.save()
        self.swamp("workflow", "run", "operation-runner", success=False)
        claimed = self.read()["operation"]["worker"]
        self.assertEqual(self.read()["effects"], {})
        self.swamp("workflow", "resume", "operation-instance-lifecycle", "--from", "execute")
        self.assertEqual(self.read()["operation"]["worker"], claimed)
        self.assertEqual(len(self.read()["completions"]), 1)

    def test_resume_recovers_the_effect_receipt_without_reinvoking(self):
        self.state["loseRunReply"] = "rename-inspect"
        self.save()
        self.swamp("workflow", "run", "operation-runner", success=False)
        self.assertEqual(self.read()["effects"], {"rename-inspect": 1})
        self.assertEqual(self.read()["completions"], [])
        self.swamp("workflow", "resume", "operation-instance-lifecycle", "--from", "execute")
        state = self.read()
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(state["invocations"]["rename-inspect"], 1)
        self.assertEqual(len(state["completions"]), 1)

    def test_parent_wait_releases_worker_and_a_new_claim_reuses_source_and_child_receipts(self):
        self.state["operation"]["input"].update(domain="preview.test", route_id=30)
        self.state["operation"]["child_context"] = {"children": [{"key": "route-update", "kind": "route.update", "node_id": 9,
            "input": {"route_id": 30, "domain": "preview.test"}}]}
        self.save()
        self.swamp("workflow", "run", "operation-runner")
        state = self.read()
        self.assertEqual(state["operation"]["status"], "waiting")
        self.assertIsNone(state["operation"]["worker"])
        self.assertEqual(state["completions"], [])
        self.assertEqual(state["invocations"], {"rename-inspect": 1})
        self.state = state
        self.state["childStatus"] = "succeeded"
        self.save()
        self.swamp("workflow", "run", "operation-runner")
        state = self.read()
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(state["invocations"], {"rename-inspect": 1, "lifecycle-finish": 1})
        self.assertEqual(state["childAdmissions"], 1)
        self.assertEqual(len(state["completions"]), 1)

    def test_resume_replays_saved_completion_without_new_effects_or_context(self):
        self.state["loseCompletionReply"] = True
        self.save()
        self.swamp("workflow", "run", "operation-runner", success=False)
        previous = self.read()
        self.swamp("workflow", "resume", "operation-instance-lifecycle", "--from", "execute")
        state = self.read()
        self.assertEqual(state["calls"][len(previous["calls"]):], [{"method": "GET", "path": "/api/v1/operations/100"}])
        self.assertEqual(len(state["completions"]), 1)
        self.assertEqual(state["effects"], previous["effects"])


if __name__ == "__main__":
    unittest.main()
