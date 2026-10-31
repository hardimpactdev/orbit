"""Exercise the resume DAG in scratch with the canonical shared native harness."""
import json
from pathlib import Path
import sys
import unittest
import uuid
import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


class ResumeNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        token = "resume-test-" + uuid.uuid4().hex
        self.native_env["ORBIT_RESUME_TEST_TOKEN"] = token
        self.secrets = (token,)
        self.copy_behaviors("automatic-deploy", "operation-runner", "operations/task-vms", "operations/_lib", "operations/node")
        module = self.repo / "extensions/behaviors/operations/task-vms/resume_direct.ts"
        module.write_text('import {fixtureHttp, fixtureAction} from "./resume_native_fixture.ts";\n' +
                          module.read_text().replace("export const behavior = nativeResume();",
                          "export const behavior = nativeResume(() => fixtureHttp, fixtureAction);"))
        failure=self.repo/"extensions/behaviors/operations/failure.ts"
        failure.write_text('import {fixtureHttp} from "./task-vms/resume_native_fixture.ts";\n'+(ROOT/"extensions/behaviors/operations/failure.ts").read_text().replace('export const behavior = nativeFailure();','export const behavior = nativeFailure(() => fixtureHttp);'))
        source = yaml.safe_load((ROOT / "workflows/workflow-operation-task-vm-resume.yaml").read_text())
        self.assertEqual([job["name"] for job in source["jobs"]], ["admission", "provider", "guest", "pi", "projection", "failure"])
        for job in source["jobs"]:
            if job["name"] != "guest":
                self.assertEqual(job.pop("target"), '${{ "orbit-node-" + string(inputs.node_id) }}')
                self.assertEqual(job.pop("labels"), {"node": '${{ string(inputs.node_id) }}'})
            else:
                guest = job
                self.assertEqual(guest.pop("target"), '${{ "orbit-node-" + string(steps.admit.outputs.input.guest_node_id) }}')
                self.assertEqual(guest.pop("labels"), {"node": '${{ string(steps.admit.outputs.input.guest_node_id) }}'})
        (self.repo / "workflows/workflow-operation-task-vm-resume.yaml").write_text(yaml.safe_dump(source, sort_keys=False))
        self.state = {"calls": [], "stages": [], "operation": {"id": 7, "kind": "task_vm.resume", "node_id": 13,
            "status": "queued", "result": None, "cancel_requested": False, "input": {
                "eligible": True, "task_vm_id": 5, "node_id": 13, "guest_node_id": 208, "name": "tvm-5",
                "provider": "incus", "provider_id": None, "agent_sha256": "a" * 64,
                "guest_agent_sha256": "b" * 64, "user": "orbit", "pi_sha256": "c" * 64,
                "pi_url": "http://10.44.0.208:3774", "deadline_at": 9999999999,
                "vm": {"project": "orbit-tasks", "name": "tvm-5", "cidr": "10.251.77.0/24"}}}}
        self.save()

    def save(self):
        (self.repo / "resume-fixture.json").write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / "resume-fixture.json").read_text())

    def run_root(self, success=True):
        self.cli("workflow", "validate", "operation-task-vm-resume")
        result = self.cli("workflow", "run", "operation-task-vm-resume", "--input",
                          json.dumps({"operation_id": 7, "node_id": 13, "kind": "task_vm.resume"}), success=success)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]["id"]

    def test_same_root_orders_host_guest_and_private_pi_then_projects_ready(self):
        self.run_root()
        saved = self.read()
        self.assertEqual(saved["stages"], [{"stage": "provider", "node_id": 13},
                                        {"stage": "guest", "node_id": 208}, {"stage": "pi", "node_id": 13}])
        self.assertEqual(saved["operation"]["status"], "succeeded")
        self.assertEqual(saved["operation"]["result"], {"task_vm_id": 5, "guest_node_id": 208, "ready": True})
        self.assertTrue(all("/direct-operations/7" in call["path"] for call in saved["calls"]))

    def test_guest_refusal_skips_pi_and_never_projects_ready(self):
        self.state["failGuest"] = True
        self.save()
        self.run_root(success=False)
        saved = self.read()
        self.assertEqual([stage["stage"] for stage in saved["stages"]], ["provider", "guest"])
        self.assertEqual(saved["operation"]["status"], "failed")
        self.assertEqual(saved["operation"]["result"], {"error_code": "task_vm.resume_failed"})

    def test_cancelled_admission_never_runs_a_provider_or_guest_action(self):
        self.state["operation"]["cancel_requested"] = True
        self.save()
        self.run_root()
        self.assertEqual(self.read()["stages"], [])
        self.assertEqual(self.read()["operation"]["status"], "cancelled")

    def test_lost_terminal_reply_resumes_same_root_without_repeating_stages(self):
        self.state["loseFinal"] = True
        self.save()
        run = self.run_root(success=False)
        stages = self.read()["stages"]
        self.cli("workflow", "resume", "operation-task-vm-resume", "--run", run)
        self.assertEqual(self.read()["stages"], stages)
        self.assertEqual(self.read()["operation"]["status"], "succeeded")


if __name__ == "__main__":
    unittest.main()
