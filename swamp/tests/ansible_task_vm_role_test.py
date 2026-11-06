"""The task VM role keeps its contract: phase tags, pins from the recipe only, and the input of a builder behind its host."""
import importlib.util
import json
import os
import re
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


def intent(**extra):
    return {'ssh': {'identity_file': '/role/identity', 'target': {
        'host': '10.252.7.9', 'port': 22, 'user': 'orbit', 'host_keys': ['ssh-ed25519 vm']}},
        'recipe': {'tools': {}}, 'profile': 'base', 'image': False, 'vp_lock': '{}', 'pi': None, 'nfs': None, **extra}


class TaskVmRoleTest(unittest.TestCase):
    def test_every_phase_is_one_tag_and_the_recipe_is_always_resolved(self):
        play = yaml.safe_load((ANSIBLE / 'task-vm-role.yml').read_text())[0]
        self.assertEqual(play['hosts'], 'task_vm')
        self.assertEqual({task['name']: task['tags'] for task in play['tasks']},
                         {'Resolve the recipe': ['always'], 'Software': ['software'], 'Tools': ['tools'], 'Check': ['check']})

    def test_the_files_name_no_version(self):
        for path in [ANSIBLE / 'task-vm-role.yml', *sorted((ANSIBLE / 'tasks').glob('task-vm-*.yml'))]:
            text = path.read_text()
            self.assertIsNone(re.search(r'\b\d+\.\d+\.\d+\b', text), f'{path.name} names a version; the recipe owns it')
            self.assertIsNone(re.search(r'\w==\d', text), f'{path.name} pins a package; the recipe owns it')

    def test_a_task_vm_is_reached_directly(self):
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            (folder / 'intent.json').write_text(json.dumps(intent(nfs={'checkout': '/home/orbit/c', 'subnet': '10.232.2.0/24', 'interface': 'nested'})))
            builder.build(folder, 'task_vm.converge')
            host = json.loads((folder / 'inventory.yml').read_text())['all']['children']['task_vm']['hosts']['vm']
            self.assertEqual(host['ansible_host'], '10.252.7.9')
            self.assertEqual(host['ansible_ssh_private_key_file'], '/role/identity')
            self.assertNotIn('ansible_ssh_common_args', host)
            self.assertEqual((folder / 'known_hosts').read_text(), '10.252.7.9 ssh-ed25519 vm\n')
            self.assertIn('"kind": "task_vm.converge"', (folder / 'vars.yml').read_text())
            for name in ['inventory.yml', 'vars.yml', 'known_hosts']:
                self.assertEqual((folder / name).stat().st_mode & 0o777, 0o600)

    def test_a_builder_is_reached_through_its_host_with_both_pinned_keys(self):
        jump = {'identity_file': '/role/identity', 'target': {'host': '192.0.2.5', 'port': 2222, 'user': 'nckrtl', 'host_keys': ['ssh-ed25519 host']}}
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            (folder / 'intent.json').write_text(json.dumps(intent(jump=jump)))
            builder.build(folder, 'task_vm.image_build')
            host = json.loads((folder / 'inventory.yml').read_text())['all']['children']['task_vm']['hosts']['vm']
            arguments = host['ansible_ssh_common_args']
            self.assertIn('ProxyCommand=', arguments)
            self.assertIn('nckrtl@192.0.2.5', arguments)
            self.assertIn('StrictHostKeyChecking=yes', arguments)
            self.assertIn(f'UserKnownHostsFile={folder}/known_hosts', arguments)
            self.assertEqual((folder / 'known_hosts').read_text(), '10.252.7.9 ssh-ed25519 vm\n[192.0.2.5]:2222 ssh-ed25519 host\n')

    def test_a_guest_of_an_incus_host_takes_its_address_and_key_from_the_run(self):
        jump = {'identity_file': '/role/identity', 'target': {'host': '192.0.2.5', 'port': 22, 'user': 'nckrtl', 'host_keys': ['ssh-ed25519 host']}}
        placeholder = intent(jump=jump)
        placeholder['ssh']['target'] = {'host': '', 'user': 'orbit', 'port': 22, 'host_keys': []}
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            (folder / 'intent.json').write_text(json.dumps(placeholder))
            with mock.patch.dict(os.environ, {'ORBIT_TARGET_HOST': '10.251.78.50\n', 'ORBIT_TARGET_KEY': 'ssh-ed25519 guest root@guest\n'}):
                builder.build(folder, 'task_vm.image_build')
            host = json.loads((folder / 'inventory.yml').read_text())['all']['children']['task_vm']['hosts']['vm']
            self.assertEqual(host['ansible_host'], '10.251.78.50')
            self.assertEqual((folder / 'known_hosts').read_text(), '10.251.78.50 ssh-ed25519 guest root@guest\n192.0.2.5 ssh-ed25519 host\n')


if __name__ == '__main__':
    unittest.main()
