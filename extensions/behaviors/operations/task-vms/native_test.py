"""Run the pinned native Swamp DAG and resume it in a new process after a lost reply."""
import json
from pathlib import Path
import shutil
import unittest
import sys

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


class TaskVmNativeTest(NativeTestCase):
    native_timeout = 45

    def setUp(self):
        super().setUp()
        self.copy_behaviors("automatic-deploy", "operation-runner", "operations/task-vms", "operations/_lib")
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
        sweep = self.repo / 'extensions/behaviors/operations/task-vms/sweep.ts'
        sweep.write_text('import { fixtureHttp } from "./native-fixture.ts";\nimport { fixtureSweepAgent, fixtureSweepCloud } from "./sweep-native-fixture.ts";\n' + sweep.read_text().replace(
            '...nativeSweep()', '...nativeSweep(() => fixtureHttp, fixtureSweepAgent, fixtureSweepCloud)'))
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

    def test_native_cache_workflow_validates_exact_host_placement(self):
        source = (ROOT / 'workflows/workflow-operation-task-vm-cache-fill.yaml').read_text()
        self.assertIn('target: \'${{ "orbit-node-" + string(inputs.node_id) }}\'', source)
        self.assertIn("labels: { node: '${{ string(inputs.node_id) }}' }", source)
        self.assertNotIn('trigger:', source)
        self.assertNotIn('queueTimeout:', source)
        self.cli('workflow', 'validate', 'operation-task-vm-cache-fill')

    def test_runner_dispatches_the_vm_family_with_proven_steps(self):
        for workflow in ("operation-task-vms",):
            self.cli("workflow", "validate", workflow)
        self.cli("workflow", "run", "operation-task-vms")
        state = self.read()
        self.assertEqual(state["operation"]["status"], "succeeded")
        self.assertEqual(state["operation"]["steps"], [{"name":"incus-start", "status":"succeeded"}])
        self.assertEqual(state["runs"], 1)

    def test_resume_after_lost_run_reply_reuses_the_node_receipt(self):
        self.state["loseRunReply"] = True
        self.save()
        failed = self.cli("workflow", "run", "operation-task-vms", success=False)
        run_id = json.JSONDecoder().raw_decode(failed.stdout.lstrip())[0]["id"]
        self.assertEqual(self.read()["runs"], 1)
        self.assertEqual(self.read()["completions"], [])
        self.cli("workflow", "resume", "operation-task-vms", "--run", run_id)
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
        return self.cli('workflow', 'run', 'operation-task-vms', '--input',
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
        failed = self.run_direct(success=False)
        run_id = json.JSONDecoder().raw_decode(failed.stdout.lstrip())[0]["id"]
        self.assertEqual(self.read()['operation']['status'], 'succeeded')
        self.cli('workflow', 'resume', 'operation-task-vms', '--run', run_id)
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


    def prepare_native_sweep(self, provider='incus'):
        path = self.repo / 'workflows/workflow-operation-task-vm-sweep.yaml'
        source = path.read_text()
        self.assertIn('target:', source)
        self.assertNotIn('trigger:', source)
        self.assertNotIn('queueTimeout:', source)
        self.cli('workflow', 'validate', 'operation-task-vm-sweep')
        path.write_text('\n'.join(line for line in source.splitlines()
                                  if not line.strip().startswith(('target:', 'labels:'))) + '\n')
        self.state['operation'].update(kind='task_vm.sweep_host', result=None, input={
            'node_id': 3, 'provider': provider, 'deadline_at': 4000000000, 'ci_runner': False, 'stray_vm_ids': [],
            'agent_sha256': 'a'*64, 'host': None if provider == 'upcloud' else {
                'project': 'orbit-tasks', 'pool': 'orbit-tasks', 'cidr': '10.1.0.1/24', 'operator_owned': []},
            'retired_build_ids': [], 'live_names': ['tvm-5'], 'protected_build_ids': [], 'recorded_build_ids': [], 'running_stamps': []})
        self.state.update(hostNames=['tvm-5', 'tvm-999', 'operator-vm'],
                          hostVolumes=['tvm-5-topology-seed', 'tvm-999-topology-seed', 'operator-data'])
        self.save()

    def run_native_sweep(self, success=True):
        return self.cli('workflow', 'run', 'operation-task-vm-sweep', '--input',
                        json.dumps({'operation_id': 19, 'node_id': 3, 'kind': 'task_vm.sweep_host'}), success=success)

    def test_native_sweep_has_no_claim_child_or_receipt_path_and_preserves_live_operator_resources(self):
        self.prepare_native_sweep()
        self.run_native_sweep()
        state = self.read()
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['hostNames'], ['tvm-5', 'operator-vm'])
        self.assertEqual(state['hostVolumes'], ['tvm-5-topology-seed', 'operator-data'])
        self.assertEqual(state.get('discoveryCalls', 0), 0)
        self.assertEqual(state['runs'], 0)
        self.assertEqual(state['operation']['result']['deleted_names'], ['tvm-999', 'tvm-999-topology-seed'])

    def test_native_sweep_terminal_reply_loss_replays_the_same_root_without_more_deletion(self):
        self.prepare_native_sweep()
        self.state['loseDirectReply'] = True
        self.save()
        failed = self.run_native_sweep(success=False)
        run_id = json.JSONDecoder().raw_decode(failed.stdout.lstrip())[0]['id']
        before = self.read()['sweepCalls']
        self.cli('workflow', 'resume', 'operation-task-vm-sweep', '--run', run_id)
        self.assertEqual(self.read()['sweepCalls'], before)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')

    def test_native_upcloud_sweep_hydrates_existing_material_without_retaining_it(self):
        import secrets
        token = 'ucat_' + secrets.token_hex(24)
        self.secrets = (token,)
        self.native_env['SWEEP_PRIVATE_FIXTURE'] = token
        self.prepare_native_sweep('upcloud')
        self.state['cloudServer'] = {'uuid': '0753940c-1382-456a-9b45-63601e6c7dd1',
            'title': 'tvm-999', 'hostname': 'tvm-999', 'state': 'stopped',
            'labels': {'label': [{'key': 'orbit-task-vm', 'value': '999'}]}}
        self.save()
        self.run_native_sweep()
        state = self.read()
        self.assertIsNone(state['cloudServer'])
        self.assertEqual(state['operation']['result']['deleted_names'], ['tvm-999'])
        self.assertEqual(state['operation']['status'], 'succeeded')


if __name__ == "__main__":
    unittest.main()
