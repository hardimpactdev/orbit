"""Official209 SSH hub graph with harmless nft, UFW and service fixtures only."""
import json
import sys

import yaml
from native_harness import NativeTestCase


class Hub209Native(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/task-vm-hub', 'operations/node-role-converge-command',
                            'operations/fleet', 'operations/node', 'operations/_lib')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.calls = self.repo / 'calls.jsonl'
        self.failure = self.repo / 'failure'
        binary = self.repo / 'ssh'
        binary.write_text('#!' + sys.executable + '\n' + '''import json,sys
from pathlib import Path
if '-M' in sys.argv or '-O' in sys.argv:raise SystemExit(0)
command=sys.argv[-1];body=sys.stdin.read()
action='file' if 'python3' in command else 'validate' if '--check' in command else 'ufw' if 'ufw' in command else 'activate' if 'restart' in command else 'observe' if '--json' in command else 'service'
with Path(CALLS).open('a') as f:f.write(json.dumps({'action':action,'command':command})+'\\n')
failure=Path(FAILURE).read_text() if Path(FAILURE).exists() else ''
if failure==action or (failure=='router-web' and action=='ufw' and '443' in command):raise SystemExit(1)
if action=='file':print('{"ready":true,"changed":true}')
elif action=='observe':
 def match(protocol,field,right):return {'match':{'op':'==','left':{'payload':{'protocol':protocol,'field':field}},'right':right}}
 rule={'family':'inet','table':'orbit_task_vms','chain':'forward','comment':'orbit:task-vm-control-ssh','expr':[match('ip','saddr','10.44.0.15'),match('ip','daddr',{'prefix':{'addr':'10.44.0.128','len':25}}),match('tcp','dport',22),{'accept':None}]}
 rules=[{'rule':rule}]
 for router in ['10.44.0.7','10.44.0.11']:
  for port in [80,443]:
   if failure=='missing-router' and router=='10.44.0.11' and port==443:continue
   rules.append({'rule':dict(rule,comment='orbit:task-vm-router-web-'+router.replace('.','-')+'-'+str(port),expr=[match('ip','saddr',router),match('ip','daddr',{'prefix':{'addr':'10.44.0.128','len':25}}),match('tcp','dport',port),{'accept':None}])})
 print(json.dumps({'nftables':[] if failure=='missing-rule' else rules}))
'''.replace('CALLS', repr(str(self.calls))).replace('FAILURE', repr(str(self.failure))))
        binary.chmod(0o700)
        identity = self.repo / 'identity'
        identity.write_text('fixture SSH identity')
        from native_harness import ROOT
        flow = yaml.safe_load((ROOT / 'workflows/workflow-operation-task-vm-hub-converge.yaml').read_text())
        for step in flow['jobs'][0]['steps']:
            self.assertEqual(step.pop('labels'), {'role': 'swamp'})
            self.assertNotIn('labels', step)
            if step['task'].get('behaviorType') == '@swamp/ssh':
                step['task']['globalArgs']['sshBinary'] = str(binary)
        (self.repo / 'workflows/workflow-operation-task-vm-hub-converge.yaml').write_text(yaml.safe_dump(flow, sort_keys=False))
        self.inputs = {'operation_id': 7, 'node_id': 1, 'kind': 'task_vm.hub_converge', 'execution_secrets': {}, 'intent': {
            'node_id': 1, 'source': '10.44.0.15', 'range': '10.44.0.128/25',
            'policy': {'routers': ['10.44.0.7', '10.44.0.11']},
            'router_rules': [['ufw', 'route', 'allow', 'in', 'on', 'orbit', 'out', 'on', 'orbit', 'proto', 'tcp', 'from', router, 'to', '10.44.0.128/25', 'port', str(port), 'comment', 'orbit:task-vm-router-web-'+router.replace('.', '-')+'-'+str(port)] for router in ['10.44.0.7', '10.44.0.11'] for port in [80, 443]],
            'ssh': {'identity_file': str(identity), 'target': {'host': 'fixture.invalid', 'user': 'orbit', 'port': 22, 'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}},
            'nft': '# Managed by Orbit (task VMs).\ntable inet orbit_task_vms {}\n',
            'unit': '[Unit]\nDescription=Orbit task VM filter on the WireGuard hub\n',
            'control_rule': ['ufw', 'route', 'allow', 'from', '10.44.0.15', 'to', '10.44.0.128/25', 'port', '22', 'proto', 'tcp']}}

    def run_hub(self, failure=None):
        self.calls.unlink(missing_ok=True)
        if failure:
            self.failure.write_text(failure)
        self.cli('workflow', 'validate', 'operation-task-vm-hub-converge')
        result = json.loads(self.cli('workflow', 'run', 'operation-task-vm-hub-converge', '--input', json.dumps(self.inputs), success=failure is None).stdout)
        return result, [json.loads(s) for s in self.calls.read_text().splitlines()]

    def test_success_is_pinned209_ssh_and_exact_public_nft_proof(self):
        result, calls = self.run_hub()
        self.assertEqual(result['status'], 'succeeded')
        history = json.loads(self.cli('workflow', 'history', 'get', result['id']).stdout)
        terminal = next(s for j in history['jobs'] for s in j['steps'] if s['name'] == 'result')
        self.assertEqual(json.loads(terminal['outputs']['stdout']), {'ready': True, 'node_id': 1, 'control_ssh_source': '10.44.0.15', 'control_ssh_port': 22, 'router_sources': ['10.44.0.7', '10.44.0.11'], 'router_web_ports': [80, 443]})
        self.assertEqual(calls[0]['action'], 'validate')
        self.assertNotIn('3774', next(s['command'] for s in calls if s['action'] == 'ufw'))
        self.assertFalse(any('swamp' in s['command'] for s in calls))
        rules = [s['command'] for s in calls if s['action'] == 'ufw']
        self.assertEqual(len(rules), 5)
        for source in ['10.44.0.7', '10.44.0.11']:
            for port in [80, 443]:
                self.assertEqual(sum(source in r and str(port) in r for r in rules), 1)

    def test_validation_failure_stops_every_effect(self):
        result, calls = self.run_hub('validate')
        self.assertEqual(result['status'], 'failed')
        self.assertEqual([s['action'] for s in calls], ['validate'])

    def test_activation_or_missing_rule_cannot_report_ready(self):
        for failure in ['activate', 'missing-rule', 'missing-router', 'router-web']:
            with self.subTest(failure=failure):
                result, calls = self.run_hub(failure)
                self.assertEqual(result['status'], 'failed')
                if failure in ['activate', 'router-web']:
                    self.assertNotIn('observe', [s['action'] for s in calls])
