"""Prove deployment recovery in the pinned Swamp runtime across fresh processes."""
import json
from pathlib import Path
import sys
import unittest

FAMILY = Path(__file__).resolve().parent
ROOT = FAMILY.parents[3]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class DeploymentNativeTest(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/instance-deploy')
        family = self.repo / 'extensions/behaviors/operations/instance-deploy'
        source = (family / 'mod.ts').read_text().replace('...instanceDeploy()', '...instanceDeploy(() => fixtureHttp)')
        (family / 'mod.ts').write_text('import { fixtureHttp } from "./native_executor.ts";\n' + source)
        # Placement is exercised by the kit worker test; this fixture proves local process recovery.
        source = (ROOT / 'workflows/workflow-operation-instance-deploy.yaml').read_text()
        source = '\n'.join(line for line in source.splitlines()
                           if not line.startswith(('labels:', 'queueTimeout:'))) + '\n'
        (self.repo / 'workflows/workflow-operation-instance-deploy.yaml').write_text(source)
        discovery = self.repo / 'extensions/behaviors/operation-runner/mod.ts'
        discovery.write_text('import { fixtureHttp } from "../operations/instance-deploy/native_executor.ts";\n' + discovery.read_text().replace('...operationDiscovery()', '...operationDiscovery(() => fixtureHttp)'))
        descriptors = []
        for key, step, name, transport, inputs in (
            ('source.resolve', 'source_preparation.resolve', 'instance.resolve-development-target', 'agent', {}),
            ('source.checkout', 'source_preparation', 'instance.checkout-development-default', 'agent', {}),
            ('before_activation.install', 'before_activation.install', 'instance.run-development-step', 'agent', {'script': {'$secret': 'instance.deployment', 'id': 17}}),
            ('verification', 'verification', 'instance.deployment-receipt', 'gateway', {'instance_id': 17}),
        ):
            descriptors.append(dict(key=key, step=step, name=name, transport=transport,
                                    input=inputs, resumable=True, required=True))
        self.state = {'operation': {'id': 7, 'kind': 'instance.deploy', 'node_id': 9,
                      'status': 'queued', 'worker': 'proof', 'cancel_requested': False,
                      'input': {'deployment': {'variant': 'development', 'php': False,
                                'steps': [{'name': 'install', 'phase': 'before_activation', 'required': True}]}},
                      'steps': [], 'step_results': {}, 'execution_context': {'descriptors': descriptors}},
                      'effects': [], 'receipts': {}, 'completions': [], 'loseRunReply': None,
                      'loseCompletionReply': False}

    def save(self):
        (self.repo / 'native-executor.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / 'native-executor.json').read_text())

    def swamp(self, *args, success=True):
        if args[:2] == ("workflow", "resume"):
            args = (*args[:3], "--run", self.last_run_id)
        result = self.cli(*args, success=success)
        if args[:2] == ("workflow", "run"):
            value = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
            self.last_run_id = value.get("id")

    def run_deploy(self, success=True):
        self.swamp('workflow', 'run', 'operation-instance-deploy', success=success)

    def test_lost_node_reply_resumes_without_running_the_script_twice(self):
        self.state['loseRunReply'] = 'before_activation.install'
        self.save()
        self.swamp('workflow', 'validate', 'operation-instance-deploy')
        self.run_deploy(success=False)
        self.assertEqual(self.read()['completions'], [])
        self.swamp('workflow', 'resume', 'operation-instance-deploy')
        state = self.read()
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['effects'].count('before_activation.install'), 1)
        self.assertEqual(state['invocations'].count('before_activation.install'), 1)
        self.assertEqual(state['claims'], 1)
        self.assertIn('before_activation.install', state['receiptReads'])
        self.assertEqual(state['completions'][0]['result']['deployment_id'], 51)

    def test_environment_child_adopts_the_original_write_after_process_restart(self):
        operation = self.state['operation']
        operation['kind'] = 'instance.sync_environment'
        operation['input'] = {'instance_id': 17}
        descriptors = []
        for key, step, name, transport, inputs in (
            ('environment.prepare', 'environment_prepare', 'instance.environment-prepare', 'gateway', {'instance_id': 17}),
            ('environment.write', 'environment_sync', 'instance.write-environment', 'instance-environment-agent',
             {'content': {'$secret': 'instance.environment_sync', 'id': 17}}),
            ('environment.receipt', 'environment_receipt', 'instance.environment-receipt', 'gateway', {'instance_id': 17}),
        ):
            descriptors.append(dict(key=key, step=step, name=name, transport=transport,
                                    input=inputs, resumable=True, required=True))
        operation['execution_context']['descriptors'] = descriptors
        self.state['runResults'] = {
            'environment.prepare': {'prepared': True, 'no_op': False},
            'environment.write': {'state': 'changed', 'changed': True, 'confirmed': True},
            'environment.receipt': {'instance_id': 17, 'confirmed': True, 'changed': True,
                                    'environment_changed': True, 'key_count': 1,
                                    'testing_status': None, 'test_database': None},
        }
        self.state['loseRunReply'] = 'environment.write'
        self.save()
        self.run_deploy(success=False)
        self.assertEqual(self.read()['completions'], [])
        self.swamp('workflow', 'resume', 'operation-instance-deploy')
        state = self.read()
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['invocations'].count('environment.write'), 1)
        self.assertEqual(state['effects'].count('environment.write'), 1)
        self.assertIn('environment.write', state['receiptReads'])
        self.assertEqual(state['claims'], 1)
        self.assertEqual(state['completions'][0]['result'], self.state['runResults']['environment.receipt'])

    def test_lost_completion_reply_replays_saved_intent_without_node_effects(self):
        self.state['loseCompletionReply'] = True
        self.save()
        self.run_deploy(success=False)
        before = self.read()['effects']
        self.swamp('workflow', 'resume', 'operation-instance-deploy')
        state = self.read()
        self.assertEqual(state['effects'], before)
        self.assertEqual(len(state['completions']), 1)
        self.assertEqual(state['claims'], 1)


if __name__ == '__main__':
    unittest.main()
