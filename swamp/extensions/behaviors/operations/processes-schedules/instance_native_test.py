"""Actual same-root placed member DAGs, using the shared local native harness."""
import hashlib
import json
from pathlib import Path
import sys
import uuid
ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


sys.path.insert(0, str(Path(__file__).parent))
from instance_native_support import InstanceRuntimeCase

class RuntimeMembers(InstanceRuntimeCase):
    def test_restart_members_are_native_steps_in_one_root(self):
        self.state['operation']['input']['members'] = [self.process(7), self.process(11)]
        state = self.execute()
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['operation']['result']['process_ids'], [7, 11])
        self.assertEqual([c['name'] for c in state['commands']], ['process.control', 'process.status'] * 2)
        self.assertTrue(all('/direct-operations/17' in c['url'] for c in state['calls']))
        self.assertEqual({c['url'].split('run_id=')[1] for c in state['calls'] if c['method'] == 'GET'}, {self.run['id']})


    def test_empty_restart_has_no_effect_and_empty_ids(self):
        state = self.execute()
        self.assertEqual(state['commands'], [])
        self.assertEqual(state['operation']['result'], {'ready': True, 'members': [], 'process_ids': []})


    def test_native_member_refusal_fails_parent(self):
        self.state['refuse'] = True
        self.state['operation']['input']['members'] = [self.process(7)]
        state = self.execute(success=False)
        self.assertEqual(state['operation']['status'], 'failed')
        self.assertEqual(state['operation']['result']['error_code'], 'process.fixture_refused')


    def test_vite_restart_prepares_publishes_then_activates_and_verifies(self):
        member = self.process(7)
        control = member['input']
        control['runtime'] = 'systemd'
        control['name'] += '.service'
        control['vite'] = {'instance_id': 8, 'path': '/etc/orbit/vite/app-instance-8.env'}
        marker = '/dev/shm/orbit/hibernation/app-instance-8.awake'
        control['vite_plan'] = {'suspend': {'operation': 'suspend', 'marker': marker}, 'environment': {'operation': 'environment', 'marker': marker, 'application': '/srv/proof', 'path': control['vite']['path'], 'instance_id': 8, 'content': '# Orbit Instance 8\nORBIT_DEV_SERVER_PORT=4000\n'}, 'awake': {'operation': 'awake', 'marker': marker}, 'listener': {'unit': control['name'], 'process_id': 7, 'port': 4000, 'probe': True}}
        self.state['operation']['input']['members'] = [member]
        self.state['operation']['input']['caddy_publication'] = {'node_id': 9, 'user': 'orbit', 'agent_sha256': 'a'*64, 'caddy': {'content': '# public\n', 'version': hashlib.sha256(b'# public\n').hexdigest()[:32], 'listen_addresses': ['10.44.0.9'], 'unserved': False}}
        state = self.execute()
        commands = [c['name'] for c in state['commands']]
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertLess(commands.index('caddy.publish'), commands.index('vite.check-listener'))
        self.assertEqual(commands[:4], ['vite.set-traffic', 'process.control', 'vite.set-traffic', 'caddy.publish'])


    def test_caddy_refusal_prevents_vite_activation(self):
        member = self.process(7)
        member['input']['runtime'] = 'systemd'
        member['input']['name'] += '.service'
        control = member['input']
        control['vite'] = {'instance_id': 8, 'path': '/etc/orbit/vite/app-instance-8.env'}
        marker = '/dev/shm/orbit/hibernation/app-instance-8.awake'
        control['vite_plan'] = {'suspend': {'operation': 'suspend', 'marker': marker}, 'environment': {'operation': 'environment', 'marker': marker, 'application': '/srv/proof', 'path': control['vite']['path'], 'instance_id': 8, 'content': '# Orbit Instance 8\nORBIT_DEV_SERVER_PORT=4000\n'}, 'awake': {'operation': 'awake', 'marker': marker}, 'listener': {'unit': control['name'], 'process_id': 7, 'port': 4000, 'probe': True}}
        self.state['operation']['input']['members'] = [member]
        self.state['operation']['input']['caddy_publication'] = {'node_id': 9, 'user': 'orbit', 'agent_sha256': 'a'*64, 'caddy': {'content': '# public\n', 'version': hashlib.sha256(b'# public\n').hexdigest()[:32], 'listen_addresses': ['10.44.0.9'], 'unserved': False}}
        self.state['refuseCaddy'] = True
        state = self.execute(success=False)
        self.assertEqual(state['operation']['status'], 'failed')
        self.assertNotIn('vite.check-listener', [c['name'] for c in state['commands']])
        self.assertEqual(state['operation']['result']['error_code'], 'process.caddy_failed')


