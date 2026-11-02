"""task_vm.resume against a fake Incus host and guest behind official SSH; never contacts a fleet Node.

The fake `ssh` runs nothing: it tells the host (192.0.2.42) from the guest (10.44.0.142) by the
destination, answers from a JSON state file and logs each argv, so the test sees the exact commands.
"""
import json
import sys

import yaml

from native_harness import ROOT, NativeTestCase

FAKE = '''import json,shlex,sys
from pathlib import Path
if '-M' in sys.argv or '-O' in sys.argv:raise SystemExit(0)
destination=next(arg for arg in sys.argv if arg.startswith('orbit@'));address=destination.split('@',1)[1]
args=shlex.split(sys.argv[-1]);state=Path(STATE);value=json.loads(state.read_text())
body=sys.stdin.read()
with Path(CALLS).open('a') as output:output.write(json.dumps({'address':address,'argv':args,'stdin':body})+'\\n')
if address=='192.0.2.42':
    if args[:1]==['/usr/bin/sudo']:raise SystemExit(0)
    assert args[:4]==['sudo','-n','--','timeout'] and args[5]=='incus',args
    command=args[6:]
    if command[:4]==['start','--project','tasks','--']:
        if value['status']=='Running':print('Error: The instance is already running',file=sys.stderr);raise SystemExit(1)
        if not value.get('stuck'):value['status']='Running'
        state.write_text(json.dumps(value));raise SystemExit(0)
    if command[:3]==['wait','--project','tasks'] and command[-3:]==['--','tvm-42','agent']:
        raise SystemExit(0 if value['status']=='Running' else 1)
    raise SystemExit(9)
assert address=='10.44.0.142',address
if args==['/usr/bin/systemctl','is-active','orbit-agent.service']:
    print(value.get('agent','active'));raise SystemExit(0 if value.get('agent','active')=='active' else 3)
if args[:3]==['/usr/bin/python3','-B','-c'] and 'urllib.request' in args[3]:
    assert json.loads(body)['pi_url']=='http://10.44.0.142:3774'
    if value.get('pi_down'):print('task_vm.resume_failed',file=sys.stderr);raise SystemExit(1)
    print(json.dumps({'pi_ready':True}));raise SystemExit(0)
raise SystemExit(9)
'''
KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG'


class TaskVmResumeNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.copy_behaviors('operations/task-vm-lifecycle')
        self.state = self.repo / 'state.json'
        self.state.write_text(json.dumps({'status': 'Stopped'}))
        self.calls = self.repo / 'calls.jsonl'
        fake = self.repo / 'ssh'
        fake.write_text('#!' + sys.executable + '\n' + FAKE.replace('STATE', repr(str(self.state))).replace('CALLS', repr(str(self.calls))))
        fake.chmod(0o755)
        for name in ('operation-task-vm-resume', 'task-vm-provider-power'):
            value = yaml.safe_load((ROOT / ('workflows/workflow-' + name + '.yaml')).read_text())
            for job in value['jobs']:
                job.pop('labels', None)
                for step in job['steps']:
                    self.assertEqual(step.pop('labels'), {'role': 'swamp'})
                    if step['task'].get('behaviorType') == '@swamp/ssh':
                        step['task']['globalArgs']['sshBinary'] = str(fake)
            (self.repo / ('workflows/workflow-' + name + '.yaml')).write_text(yaml.safe_dump(value, sort_keys=False))
        identity = {'control_node_id': 209, 'identity_file': '/etc/orbit/swamp-role-worker/ssh-identity', 'public_key': 'ssh-ed25519 PUBLIC'}
        host = {**identity, 'target': {'name': 'target', 'host': '192.0.2.42', 'user': 'orbit', 'port': 22, 'host_keys': [KEY]}}
        guest = {**identity, 'target': {'name': 'target', 'host': '10.44.0.142', 'user': 'orbit', 'port': 22, 'host_keys': [KEY]}}
        provider = {'task_vm_id': 42, 'name': 'tvm-42', 'provider': 'incus', 'provider_id': None,
                    'vm': {'project': 'tasks', 'name': 'tvm-42', 'pool': 'tasks'}, 'host_ssh_execution': host}
        self.inputs = {'operation_id': 7002, 'node_id': 99, 'kind': 'task_vm.resume', 'execution_secrets': {},
                       'intent': {'task_vm_id': 42, 'node_id': 99, 'guest_node_id': 142, 'name': 'tvm-42', 'provider': 'incus', 'provider_id': None,
                                  'user': 'orbit', 'pi_url': 'http://10.44.0.142:3774', 'admitted_state': 'parked', 'mode': 'resume',
                                  'node': {'node_id': 142, 'wireguard_ip': '10.44.0.142'}, 'ssh_identity': identity, 'ssh_execution': guest,
                                  'runtime_json': json.dumps({'pi_sha256': 'a' * 64, 'pi_port': 3774, 'pi_url': 'http://10.44.0.142:3774'}),
                                  'provider_input': provider}}

    def resume(self, success=True):
        run = json.loads(self.cli('workflow', 'run', 'operation-task-vm-resume', '--input', json.dumps(self.inputs), success=success).stdout)
        history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        return run, {step['name']: step['status'] for job in history['jobs'] for step in job['steps']}

    def calls_to(self, address):
        if not self.calls.exists():
            return []
        return [row for row in map(json.loads, self.calls.read_text().splitlines()) if row['address'] == address]

    def test_a_parked_vm_starts_waits_for_its_agent_and_checks_agent_and_pi(self):
        run, steps = self.resume()
        self.assertEqual(run['status'], 'succeeded')
        self.assertEqual([row['argv'][3:] for row in self.calls_to('192.0.2.42')], [
            ['timeout', '60', 'incus', 'start', '--project', 'tasks', '--', 'tvm-42'],
            ['timeout', '130', 'incus', 'wait', '--project', 'tasks', '--timeout', '120', '--interval', '1', '--', 'tvm-42', 'agent']])
        self.assertEqual(sorted(row['argv'][0] for row in self.calls_to('10.44.0.142')), ['/usr/bin/python3', '/usr/bin/systemctl'])
        for name in ('Start the VM', 'Wait for the guest agent', 'Read the guest state-agent', 'Read the guest state-pi', 'Check the guest is ready'):
            self.assertEqual(steps[name], 'succeeded', name)
        self.assertEqual(steps['Start the server'], 'skipped')
        self.assertNotEqual(steps.get('Converge 209 SSH access-0', 'skipped'), 'succeeded')
        self.assertFalse({'eligible', 'provider', 'ssh', 'artifact', 'pi', 'runtime-confirmed', 'result'} & set(steps))

    def test_a_vm_that_already_runs_counts_as_started(self):
        self.state.write_text(json.dumps({'status': 'Running'}))
        run, steps = self.resume()
        self.assertEqual(run['status'], 'succeeded')
        self.assertEqual(steps['Start the VM'], 'succeeded')

    def test_an_agent_that_never_comes_up_fails_before_the_guest_is_read(self):
        self.state.write_text(json.dumps({'status': 'Stopped', 'stuck': True}))
        run, steps = self.resume(False)
        self.assertEqual(run['status'], 'failed')
        self.assertEqual(steps['Wait for the guest agent'], 'failed')
        self.assertEqual(self.calls_to('10.44.0.142'), [])

    def test_a_pi_that_does_not_answer_fails_the_run(self):
        self.state.write_text(json.dumps({'status': 'Stopped', 'pi_down': True}))
        run, steps = self.resume(False)
        self.assertEqual(run['status'], 'failed')
        self.assertNotEqual(steps.get('Check the guest is ready'), 'succeeded')

    def test_an_inactive_agent_fails_the_run(self):
        self.state.write_text(json.dumps({'status': 'Stopped', 'agent': 'inactive'}))
        run, steps = self.resume(False)
        self.assertEqual(run['status'], 'failed')
        self.assertNotEqual(steps.get('Check the guest is ready'), 'succeeded')

    def test_converge_restores_209_access_through_the_host_before_the_check(self):
        self.state.write_text(json.dumps({'status': 'Running'}))
        self.inputs['intent'].update(mode='converge', admitted_state='ready')
        run, steps = self.resume()
        self.assertEqual(run['status'], 'succeeded')
        prefix = ['/usr/bin/sudo', '-n', '--', '/usr/bin/incus', '--project', 'tasks', 'exec', '--mode=non-interactive', '--user', '0', '--group', '0', '--', 'tvm-42']
        changes = [row for row in self.calls_to('192.0.2.42') if row['argv'][:1] == ['/usr/bin/sudo']]
        self.assertEqual(len(changes), 3)
        self.assertTrue(all(row['argv'][:len(prefix)] == prefix for row in changes))
        commands = {row['argv'][len(prefix)]: row for row in changes}
        self.assertEqual(set(commands), {'/usr/bin/install', '/bin/sh', '/usr/bin/systemctl'})
        self.assertEqual(commands['/usr/bin/install']['stdin'], 'ssh-ed25519 PUBLIC\n')
        self.assertEqual(steps['Check the guest is ready'], 'succeeded')
