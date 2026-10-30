"""Run the pinned native Swamp DAG and resume it in a new process after a lost reply."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]
SWAMP = os.environ.get("SWAMP_BIN", shutil.which("swamp") or "swamp")


class TaskVmNativeTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="task-vm-native-")
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / ".swamp.yaml", self.repo)
        for name in ("automatic-deploy", "operation-runner"):
            shutil.copytree(ROOT / "extensions/behaviors" / name, self.repo / "extensions/behaviors" / name)
        shutil.copytree(ROOT / "extensions/behaviors/operations/task-vms", self.repo / "extensions/behaviors/operations/task-vms")
        fixture = self.repo / "extensions/behaviors/_test/executor.ts"
        fixture.parent.mkdir()
        shutil.copy(ROOT / "extensions/behaviors/operations/task-vms/native-fixture.ts", fixture)
        for name, factory, relative in (("operation-runner", "operationDiscovery", "../_test/executor.ts"), ("operations/task-vms", "taskVms", "../../_test/executor.ts")):
            path = self.repo / "extensions/behaviors" / name / "mod.ts"
            source = path.read_text().replace(f"...{factory}()", f"...{factory}(() => fixtureHttp)").replace("behavior = taskVms()", "behavior = taskVms(() => fixtureHttp)")
            path.write_text(f'import {{ fixtureHttp }} from "{relative}";\n' + source)
        (self.repo / "workflows").mkdir()
        for path in ROOT.glob("workflows/workflow-operation-*.yaml"):
            # Local receipt recovery uses fresh processes; worker placement has its own harness.
            source = "\n".join(line for line in path.read_text().splitlines()
                               if not line.startswith(("labels:", "queueTimeout:"))) + "\n"
            (self.repo / "workflows" / path.name).write_text(source)
        self.state = {"operation": {"id": 19, "kind": "task_vm.incus_start", "node_id": 3,
                     "status": "queued", "cancel_requested": False, "step_results": {}, "steps": [],
                     "input": {"task_vm_id": 5, "vm": {"project":"tasks", "name":"tvm-5", "cidr":"10.1.0.1/24"}, "operation":{"kind":"start"}}},
                      "runs": 0, "completions": [], "receipt": None, "loseRunReply": False}
        self.save()

    def save(self):
        (self.repo / "native-executor.json").write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / "native-executor.json").read_text())

    def swamp(self, *args, success=True):
        if args[:2] == ("workflow", "resume"):
            args = (*args[:3], "--run", self.last_run_id)
        result = subprocess.run([SWAMP, *args, "--json"], cwd=self.repo, text=True,
                                capture_output=True, timeout=45,
                                env={**os.environ, "SWAMP_NO_UPDATE_CHECK": "1", "SWAMP_NO_TELEMETRY": "1"})
        if args[:2] == ("workflow", "run"):
            value = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
            self.last_run_id = value.get("id")
        if success:
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        else:
            self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        return result

    def test_runner_dispatches_the_vm_family_with_proven_steps(self):
        for workflow in ("operation-task-vms",):
            self.swamp("workflow", "validate", workflow)
        self.swamp("workflow", "run", "operation-task-vms")
        state = self.read()
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(state["operation"]["steps"], [{"name":"incus-start", "status":"succeeded"}])
        self.assertEqual(state["runs"], 1)

    def test_resume_after_lost_run_reply_reuses_the_node_receipt(self):
        self.state["loseRunReply"] = True
        self.save()
        self.swamp("workflow", "run", "operation-task-vms", success=False)
        self.assertEqual(self.read()["runs"], 1)
        self.assertEqual(self.read()["completions"], [])
        self.swamp("workflow", "resume", "operation-task-vms")
        state = self.read()
        self.assertEqual(state["runs"], 1)
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(len(state["completions"]), 1)


if __name__ == "__main__":
    unittest.main()
