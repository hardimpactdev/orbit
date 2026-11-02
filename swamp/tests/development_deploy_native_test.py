"""Actual development DAG, official SSH, scratch checkout and private stdin copies."""
import fcntl
import hashlib
import importlib.util
import json
import os
import sys

import yaml
from native_harness import ROOT, NativeTestCase

spec = importlib.util.spec_from_file_location('development_scripts', ROOT / 'extensions/behaviors/operations/development-deploy/scripts_test.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class DevelopmentDeployNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations', 'automatic-deploy', '_lib')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        fixture = module.DevelopmentScripts()
        fixture.setUp()
        self.addCleanup(fixture.doCleanups)
        self.checkout, self.second = fixture.repo, fixture.second
        binary = self.repo / 'fixture-ssh'
        binary.write_text('#!' + sys.executable + '\n' + '''
import json,os,shlex,sys
argv=shlex.split(sys.argv[-1])
# The external GitHub fetch is replaced by already populated scratch remote refs.
if len(argv)>3 and argv[3]=='fetch':
    print(json.dumps({'ready':True}))
else:
    os.execv(argv[0],argv)
''')
        binary.chmod(0o700)
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        identity = self.repo / 'identity'
        identity.write_text('synthetic unused identity')
        self.inputs = {'operation_id': 17, 'node_id': 9, 'kind': 'instance.deploy', 'execution_secrets': {}, 'intent': fixture.input['intent']}
        self.inputs['intent'].update(instance_id=4, manager=None, ssh_execution={'identity_file': str(identity), 'target': {
            'user': self.inputs['intent']['deployment']['node_user'], 'port': 22, 'host': 'fixture.invalid',
            'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}})
        self.inputs['intent']['deployment'].update(needs_vp=False, steps=[])
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-instance-deploy-direct.yaml').read_text())
        self.workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-instance-deploy-direct.yaml').read_text())
        workflow['jobs'] = workflow['jobs'][:2]
        for job in workflow['jobs']:
            self.assertEqual(job.pop('labels'), {'role': 'swamp'})
            for step in job['steps']:
                task = step['task']
                if task.get('behaviorType') == '@swamp/ssh':
                    task['globalArgs']['sshBinary'] = str(binary)
                if task.get('type') == 'workflow':
                    # VP is skipped; target-local runtime preparation has separate tests.
                    step['task'] = {'type': 'behavior', 'behaviorType': 'command/shell', 'behaviorName': 'unused-manager', 'methodName': 'execute', 'inputs': {'run': 'true'}}
        (self.repo / 'workflows/workflow-operation-instance-deploy-direct.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False, width=1000000))

    def command(self, command, required=True):
        index = len(self.inputs['intent']['deployment']['steps'])
        key = f'development-deploy-operation-17-step-{index}'
        self.inputs['intent']['deployment']['steps'].append({'name': f'command-{index}', 'required': required,
            'timeout_seconds': 5, 'command_digest': hashlib.sha256(command.encode()).hexdigest()})
        self.cli('vault', 'put', 'orbit-control-inputs', key, input=command)
        self.inputs['execution_secrets'][f'step_{index}'] = {'vault': 'orbit-control-inputs', 'key': key}

    def run_workflow(self, success=True):
        self.cli('workflow', 'validate', 'operation-instance-deploy-direct')
        reply = self.cli('workflow', 'run', 'operation-instance-deploy-direct', '--stdin', input=json.dumps(self.inputs), success=success)
        self.assertTrue(reply.stdout.strip(), reply.stderr)
        result = json.loads(reply.stdout)
        self.history = json.loads(self.cli('workflow', 'history', 'get', result['id']).stdout)
        self.steps = {s['name']: s for job in self.history['jobs'] for s in job['steps']}
        return json.loads(self.steps['result']['outputs']['stdout'])

    def test_ordered_success_keeps_private_commands_and_environment_out_of_native_history(self):
        sentinel = "native-private-" + os.urandom(12).hex()
        (self.checkout / '.env').write_text('TOKEN="' + sentinel + '"\n')
        self.command('test "$TOKEN" = ' + sentinel + '; printf ' + sentinel + '; printf first > proof')
        self.command('test "$(cat proof)" = first; printf second >> proof')
        self.secrets = (sentinel,)
        result = self.run_workflow()
        self.assertEqual(result['commit'], self.second)
        self.assertIsNone(result['failed_step'])
        self.assertEqual((self.checkout / 'proof').read_text(), 'firstsecond')
        self.assertEqual(self.steps['dev_app_2']['status'], 'skipped')

    def test_required_failure_never_reopens_later_commands_or_final_success(self):
        self.command('exit 7')
        self.command('touch must-not-exist')
        result = self.run_workflow(success=False)
        self.assertEqual(result['failed_step'], 'dev_app_0')
        self.assertFalse((self.checkout / 'must-not-exist').exists())
        self.assertEqual(self.steps['dev_app_1']['status'], 'skipped')
        self.assertEqual(self.steps['dev_inspect']['status'], 'skipped')

    def test_optional_failure_continues_and_records_one_warning(self):
        self.command('exit 7', required=False)
        self.command('touch proof')
        result = self.run_workflow()
        self.assertEqual(result['warnings'], 1)
        self.assertTrue((self.checkout / 'proof').exists())

    def test_dirty_checkout_prevents_commands_even_when_vp_is_skipped(self):
        (self.checkout / 'app').write_text('dirty')
        self.command('touch must-not-exist')
        result = self.run_workflow(success=False)
        self.assertEqual(result['failed_step'], 'dev_check')
        self.assertFalse((self.checkout / 'must-not-exist').exists())
        self.assertEqual(self.steps['dev_inspect']['status'], 'skipped')

    def test_final_inspection_refuses_a_command_that_changes_branch(self):
        self.command('git checkout -b unexpected')
        result = self.run_workflow(success=False)
        self.assertEqual(result['failed_step'], 'dev_inspect')
        self.assertEqual(result['error_code'], 'deployment.branch_changed')


    def test_native_checkout_transaction_refuses_an_existing_directory_owner(self):
        descriptor = os.open(self.checkout, os.O_RDONLY | os.O_DIRECTORY)
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.command('touch must-not-exist')
            result = self.run_workflow(success=False)
        finally:
            os.close(descriptor)
        self.assertEqual(result['failed_step'], 'dev_clean')
        self.assertEqual(self.steps['dev_checkout']['status'], 'skipped')
        self.assertFalse((self.checkout / 'must-not-exist').exists())

    def test_production_branch_does_not_evaluate_development_transport_or_commands(self):
        workflow = self.workflow
        for job in workflow['jobs']:
            job.pop('target', None); job.pop('labels', None)
            for step in job['steps']:
                step.pop('target', None); step.pop('labels', None)
                if job['name'] in ('deploy', 'failure'):
                    step['task'] = {'type': 'behavior', 'behaviorType': 'command/shell', 'behaviorName': 'production-' + step['name'],
                        'methodName': 'execute', 'inputs': {'run': 'true'}}
        (self.repo / 'workflows/workflow-operation-instance-deploy-direct.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False, width=1000000))
        self.inputs['intent'] = {'instance_id': 4, 'process_members': [], 'deployment': {'variant': 'production', 'needs_vp': True, 'steps': [{'name': 'production-command', 'timeout_seconds': 5}]}}
        self.cli('workflow', 'validate', 'operation-instance-deploy-direct')
        result = json.loads(self.cli('workflow', 'run', 'operation-instance-deploy-direct', '--stdin', input=json.dumps(self.inputs)).stdout)
        self.assertEqual(result['status'], 'succeeded')
        development = next(j for j in result['jobs'] if j['name'] == 'development')
        self.assertTrue(all(s['status'] == 'skipped' for s in development['steps']))
