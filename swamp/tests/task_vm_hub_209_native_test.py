"""task_vm.hub_converge against a fake hub behind official SSH; never contacts a fleet Node.

The fake `ssh` runs nothing: it logs each argv and stdin and answers the nft listing from the expected rule
lines, so the test sees the exact commands, their order and the end check's verdict.
"""
import json
import sys

import yaml
from native_harness import ROOT, NativeTestCase

RANGE = '10.44.0.128/25'
ROUTERS = ['10.44.0.7', '10.44.0.11']


def rule(source, port, comment):
    return 'ip saddr ' + source + ' ip daddr ' + RANGE + ' tcp dport ' + str(port) + ' accept comment "' + comment + '"'


CONTROL = rule('10.44.0.15', 22, 'orbit:task-vm-control-ssh')
ROUTER_RULES = [rule(r, p, 'orbit:task-vm-router-web-' + r.replace('.', '-') + '-' + str(p)) for r in ROUTERS for p in (80, 443)]

FAKE = '''import json,shlex,sys
from pathlib import Path
if '-M' in sys.argv or '-O' in sys.argv:raise SystemExit(0)
args=shlex.split(sys.argv[-1]);body=sys.stdin.read()
assert args[:3]==['sudo','-n','--'],args
args=args[3:]
assert args[:2]==['timeout','60'],args
action='check' if args[2:]==['/usr/sbin/nft','--check','--file','-'] else 'load' if args[2:4]==['sh','-c'] else 'ufw' if args[2]=='ufw' else 'unknown'
with Path(CALLS).open('a') as f:f.write(json.dumps({'action':action,'argv':args,'body':body})+'\\n')
failure=Path(FAILURE).read_text() if Path(FAILURE).exists() else ''
if action=='unknown' or failure==action or (failure=='router-web' and action=='ufw' and '443' in args):raise SystemExit(1)
if action=='load':
 lines=list(LINES)
 if failure=='missing-router':lines.pop()
 if failure=='narrowed':lines[0]='iifname "eth9" '+lines[0]
 if failure=='other-source':lines[0]=lines[0].replace('10.44.0.15','10.44.0.16')
 print('table inet orbit_task_vms {\\n\\tchain forward {\\n\\t\\ttype filter hook forward priority filter - 5; policy accept;')
 for line in lines:print('\\t\\t'+line)
 print('\\t\\tip saddr '+RANGE+' drop\\n\\t}\\n}')
'''


class Hub209Native(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.calls = self.repo / 'calls.jsonl'
        self.failure = self.repo / 'failure'
        fake = self.repo / 'ssh'
        fake.write_text('#!' + sys.executable + '\n' + FAKE.replace('CALLS', repr(str(self.calls))).replace('FAILURE', repr(str(self.failure)))
                        .replace('LINES', repr([CONTROL, *ROUTER_RULES])).replace('RANGE', repr(RANGE)))
        fake.chmod(0o700)
        identity = self.repo / 'identity'
        identity.write_text('fixture SSH identity')
        self.flow = yaml.safe_load((ROOT / 'workflows/workflow-operation-task-vm-hub-converge.yaml').read_text())
        for step in self.flow['jobs'][0]['steps']:
            self.assertEqual(step.pop('labels'), {'role': 'swamp'})
            self.assertNotIn('target', step)
            if step['task'].get('behaviorType') == '@swamp/ssh':
                step['task']['globalArgs']['sshBinary'] = str(fake)
        (self.repo / 'workflows/workflow-operation-task-vm-hub-converge.yaml').write_text(yaml.safe_dump(self.flow, sort_keys=False))
        ufw = ['ufw', 'route', 'allow', 'in', 'on', 'orbit', 'out', 'on', 'orbit', 'proto', 'tcp']
        self.inputs = {'operation_id': 7, 'node_id': 1, 'kind': 'task_vm.hub_converge', 'execution_secrets': {}, 'intent': {
            'node_id': 1, 'source': '10.44.0.15', 'range': RANGE, 'policy': {'routers': ROUTERS},
            'ssh': {'identity_file': str(identity), 'target': {'host': 'fixture.invalid', 'user': 'orbit', 'port': 22,
                                                               'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}},
            'nft': '# Managed by Orbit (task VMs).\ntable inet orbit_task_vms {}\n',
            'unit': '[Unit]\nDescription=Orbit task VM filter on the WireGuard hub\n',
            'live_rules': [CONTROL, *ROUTER_RULES],
            'control_rule': [*ufw, 'from', '10.44.0.15', 'to', RANGE, 'port', '22', 'comment', 'orbit:task-vm-control-ssh'],
            'router_rules': [[*ufw, 'from', r, 'to', RANGE, 'port', str(p), 'comment', 'orbit:task-vm-router-web-' + r.replace('.', '-') + '-' + str(p)]
                             for r in ROUTERS for p in (80, 443)]}}

    def run_hub(self, failure=None):
        self.calls.unlink(missing_ok=True)
        if failure:
            self.failure.write_text(failure)
        self.cli('workflow', 'validate', 'operation-task-vm-hub-converge')
        run = json.loads(self.cli('workflow', 'run', 'operation-task-vm-hub-converge', '--input', json.dumps(self.inputs), success=failure is None).stdout)
        history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        steps = {step['name']: step['status'] for job in history['jobs'] for step in job['steps']}
        calls = [json.loads(line) for line in self.calls.read_text().splitlines()] if self.calls.exists() else []
        return run, steps, calls

    def test_success_checks_writes_loads_then_opens_ufw_and_asserts_the_exact_rules(self):
        run, steps, calls = self.run_hub()
        self.assertEqual(run['status'], 'succeeded')
        self.assertEqual(steps['Confirm the filter'], 'succeeded')
        self.assertEqual([c['action'] for c in calls], ['check', 'load', 'ufw', 'ufw', 'ufw', 'ufw', 'ufw'])
        nft = self.inputs['intent']['nft']
        self.assertEqual(calls[0]['body'], nft)
        # Rule 6: both files, daemon-reload, enable and restart are one argv; the file on stdin, the unit as $1.
        load = calls[1]
        self.assertEqual(load['body'], nft)
        self.assertEqual(load['argv'][-2:], ['sh', self.inputs['intent']['unit']])
        for part in ('/dev/stdin /etc/orbit/task-vms/hub.nft', '/etc/systemd/system/orbit-task-vms-hub.service', 'systemctl daemon-reload',
                     'systemctl enable orbit-task-vms-hub.service', 'systemctl restart orbit-task-vms-hub.service',
                     'nft list chain inet orbit_task_vms forward'):
            self.assertIn(part, load['argv'][4])
        # Rule 8: the UFW rules that make the range reachable come after the filter loads; each one exactly once.
        self.assertEqual(sorted(json.dumps(c['argv'][2:]) for c in calls[2:]),
                         sorted(json.dumps(r) for r in [self.inputs['intent']['control_rule'], *self.inputs['intent']['router_rules']]))
        self.assertFalse(any('swamp' in json.dumps(c['argv']) or 'python3' in json.dumps(c['argv']) for c in calls))

    def test_ufw_rules_run_one_at_a_time_under_names_by_hub(self):
        steps = {step['name']: step for step in self.flow['jobs'][0]['steps']}
        self.assertEqual(steps['Allow 209 SSH, Router web']['concurrency'], 1)
        names = [step['task'].get('behaviorName') for step in steps.values() if step['task']['type'] == 'behavior']
        self.assertTrue(all('inputs.intent.node_id' in name and 'run.id' not in name and 'operation_id' not in name for name in names))
        self.assertEqual(len(set(names)), len(names))

    def test_a_refused_ruleset_changes_nothing(self):
        run, _steps, calls = self.run_hub('check')
        self.assertEqual(run['status'], 'failed')
        self.assertEqual([c['action'] for c in calls], ['check'])

    def test_a_failed_load_opens_no_ufw_rule(self):
        run, steps, calls = self.run_hub('load')
        self.assertEqual(run['status'], 'failed')
        self.assertEqual([c['action'] for c in calls], ['check', 'load'])
        self.assertNotEqual(steps.get('Confirm the filter'), 'succeeded')

    def test_only_the_exact_live_rules_pass_the_end_check(self):
        for failure in ['missing-router', 'narrowed', 'other-source', 'router-web']:
            with self.subTest(failure=failure):
                run, steps, _ = self.run_hub(failure)
                self.assertEqual(run['status'], 'failed')
                self.assertNotEqual(steps.get('Confirm the filter'), 'succeeded')
                if failure != 'router-web':
                    # The listing differs in one rule only: the native assert itself refuses it.
                    self.assertEqual(steps['Confirm the filter'], 'failed')
