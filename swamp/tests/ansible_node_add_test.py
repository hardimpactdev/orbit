"""Node enrollment keeps its contract: phase tags, pinned host keys, private file custody and the order the Node needs."""
import importlib.util
import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest import mock

import yaml

ROOT = Path(__file__).resolve().parents[2]
ANSIBLE = ROOT / 'swamp/ansible'
spec = importlib.util.spec_from_file_location('build_vars', ANSIBLE / 'build_vars.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)

SSH_KEY = 'ssh-ed25519 observed'
HUB = {'target': {'host': '192.0.2.1', 'port': 22, 'user': 'orbit', 'host_keys': ['ssh-ed25519 hub']}, 'identity_file': '/swamp/identity'}


def intent():
    return {
        'platform': 'linux', 'managed_user': 'orbit', 'address': '10.44.0.9',
        'bootstrap': {'host': '198.51.100.7', 'port': 2222, 'user': 'ubuntu', 'fingerprint': 'SHA256:x'},
        'identity': {'identity_file': '/swamp/identity', 'public_key': 'ssh-ed25519 control'},
        'known_hosts_path': '/home/orbit/.ssh/known_hosts', 'packages': ['ufw'],
        'network': {'hubs': [{'ssh': HUB, 'sync_path': '/s', 'private_key_file': '/k', 'public_key': 'hubkey', 'port': 51820}],
                    'hub_files': [{'ssh': HUB, 'path': '/etc/wireguard/orbit.peers', 'content': ''}, {'ssh': {'target': {'host': '203.0.113.1'}}, 'path': '/other', 'content': 'x'}]},
        'private_dns': [{'node_id': 9, 'ssh': {'identity_file': '/swamp/identity', 'target': {
            'host': '198.51.100.7', 'port': 2222, 'user': 'orbit', 'host_keys': ['@ORBIT_ENROLLMENT_SSH_HOST_KEY@']}}, 'backend': '{{ kept }}'}],
        'roles': [{'kind': 'node.role_add'}], 'cli': {'plan': 1}, 'dns': [{'publication': 1}],
    }


def build(folder):
    (folder / 'intent.json').write_text(json.dumps(intent()))
    (folder / 'secret-agent_secret').write_text('observer secret')
    environment = {'ORBIT_HOST_KEY': SSH_KEY + '\n', 'ORBIT_BOOTSTRAP_AUTHORIZED_KEYS': '/home/ubuntu/.ssh/authorized_keys'}
    with mock.patch.dict(os.environ, environment):
        builder.build(folder, 'node.add')


class NodeAddTest(unittest.TestCase):
    def test_every_host_is_pinned_and_the_private_files_have_their_custody(self):
        previous = os.umask(0o022)
        self.addCleanup(os.umask, previous)
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            build(folder)
            hosts = json.loads((folder / 'inventory.yml').read_text())['all']['hosts']
            self.assertEqual(hosts['node-bootstrap']['ansible_ssh_private_key_file'], str(folder / 'bootstrap-identity'))
            self.assertEqual(hosts['node-control']['ansible_ssh_private_key_file'], '/swamp/identity')
            self.assertEqual((hosts['node-control']['ansible_host'], hosts['node-control']['ansible_port'], hosts['node-control']['ansible_user']), ('198.51.100.7', 2222, 'ubuntu'))
            self.assertEqual((hosts['node-managed']['ansible_host'], hosts['node-managed']['ansible_user']), ('10.44.0.9', 'orbit'))
            self.assertEqual(hosts['hub0']['hub_index'], 0)
            self.assertEqual(hosts['dns0']['dns_index'], 0)
            # The hub keeps its own pin; the Node and its DNS host take the observed key, never the Gateway placeholder.
            self.assertEqual((folder / 'known_hosts').read_text().splitlines(), sorted([
                f'10.44.0.9 {SSH_KEY}', f'[198.51.100.7]:2222 {SSH_KEY}', '192.0.2.1 ssh-ed25519 hub']))
            self.assertFalse((folder / 'secret-agent_secret').exists())
            text = (folder / 'vars.yml').read_text()
            self.assertIn('"agent_secret": "observer secret"', text)
            self.assertIn('!unsafe "{{ kept }}"', text)
            variables = yaml.safe_load(text.replace('!unsafe ', ''))
            self.assertEqual([file['path'] for file in variables['hub_files'][0]], ['/etc/wireguard/orbit.peers'])
            self.assertEqual(set(variables['intent']) & {'roles', 'cli', 'dns'}, set())
            for name in ['inventory.yml', 'vars.yml', 'known_hosts']:
                self.assertEqual((folder / name).stat().st_mode & 0o777, 0o600)

    def test_every_phase_is_one_tag_and_runs_on_the_hosts_it_needs(self):
        plays = yaml.safe_load((ANSIBLE / 'operation-node-add.yml').read_text())
        self.assertEqual([(play['hosts'], play['tags']) for play in plays], [
            ('controller', ['baseline']), ('bootstrap', ['baseline']), ('node', ['tunnel']), ('hubs', ['hub']),
            ('node', ['agent']), ('dns', ['dns']), ('managed_linux', ['check'])])

    def test_the_machine_phase_validates_the_sudo_rule_before_it_replaces_the_old_one(self):
        play = yaml.safe_load((ANSIBLE / 'operation-node-add.yml').read_text())[1]
        tasks = {task['name']: task for task in play['tasks']}
        self.assertEqual(tasks['Publish the sudo rule']['ansible.builtin.copy']['validate'], '/usr/sbin/visudo -cf %s')
        names = [task['name'] for task in play['tasks']]
        # The control key goes in first; the firewall comes up last, after the recovery rule that keeps SSH open.
        self.assertLess(names.index('Authorize the Swamp control key'), names.index('Install the base packages'))
        self.assertLess(names.index('Open public recovery SSH'), names.index('Enable the firewall'))
        self.assertLess(names.index('Create the managed account'), names.index('Write the Orbit keys'))

    def test_the_agent_starts_only_after_its_group_and_unit_exist(self):
        play = yaml.safe_load((ANSIBLE / 'operation-node-add.yml').read_text())[4]
        names = [task['name'] for task in play['tasks']]
        self.assertLess(names.index('Create the observer group'), names.index('Start the Node agent'))
        self.assertLess(names.index('Write the observer unit'), names.index('Start the Node agent'))
        self.assertTrue(next(task for task in play['tasks'] if task['name'] == 'Write the observer secret')['no_log'])

    def test_the_tunnel_key_is_checked_before_any_tunnel_change(self):
        play = yaml.safe_load((ANSIBLE / 'operation-node-add.yml').read_text())[2]
        names = [task['name'] for task in play['tasks']]
        for change in ['Write the tunnel files', 'Start the tunnel', 'Apply the tunnel peers']:
            self.assertLess(names.index('Require a unique tunnel key'), names.index(change))
        self.assertLess(names.index('Create the WireGuard key'), names.index('Require a unique tunnel key'))

    def test_a_hub_node_has_no_hub_peer(self):
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            data = intent()
            data['network'].update(is_hub=True, hubs=[], hub_files=[])
            data['private_dns'] = []
            (folder / 'intent.json').write_text(json.dumps(data))
            (folder / 'secret-agent_secret').write_text('observer secret')
            with mock.patch.dict(os.environ, {'ORBIT_HOST_KEY': SSH_KEY, 'ORBIT_BOOTSTRAP_AUTHORIZED_KEYS': '/root/.ssh/authorized_keys'}):
                builder.build(folder, 'node.add')
            inventory = json.loads((folder / 'inventory.yml').read_text())['all']
            self.assertEqual(inventory['children']['hubs']['hosts'], {})
            self.assertEqual(yaml.safe_load((folder / 'vars.yml').read_text().replace('!unsafe ', ''))['hub_files'], [])

    def test_a_condition_is_a_string_and_host_keys_stay_checked(self):
        def conditions(value):
            if isinstance(value, dict):
                for key, item in value.items():
                    if key in ('when', 'changed_when', 'until'):
                        yield item
                    yield from conditions(item)
            elif isinstance(value, list):
                for item in value:
                    yield from conditions(item)
        for condition in conditions(yaml.safe_load((ANSIBLE / 'operation-node-add.yml').read_text())):
            self.assertIsInstance(condition, (str, bool), condition)
        config = (ANSIBLE / 'ansible.cfg').read_text()
        self.assertIn('host_key_checking = True', config)
        self.assertNotIn('accept-new', config)


if __name__ == '__main__':
    unittest.main()
