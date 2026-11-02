"""Disposable native roots with the unmodified official SSH binary test seam."""
import json
import os
import shutil
import sys
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

KEY = 'A' * 43 + '='
SSH = {'identity_file': '/fixture/key', 'target': {'host': '10.44.0.9', 'user': 'orbit', 'port': 22,
       'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB']}}


class NetworkNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/network-command')
        fleet = self.repo / 'extensions/behaviors/operations/fleet'
        fleet.mkdir()
        shutil.copy(ROOT / 'extensions/behaviors/operations/fleet/contract.ts', fleet)
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.audit = self.repo / 'audit.jsonl'
        fake = self.repo / 'fake-ssh'
        fake.write_text('#!' + sys.executable + '\n' + '''import json,os,re,sys
from pathlib import Path
if any(x in sys.argv for x in ['-O','-N','-MNf','-M']):raise SystemExit(0)
body=sys.stdin.read()
m=re.findall(r'main\\((\\w+)\\)',body)
action=m[-1] if m else sys.argv[-1]
with Path(os.environ['NETWORK_AUDIT']).open('a') as f:f.write(json.dumps({'action':action,'argv':sys.argv[1:]})+'\\n')
if os.environ.get('NETWORK_FAIL') and os.environ['NETWORK_FAIL'] in action:raise SystemExit(17)
key='A'*43+'='
if action=='inspect':
 drift = bool(os.environ.get('NETWORK_DRIFT')) and sum(json.loads(x)['action']=='inspect' for x in Path(os.environ['NETWORK_AUDIT']).read_text().splitlines())==1
 print(json.dumps({'state':('eth0\\n10.44.0.1\\nold' if drift else 'orbit\\n10.44.0.1\\norbit'),'hooks':'PostUp = resolvectl dns %i 10.44.0.1; resolvectl domain %i \\\\~orbit',
 'dns':'Link 2 (orbit): 10.44.0.1','domains':'Link 2 (orbit): ~orbit','default_route':'Link 2 (orbit): no',
 'uplink':True,'old_link':('eth0' if drift else 'orbit'),'public_key':key}))
elif action=='prepare':print(json.dumps({'public_key':key}))
else:print('{}')
''')
        fake.chmod(0o755)
        self.native_env = {'NETWORK_AUDIT': str(self.audit), 'NETWORK_FAIL': '', 'NETWORK_DRIFT': ''}
        for name in ['operation-fleet-peer-dns', 'operation-node-network-converge']:
            path = self.repo / ('workflows/workflow-' + name + '.yaml')
            path.write_text((ROOT / ('workflows/workflow-' + name + '.yaml')).read_text())
            self.cli('workflow', 'validate', name)
            w = yaml.safe_load(path.read_text())
            self.assertEqual(w.pop('labels'), {'role': 'swamp'})
            for job in w['jobs']:
                for step in job['steps']:
                    self.assertEqual(step.pop('labels'), {'role': 'swamp'})
                    self.assertNotIn('labels', step)
                    if step['task'].get('behaviorType') == '@swamp/ssh':
                        step['task']['globalArgs']['sshBinary'] = str(fake)
            path.write_text(yaml.safe_dump(w, sort_keys=False))
        self.policy = {'server': '10.44.0.1', 'dns_server': '10.44.0.1', 'public_key': KEY, 'domains': ['orbit']}
        self.peer = {'server_public_key': 'B' * 43 + '=', 'endpoint': 'vpn.example.test:51820', 'address': '10.44.0.9/24',
                     'subnet': '10.44.0.0/24', 'dns_server': '10.44.0.1', 'domains': ['orbit'], 'operator_dns': None, 'retain': True}

    def run_root(self, network, success=True):
        name = 'operation-node-network-converge' if network else 'operation-fleet-peer-dns'
        intent = {'ssh': SSH, 'hub_ssh': SSH, 'hub_id': 1, 'existing_key': KEY, 'peer': self.peer,
                  'other_public_keys': [], 'trust_argv': ['ufw', 'allow', 'in', 'on', 'orbit']} if network else {'ssh': SSH, **self.policy}
        run = self.cli('workflow', 'run', name, '--input', json.dumps({'operation_id': 17, 'node_id': 9,
                       'kind': 'node.network_converge' if network else 'fleet.peer_dns', 'intent': intent,
                       'execution_secrets': {}}), success=success)
        history = json.loads(self.cli('workflow', 'history', 'get', json.loads(run.stdout)['id']).stdout)
        evidence = os.environ.get('NETWORK_NATIVE_EVIDENCE')
        if evidence:
            Path(evidence).mkdir(parents=True, exist_ok=True)
            Path(evidence, history['id'] + '.json').write_text(json.dumps(history, indent=2))
        return history

    def test_dns_matching_policy_skips_mutation_and_records_typed_result(self):
        h = self.run_root(False)
        self.assertEqual(h['status'], 'succeeded')
        out = json.loads(h['jobs'][0]['steps'][-1]['outputs']['stdout'])
        self.assertTrue(out['uplink'])
        actions = [json.loads(x)['action'] for x in self.audit.read_text().splitlines()]
        self.assertEqual(actions, ['inspect', 'inspect'])

    def test_peer_precedes_hub_then_peer_publication_without_gateway_callbacks(self):
        h = self.run_root(True)
        self.assertEqual(h['status'], 'succeeded')
        out = json.loads(h['jobs'][0]['steps'][-1]['outputs']['stdout'])
        self.assertEqual(out, {'network_proven': True, 'public_key': KEY, 'retained': True})
        actions = [json.loads(x)['action'] for x in self.audit.read_text().splitlines()]
        self.assertEqual(actions[:3], ['prepare', 'publish', 'configure'])
        self.assertNotIn('gateway', self.audit.read_text())

    def test_hub_ssh_failure_never_configures_peer_or_publishes_success(self):
        self.native_env['NETWORK_FAIL'] = 'publish'
        h = self.run_root(True, success=False)
        self.assertEqual(h['status'], 'failed')
        actions = [json.loads(x)['action'] for x in self.audit.read_text().splitlines()]
        self.assertEqual(actions, ['prepare', 'publish'])

    def test_dns_drift_uses_separate_persistence_and_resolver_actions(self):
        self.native_env['NETWORK_DRIFT'] = '1'
        h = self.run_root(False)
        self.assertEqual(h['status'], 'succeeded')
        actions = [json.loads(x)['action'] for x in self.audit.read_text().splitlines()]
        self.assertEqual(actions[0:2], ['inspect', 'persist'])
        self.assertEqual(actions[-1], 'inspect')
        self.assertEqual(sum('resolvectl' in x for x in actions), 6)
        self.assertEqual(sum('networkctl' in x for x in actions), 1)

    def test_native_resume_does_not_repeat_completed_key_preparation(self):
        self.native_env['NETWORK_FAIL'] = 'publish'
        h = self.run_root(True, success=False)
        self.native_env['NETWORK_FAIL'] = ''
        self.cli('workflow', 'resume', 'operation-node-network-converge', '--run', h['id'])
        resumed = json.loads(self.cli('workflow', 'history', 'get', h['id']).stdout)
        self.assertEqual(resumed['status'], 'succeeded')
        actions = [json.loads(x)['action'] for x in self.audit.read_text().splitlines()]
        self.assertEqual(actions.count('prepare'), 1)
        self.assertEqual(actions.count('publish'), 2)


if __name__ == '__main__':
    unittest.main()
