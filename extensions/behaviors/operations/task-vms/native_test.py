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
        shutil.copytree(ROOT / "extensions/behaviors/operations/_lib", self.repo / "extensions/behaviors/operations/_lib")
        fixture = self.repo / "extensions/behaviors/_test/executor.ts"
        fixture.parent.mkdir()
        shutil.copy(ROOT / "extensions/behaviors/operations/task-vms/native-fixture.ts", fixture)
        for name, factory, relative in (("operation-runner", "operationDiscovery", "../_test/executor.ts"), ("operations/task-vms", "taskVms", "../../_test/executor.ts")):
            path = self.repo / "extensions/behaviors" / name / "mod.ts"
            source = path.read_text().replace(f"...{factory}()", f"...{factory}(() => fixtureHttp)").replace("behavior = taskVms()", "behavior = taskVms(() => fixtureHttp)")
            path.write_text(f'import {{ fixtureHttp }} from "{relative}";\n' + source)
        direct = self.repo / 'extensions/behaviors/operations/task-vms/lifecycle_intent_direct.ts'
        direct.write_text('import { fixtureHttp } from "../../_test/executor.ts";\n' + direct.read_text().replace(
            '...lifecycleIntent()', '...lifecycleIntent(() => fixtureHttp)'))
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
            self.assertTrue(result.stdout.strip(), result.stderr)
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

    def prepare_direct_acknowledgement(self):
        source = (ROOT / 'workflows/workflow-operation-task-vms.yaml').read_text()
        self.assertIn('target: swamp209-migration-a', source)
        self.assertIn('labels: { node: "209", pool: migration }', source)
        workflow = self.repo / 'workflows/workflow-operation-task-vms.yaml'
        # Local fixture only. Live source always places this pure stage on Node209.
        workflow.write_text('\n'.join(line for line in workflow.read_text().splitlines()
                                     if not line.strip().startswith(('labels:', 'target:'))) + '\n')
        self.state['operation'].update(kind='task_vm.lifecycle_intent', input={'task_vm_id': 5}, result=None)
        self.save()

    def run_direct(self, success=True):
        return self.swamp('workflow', 'run', 'operation-task-vms', '--input',
                          json.dumps({'operation_id': 19, 'kind': 'task_vm.lifecycle_intent', 'node_id': 3}),
                          success=success)

    def test_direct_acknowledgement_skips_discovery_and_performs_no_provider_effect(self):
        self.prepare_direct_acknowledgement()
        self.run_direct()
        state = self.read()
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['operation']['result'], {'ready': True})
        self.assertEqual(state['runs'], 0)
        self.assertEqual(state.get('discoveryCalls', 0), 0)
        self.assertEqual(len(state['directCalls']), 3)

    def test_direct_acknowledgement_resumes_after_a_lost_terminal_reply(self):
        self.prepare_direct_acknowledgement()
        self.state['loseDirectReply'] = True
        self.save()
        self.run_direct(success=False)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')
        self.swamp('workflow', 'resume', 'operation-task-vms')
        state = self.read()
        self.assertEqual(state['runs'], 0)
        self.assertEqual(len(state['directCalls']), 4)

    def test_direct_cancellation_supplies_no_ready_acknowledgement(self):
        self.prepare_direct_acknowledgement()
        self.state['operation']['cancel_requested'] = True
        self.save()
        self.run_direct()
        state = self.read()
        self.assertEqual(state['operation']['status'], 'cancelled')
        self.assertEqual(state['operation']['result'], {})
        self.assertEqual(state['runs'], 0)



if __name__ == "__main__":
    unittest.main()
