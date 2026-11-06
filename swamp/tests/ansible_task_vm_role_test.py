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
                         {'Wait for a new server': ['always'], 'Resolve the recipe': ['always'], 'Software': ['software'], 'Tools': ['tools'], 'Check': ['check'], 'Clean': ['clean'], 'Template': ['template']})

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

    def test_every_condition_of_the_role_is_a_string(self):
        # A plain YAML scalar with a colon and a space parses as a mapping, and Ansible refuses it: "Conditional expressions must be strings".
        def conditions(node, where):
            if isinstance(node, dict):
                for key, value in node.items():
                    if key in ('when', 'that', 'failed_when', 'changed_when', 'until'):
                        for item in value if isinstance(value, list) else [value]:
                            self.assertIsInstance(item, (str, bool), f'{where}: a {key} is {type(item).__name__}, not a string')
                    conditions(value, where)
            elif isinstance(node, list):
                for value in node:
                    conditions(value, where)

        for path in [ANSIBLE / 'task-vm-role.yml', *sorted((ANSIBLE / 'tasks').glob('task-vm-*.yml'))]:
            conditions(yaml.safe_load(path.read_text()), path.name)

    def test_every_package_download_retries_with_the_tool_and_no_shell(self):
        def tasks(node):
            if isinstance(node, list):
                for item in node:
                    yield from tasks(item)
            elif isinstance(node, dict):
                if 'name' in node and any(key.startswith('ansible.builtin.') for key in node):
                    yield node
                for value in node.values():
                    yield from tasks(value)

        play = yaml.safe_load((ANSIBLE / 'task-vm-role.yml').read_text())[0]
        self.assertEqual((play['vars']['apt_retries'], play['vars']['apt_delay']), (4, 30))
        self.assertEqual(play['module_defaults']['ansible.builtin.apt']['lock_timeout'], 300)
        found = {}
        for name in ['task-vm-apt-config.yml', 'task-vm-apt.yml', 'task-vm-tools.yml']:
            for task in tasks(yaml.safe_load((ANSIBLE / 'tasks' / name).read_text())):
                for module in ('ansible.builtin.shell', 'ansible.builtin.raw'):
                    self.assertNotIn(module, task, f"{task['name']}: {module} runs apt")
                command = task.get('ansible.builtin.command')
                if 'ansible.builtin.apt' in task or (command is not None and 'apt-get' in str(command)):
                    found[task['name']] = task
        self.assertEqual(
            sorted(found),
            ['Install the Ubuntu packages', 'Install the packages', 'Install the packages that come as files', 'Read the package indexes'],
        )
        for name, task in found.items():
            expected = '{{ apt_index_environment }}' if name == 'Read the package indexes' else '{{ apt_environment }}'
            self.assertEqual(task['environment'], expected, f'{name}: apt must read the snapshot configuration')
        indexes = found['Read the package indexes']['ansible.builtin.apt']
        self.assertIs(indexes['update_cache'], True)
        self.assertEqual((indexes['update_cache_retries'], indexes['update_cache_retry_max_delay']), (12, 30))
        for name in ['Install the Ubuntu packages', 'Install the packages', 'Install the packages that come as files']:
            task = found[name]
            self.assertEqual((task['retries'], task['delay']), ('{{ apt_retries }}', '{{ apt_delay }}'), name)
            self.assertEqual(task['until'], f"{task['register']} is succeeded", name)
        # The flags of the old apt-get commands: no recommends, no removal; the Ubuntu pins are exact, so a downgrade is allowed.
        for name in ['Install the Ubuntu packages', 'Install the packages']:
            apt = found[name]['ansible.builtin.apt']
            self.assertIs(apt['install_recommends'], False, name)
            self.assertIs(apt['fail_on_autoremove'], True, name)
        self.assertIs(found['Install the Ubuntu packages']['ansible.builtin.apt']['allow_downgrade'], True)

    def test_the_wait_for_a_new_server_is_for_an_image_only(self):
        phase = yaml.safe_load((ANSIBLE / 'tasks/task-vm-ready.yml').read_text())
        self.assertEqual([task['when'] for task in phase], ['intent.image'])
        steps = {task['name']: task for task in phase[0]['block']}
        self.assertIn('ansible.builtin.wait_for_connection', steps['Wait until SSH answers'])
        wait = steps['Wait for cloud-init']
        self.assertEqual(wait['ansible.builtin.command']['argv'][:2], ['timeout', '900'])
        self.assertEqual(wait['failed_when'], 'ready_cloud_init.rc not in [0, 2]')

    def test_the_clean_phase_is_for_an_image_only_and_leaves_incus_in_the_image(self):
        phase = yaml.safe_load((ANSIBLE / 'tasks/task-vm-clean.yml').read_text())
        self.assertEqual([task['when'] for task in phase], ['intent.image'])
        text = (ANSIBLE / 'tasks/task-vm-clean.yml').read_text()
        self.assertNotIn('/var/lib/incus', text)
        self.assertIn('orbit:public-ssh-recovery', text)
        argvs = [item for task in phase[0]['block'] for item in (task.get('loop') or []) if isinstance(item, list)]
        tmp = next(argv for argv in argvs if argv[:3] == ['find', '/tmp', '/var/tmp'])
        self.assertIn(['!', '-name', 'ansible_*'], [tmp[i:i + 3] for i in range(tmp.index('-exec'))], 'the clean phase must not delete the module that is running it')

    def test_a_template_builder_is_reached_directly_on_the_address_of_the_run(self):
        placeholder = intent()
        placeholder['ssh']['target'] = {'host': '', 'user': 'orbit', 'port': 22, 'host_keys': []}
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            (folder / 'intent.json').write_text(json.dumps(placeholder))
            with mock.patch.dict(os.environ, {'ORBIT_TARGET_HOST': '203.0.113.7\n', 'ORBIT_TARGET_KEY': 'ssh-ed25519 admitted\n'}):
                builder.build(folder, 'task_vm.upcloud_image_build')
            host = json.loads((folder / 'inventory.yml').read_text())['all']['children']['task_vm']['hosts']['vm']
            self.assertEqual(host['ansible_host'], '203.0.113.7')
            self.assertNotIn('ansible_ssh_common_args', host)
            self.assertEqual((folder / 'known_hosts').read_text(), '203.0.113.7 ssh-ed25519 admitted\n')
            self.assertIn('"kind": "task_vm.upcloud_image_build"', (folder / 'vars.yml').read_text())


if __name__ == '__main__':
    unittest.main()
