"""UpCloud provision rollback on official native graphs with disposable boundaries."""
import json
import shutil
import unittest

import task_vm_provision_composition_native_test as composition
import task_vm_upcloud_provider_native_test as provider
import yaml
from native_harness import ROOT


class UpCloudCompositionNative(unittest.TestCase):
    def setUp(self):
        self.cloud = provider.UpCloudProviderNative()
        self.cloud.setUp()
        self.addCleanup(self.cloud.doCleanups)
        self.vm = composition.TaskVmProvisionComposition()
        self.vm.setUp()
        self.addCleanup(self.vm.doCleanups)
        for script in ('upcloud.py', 'upcloud_create.py'):
            shutil.copy(self.cloud.repo / 'extensions/behaviors/operations/task-vm-lifecycle/scripts' / script,
                        self.vm.repo / 'extensions/behaviors/operations/task-vm-lifecycle/scripts' / script)
        for name in ('task-vm-provider-upcloud', 'task-vm-provider-power'):
            shutil.copy(self.cloud.repo / f'workflows/workflow-{name}.yaml', self.vm.repo / f'workflows/workflow-{name}.yaml')
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-task-vm-destroy.yaml').read_text())
        for job in workflow['jobs']:
            job.pop('target')
            for step in job['steps']:
                step.pop('target')
                if step['task'].get('behaviorType') == '@swamp/ssh':
                    step['task']['globalArgs']['sshBinary'] = str(self.vm.repo / 'ssh')
        (self.vm.repo / 'workflows/workflow-task-vm-destroy.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.vm.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        self.vm.cli('vault', 'put', 'orbit-control-inputs', 'task-vm-upcloud-token', input=self.cloud.token)
        self.vm.cli('vault', 'put', 'orbit-control-inputs', 'task-vm-provider-bootstrap-42', input=json.dumps({'private': self.cloud.private, 'public': self.cloud.public}))
        self.vm.secrets = self.cloud.secrets
        self.vm.intent.update(self.cloud.intent)
        self.vm.inputs['node_id'] = 14
        self.vm.inputs['execution_secrets'].update(self.cloud.copies)

    def test_full_cloud_provision_pins_public_then_private_ssh_and_no_worker(self):
        run = self.vm.run_root()
        history = json.loads(self.vm.cli('workflow', 'history', 'get', run['id']).stdout)
        result = json.loads(history['jobs'][0]['steps'][-1]['outputs']['stdout'])
        self.assertTrue(result['ready'])
        self.assertEqual(result['provider']['provider_id'], self.cloud.provider_id)
        self.assertEqual(result['enrollment']['node']['ssh_host_key'], self.cloud.public.split(' ')[1])
        calls = self.vm.events.read_text()
        self.assertIn('93.184.216.34', calls)
        self.assertIn('10.44.0.142', calls)
        self.assertNotIn('worker connect', calls)
        self.assertTrue(self.cloud.exists)
        self.vm.assert_private_absent()

    def test_cloud_enrollment_composes_actual_runtime_with_only_runtime_descriptors(self):
        self.vm.copy_behaviors('operations/task-vm-pi')
        secret = 'native-cloud-runtime-fixture-c98dce'
        for key in ('task-vm-model-key-42', 'task-vm-pi-token-42'):
            self.vm.cli('vault', 'put', 'orbit-control-inputs', key, input=secret)
        self.vm.secrets = (*self.vm.secrets, secret)
        self.vm.intent['runtime'].update({'pi_sha256': 'a' * 64, 'origin': 'http://192.0.2.6'})
        self.vm.intent['runtime']['process'].update({'unit_name': 'orbit-process-33.service', 'unit_path': '/etc/systemd/system/orbit-process-33.service'})
        fake = self.vm.repo / 'ssh'
        program = fake.read_text().replace("'body':body", "'body':'' if 'pi_model.py' in program or 'pi_token.py' in program else body")
        program = program.replace("if '/etc/ssh/ssh_host_ed25519_key.pub' in argv:", "if 'pi_inspect.py' in program:print(json.dumps({'task_vm_id':42,'sha256':'a'*64,'current':True}))\nelif '/etc/ssh/ssh_host_ed25519_key.pub' in argv:")
        fake.write_text(program)
        scp = self.vm.repo / 'scp'
        scp.write_text('#!/bin/sh\nexit 0\n')
        scp.chmod(0o755)
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-task-vm-runtime.yaml').read_text())
        for job in workflow['jobs']:
            job.pop('target')
            for step in job['steps']:
                step.pop('target')
                if step['task'].get('behaviorType') == '@swamp/ssh':
                    step['task']['globalArgs']['sshBinary'] = str(fake)
                    step['task']['globalArgs']['scpBinary'] = str(scp)
        (self.vm.repo / 'workflows/workflow-task-vm-runtime.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        run = self.vm.run_root()
        self.assertEqual(run['status'], 'succeeded')
        self.assertTrue(self.cloud.exists)
        self.vm.assert_private_absent()

    def test_failure_after_firewall_revokes_restores_hub_and_deletes_without_guest_restore(self):
        self.vm.fixture('task-vm-runtime', 'result', {'task_vm_id': 43, 'guest_node_id': 142, 'process_id': 33, 'ready': True}, stdout=True)
        run = self.vm.run_root(False)
        self.assertEqual(run['status'], 'failed')
        self.assertFalse(self.cloud.exists)
        calls = self.vm.events.read_text()
        self.assertIn('10.44.0.15', calls)
        self.assertIn('restoring', calls)
        self.assertNotIn('def restore(data)', calls)
        self.assertEqual(sum(method == 'DELETE' for method, _ in self.cloud.calls), 1)

    def test_foreign_model_revocation_refuses_provider_delete(self):
        self.vm.fixture('task-vm-runtime', 'result', {'task_vm_id': 43, 'guest_node_id': 142, 'process_id': 33, 'ready': True}, stdout=True)
        self.vm.fixture('task-vm-model-revoke', 'result', {'task_vm_id': 43, 'revoked': True}, stdout=True)
        self.vm.run_root(False)
        self.assertTrue(self.cloud.exists)
        self.assertFalse(any(method == 'DELETE' for method, _ in self.cloud.calls))

    def test_upcloud_destroy_uses_vault_api_and_no_incus_seed_or_guest_call(self):
        self.cloud.exists, self.cloud.state = True, 'started'
        inputs = {'operation_id': 7008, 'node_id': 14, 'kind': 'task_vm.destroy',
                  'intent': {'task_vm_id': 42, 'guest_node_id': None, 'provider': 'upcloud',
                             'model_key_required': True, 'model_intent': self.vm.intent['model_intent'],
                             'removal': None, 'provider_input': {'task_vm_id': 42, 'name': 'tvm-42',
                             'provider': 'upcloud', 'provider_id': self.cloud.provider_id,
                             'vm': None, 'host_ssh_execution': None}},
                  'execution_secrets': {key: value for key, value in self.vm.inputs['execution_secrets'].items()
                                        if key not in ('bootstrap', 'pi_token')}}
        run = json.loads(self.vm.cli('workflow', 'run', 'task-vm-destroy', '--input', json.dumps(inputs)).stdout)
        history = json.loads(self.vm.cli('workflow', 'history', 'get', run['id']).stdout)
        receipt = json.loads(history['jobs'][0]['steps'][-1]['outputs']['result_json'])
        self.assertTrue(receipt['destroyed'])
        self.assertTrue(receipt['cleanup_complete'])
        self.assertFalse(self.cloud.exists)
        self.assertFalse(self.vm.events.exists())
        self.assertTrue(any('/stop' in path for method, path in self.cloud.calls if method == 'POST'))
        self.assertEqual(sum(method == 'DELETE' for method, _ in self.cloud.calls), 1)

    def test_cancel_after_guest_configuration_runs_provider_compensation(self):
        path = self.vm.repo / 'workflows/workflow-task-vm-runtime.yaml'
        workflow = yaml.safe_load(path.read_text())
        workflow['jobs'][0]['steps'][0]['task']['inputs']['run'] = 'sleep 60'
        path.write_text(yaml.safe_dump(workflow, sort_keys=False))
        run = self.vm.run_root(False, '--timeout', '25s')
        self.assertEqual(run['status'], 'cancelled')
        self.assertFalse(self.cloud.exists)
        self.assertFalse(any('def restore(data)' in json.loads(line)['body'] for line in self.vm.events.read_text().splitlines()))

    def test_prefix_failure_cleans_unknown_uuid_without_guest_actions(self):
        self.cloud.state = 'maintenance'
        self.vm.run_root(False)
        # Transitional state cannot be safely powered/deleted. Discovery retains
        # the exact VM for normal native observation/resume, never false success.
        self.assertTrue(self.cloud.exists)
        self.assertFalse(self.vm.events.exists())
        self.assertFalse(any(method == 'DELETE' for method, _ in self.cloud.calls))


if __name__ == '__main__':
    unittest.main()
