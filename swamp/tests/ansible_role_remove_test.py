"""Role removal input and reachability behavior, without an Orbit Node."""
import importlib.util
import json
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('remove_vars', ROOT / 'ansible/build_vars.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


def connection(address):
    return {'identity_file': '/dev/null', 'target': {'host': address, 'port': 1, 'user': 'orbit',
                                                   'host_keys': ['ssh-ed25519 fixture-public-key']}}


class RoleRemovalInputTest(unittest.TestCase):
    def test_groups_recorded_participants_and_keeps_purge_out_of_teardown(self):
        with tempfile.TemporaryDirectory() as temp:
            folder = Path(temp)
            target, peer = connection('127.0.0.1'), connection('127.0.0.2')
            intent = {'ssh': target, 'node_id': 7, 'publications': [], 'prometheus': None,
                      'purge': {'files': ['/srv/reverb'], 'directories': ['/srv/reverb.releases'], 'volumes': []},
                      **{key: [] for key in ('stop_units', 'files', 'directories', 'commands', 'reload_units',
                                            'remove_containers', 'firewall', 'certificates')}}
            intent['files'] = [{'ssh': target, 'path': '/srv/reverb'}, {'ssh': peer, 'path': '/etc/orbit/exporter'}]
            intent['directories'] = [{'ssh': target, 'path': '/srv/reverb.releases'}]
            intent['commands'] = [{'ssh': target, 'command': 'timeout 60 docker volume rm -- retained'}]
            folder.joinpath('intent.json').write_text(json.dumps(intent))
            builder.build_role_remove(folder)
            hosts = json.loads(folder.joinpath('inventory.yml').read_text())['all']['hosts']
            self.assertEqual(hosts['target']['files'], [])
            self.assertEqual(hosts['target']['directories'], [])
            self.assertEqual(hosts['target']['commands'], [])
            self.assertEqual([h['files'][0]['path'] for name, h in hosts.items() if name != 'target'], ['/etc/orbit/exporter'])
            self.assertIn('[127.0.0.2]:1 ssh-ed25519 fixture-public-key', folder.joinpath('known_hosts').read_text())
            self.assertEqual(json.loads(folder.joinpath('intent.json').read_text()), intent)

    @unittest.skipUnless(shutil.which('ansible-playbook'), 'Ansible is not installed')
    def test_completed_target_cleanup_is_unchanged_on_retry(self):
        with tempfile.TemporaryDirectory() as temp:
            folder = Path(temp)
            owned = folder / 'owned.conf'
            owned.write_text('withdrawn role')
            retained = folder / 'retained.conf'
            retained.write_text('another role')
            folder.joinpath('reach.json').write_text('{"reachable": true}')
            duties = {key: [] for key in ('stop_units', 'remove_containers', 'publications', 'files', 'directories',
                                         'certificates', 'reload_units', 'commands', 'firewall')}
            duties.update(files=[{'path': str(owned)}], prometheus=None)
            inventory = {'all': {'children': {'role_node': {'hosts': {'fixture': {
                'ansible_connection': 'local', 'ansible_become': False, **duties}}}}}}
            folder.joinpath('inventory.json').write_text(json.dumps(inventory))
            folder.joinpath('vars.json').write_text(json.dumps({'intent': {}, 'run_folder': temp}))
            for attempt in range(2):
                result = subprocess.run(['timeout', '30', 'ansible-playbook', '-i', str(folder / 'inventory.json'),
                                         '-e', '@' + str(folder / 'vars.json'), '--tags', 'remove',
                                         str(ROOT / 'ansible/operation-node-role-remove.yml')],
                                        check=False, capture_output=True, text=True, env={**os.environ, 'ANSIBLE_STDOUT_CALLBACK': 'default'})
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertFalse(owned.exists())
                self.assertEqual(retained.read_text(), 'another role')
                if attempt == 1:
                    self.assertRegex(result.stdout, r'changed=0\s')

    @unittest.skipUnless(shutil.which('ansible-playbook'), 'Ansible is not installed')
    def test_only_offline_consent_allows_an_unreachable_target(self):
        for offline in (False, True):
            with self.subTest(offline=offline), tempfile.TemporaryDirectory() as temp:
                folder = Path(temp)
                intent = {'offline': offline, 'role': 'database'}
                folder.joinpath('inventory.json').write_text(json.dumps({'all': {'children': {'role_node': {'hosts': {
                    'refused': {'ansible_host': '127.0.0.1', 'ansible_port': 1, 'ansible_user': 'orbit',
                                'ansible_ssh_private_key_file': '/dev/null'}}}}}}))
                folder.joinpath('vars.json').write_text(json.dumps({'intent': intent, 'run_folder': temp}))
                result = subprocess.run(['timeout', '20', 'ansible-playbook', '-i', str(folder / 'inventory.json'),
                                         '-e', '@' + str(folder / 'vars.json'), '--tags', 'reach',
                                         str(ROOT / 'ansible/operation-node-role-remove.yml')],
                                        check=False, capture_output=True, text=True, env={**os.environ, 'ANSIBLE_STDOUT_CALLBACK': 'default',
                                        'ANSIBLE_LOCALHOST_WARNING': 'false', 'ANSIBLE_SSH_ARGS': '-o ConnectTimeout=1'})
                self.assertEqual(result.returncode == 0, offline, result.stdout + result.stderr)
                self.assertEqual(json.loads(folder.joinpath('reach.json').read_text()), {'reachable': False})


if __name__ == '__main__':
    unittest.main()
