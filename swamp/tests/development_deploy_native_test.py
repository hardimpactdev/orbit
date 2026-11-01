"""Execute the real F9 development phase graph with official SSH and a scratch vault."""
import hashlib
import importlib.util
import json
import os
import sys

import yaml
from native_harness import ROOT, NativeTestCase

spec = importlib.util.spec_from_file_location('source_parity', ROOT / 'extensions/behaviors/operations/development-deploy/phases/source_preparation_test.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class DevelopmentDeployNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations', 'automatic-deploy', '_lib')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        fixture = module.SourceParity()
        fixture.setUp()
        self.addCleanup(fixture.doCleanups)
        self.checkout, self.second = fixture.repo, fixture.second
        binary = self.repo / 'fixture-ssh'
        binary.write_text('#!' + sys.executable + '\n' + """
import os,shlex,sys
argv=shlex.split(sys.argv[-1])
# The official extension runs its real argv/stdin path; only the external SSH process is local.
while os.path.basename(argv[0]) == 'sudo':
    argv=argv[argv.index('--')+1:]
os.execv(argv[0],argv)
""")
        binary.chmod(0o700)
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        identity = self.repo / 'identity'
        identity.write_text('synthetic unused identity')
        self.inputs = {'operation_id': 17, 'node_id': 9, 'kind': 'instance.deploy', 'execution_secrets': {}, 'intent': fixture.intent}
        self.inputs['intent'].update(instance_id=4, manager=None, command_timeout_seconds=60, ssh_execution={'identity_file': str(identity), 'target': {
            'user': self.inputs['intent']['deployment']['node_user'], 'port': 22, 'host': 'fixture.invalid',
            'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}})
        self.inputs['intent']['deployment'].update(needs_vp=False, steps=[])
        self.workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-instance-deploy-direct.yaml').read_text())
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-instance-deploy-direct.yaml').read_text())
        workflow['jobs'] = workflow['jobs'][:1]
        for job in workflow['jobs']:
            job.pop('target', None)
            for step in job['steps']:
                self.assertEqual(step.pop('target'), 'orbit-node-209')
                task = step['task']
                if task.get('behaviorType') == '@swamp/ssh':
                    task['globalArgs']['sshBinary'] = str(binary)
                if task.get('type') == 'workflow':
                    # The manager path is guarded off for this no-VP fixture, not replaced by a success effect.
                    step['task'] = {'type': 'assert', 'expr': 'false', 'severity': 'high', 'message': 'Unexpected VP admission'}
        (self.repo / 'workflows/workflow-operation-instance-deploy-direct.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.commands = []

    def command(self, command, required=True, timeout=5):
        index = len(self.commands)
        self.commands.append(command)
        self.inputs['intent']['deployment']['steps'].append({'name': f'command-{index}', 'phase': 'before_activation', 'required': required,
            'timeout_seconds': timeout, 'command_digest': hashlib.sha256(command.encode()).hexdigest()})
        key = 'development-deploy-operation-17-commands'
        self.cli('vault', 'put', 'orbit-control-inputs', key, input=json.dumps(self.commands))
        self.inputs['execution_secrets']['commands'] = {'vault': 'orbit-control-inputs', 'key': key}

    def run_workflow(self, success=True):
        self.cli('workflow', 'validate', 'operation-instance-deploy-direct')
        reply = self.cli('workflow', 'run', 'operation-instance-deploy-direct', '--stdin', input=json.dumps(self.inputs), success=success)
        result = json.loads(reply.stdout)
        self.history = json.loads(self.cli('workflow', 'history', 'get', result['id']).stdout)
        self.steps = {s['name']: s for job in self.history['jobs'] for s in job['steps']}
        return json.loads(self.steps['result']['outputs']['stdout'])

    def test_ordered_success_keeps_commands_and_dotenv_out_of_native_history(self):
        sentinel = 'native-private-' + os.urandom(12).hex()
        (self.checkout / '.env').write_text('TOKEN="' + sentinel + '"\n')
        self.command('test "$TOKEN" = ' + sentinel + '; printf ' + sentinel + '; printf first > proof')
        self.command('test "$(cat proof)" = first; printf second >> proof')
        self.secrets = (sentinel,)
        result = self.run_workflow()
        self.assertEqual(result['commit'], self.second)
        self.assertIsNone(result['failed_step'])
        self.assertEqual((self.checkout / 'proof').read_text(), 'firstsecond')
        self.assertEqual([self.steps[n]['status'] for n in ('source', 'commands', 'accepted')], ['succeeded'] * 3)

    def test_no_project_commands_needs_no_vault_copy_and_still_records_the_source(self):
        result = self.run_workflow()
        self.assertEqual(result['commit'], self.second)
        self.assertTrue(result['ready'])
        self.assertEqual(self.steps['commands']['status'], 'skipped')
        self.assertEqual(self.steps['accepted']['status'], 'succeeded')

    def test_required_failure_stops_later_commands_and_keeps_attempted_commit(self):
        self.command('exit 7')
        self.command('touch forbidden')
        result = self.run_workflow(success=False)
        self.assertEqual((result['commit'], result['failed_step'], result['error_code']),
                         (self.second, 'before_activation.command-0', 'deployment.step_failed'))
        self.assertFalse((self.checkout / 'forbidden').exists())

    def test_best_effort_failure_continues_and_records_warning(self):
        self.command('exit 7', required=False)
        self.command('touch continued')
        self.assertEqual(self.run_workflow()['warnings'], 1)
        self.assertTrue((self.checkout / 'continued').exists())

    def test_dirty_checkout_refuses_before_commands(self):
        (self.checkout / 'app').write_text('dirty')
        self.command('touch forbidden')
        result = self.run_workflow(success=False)
        self.assertEqual(result['error_code'], 'deployment.checkout_dirty')
        self.assertEqual(self.steps['commands']['status'], 'skipped')
        self.assertFalse((self.checkout / 'forbidden').exists())

    def test_final_branch_check_cannot_advance_a_different_seed(self):
        self.command('git checkout -b unexpected')
        result = self.run_workflow(success=False)
        self.assertEqual(result['error_code'], 'deployment.branch_changed')

    def test_per_command_timeout_is_a_terminal_failure(self):
        self.command('sleep 30', timeout=1)
        self.command('touch forbidden')
        self.assertEqual(self.run_workflow(success=False)['error_code'], 'deployment.step_failed')
        self.assertFalse((self.checkout / 'forbidden').exists())
