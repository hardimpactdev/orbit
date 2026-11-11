"""The task VM role initialises Incus once: a pool, a root disk and, on a task VM, the nested network from the nested intent."""
import getpass
import os
import shutil
import stat
import subprocess
import tempfile
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
ANSIBLE = ROOT / 'swamp/ansible'
FAKE_INCUS = """#!/bin/sh
# Lists come from files; an init records its preseed.
case "$1 $2" in
  "list --project") mkdir -p "$HOME/.config/incus"; printf 'default-remote: local\n' > "$HOME/.config/incus/config.yml" ;;
  "storage list") cat "$FAKE/pools" ;;
  "network list") cat "$FAKE/networks" ;;
  "admin init") cat > "$FAKE/preseed" ;;
  "storage create") echo "$*" > "$FAKE/created" ;;
  *) echo "unexpected incus call: $*" >&2; exit 1 ;;
esac
"""
# The seed disk's file system: blkid prints its type and exits 0, or exits 2 for a blank disk.
FAKE_BLKID = """#!/bin/sh
if [ -s "$FAKE/fs" ]; then cat "$FAKE/fs"; exit 0; fi
exit 2
"""
FAKE_TIMEOUT = '#!/bin/sh\nshift\nexec "$@"\n'
NESTED = {'subnet': '10.232.2.0/24', 'interface': 'nested'}


class TaskVmIncusTest(unittest.TestCase):
    def test_the_ssh_users_client_config_is_prepared_before_the_fleet(self):
        tasks = yaml.safe_load((ANSIBLE / 'tasks/task-vm-incus.yml').read_text())
        prepare = next((task for task in tasks if task['name'] == 'Prepare the Incus client config'), None)
        self.assertIsNotNone(prepare, 'Parallel proof restores need the SSH user config prepared during provisioning')
        self.assertTrue(prepare['become'])
        self.assertEqual(prepare['become_user'], '{{ orbit_user }}')
        self.assertEqual(prepare['environment'], {'HOME': '{{ orbit_home }}'})
        self.assertEqual(prepare['ansible.builtin.command'], {
            'argv': ['incus', 'list', '--project', 'default', '--format', 'csv'],
            'creates': '{{ orbit_home }}/.config/incus/config.yml',
        })
        role = yaml.safe_load((ANSIBLE / 'task-vm-role.yml').read_text())[0]['tasks']
        names = [task['name'] for task in role]
        self.assertLess(names.index('Tools'), names.index('Fleet'))
        tools = yaml.safe_load((ANSIBLE / 'tasks/task-vm-tools.yml').read_text())
        self.assertIn('task-vm-incus.yml', [task.get('ansible.builtin.import_tasks') for task in tools])

    def test_the_role_includes_it_in_the_tools_phase(self):
        tools = yaml.safe_load((ANSIBLE / 'tasks/task-vm-tools.yml').read_text())
        names = [task['name'] for task in tools]
        self.assertNotIn('Serve the checkout', names)
        self.assertEqual(tools[names.index('Initialise Incus')]['ansible.builtin.import_tasks'], 'task-vm-incus.yml')

    def run_task(self, nested, pools='', networks='', seed_fs=None, check=True):
        """Runs the task file; `seed_fs` gives the VM a seed disk: '' blank, or the type of its file system."""
        folder = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, folder, True)
        fake = folder / 'bin'
        fake.mkdir()
        for name, text in (('incus', FAKE_INCUS), ('blkid', FAKE_BLKID), ('timeout', FAKE_TIMEOUT)):
            (fake / name).write_text(text)
            (fake / name).chmod(stat.S_IRWXU)
        (folder / 'pools').write_text(pools)
        (folder / 'networks').write_text(networks)
        extra = []
        if seed_fs is not None:
            (folder / 'disk').write_text('')
            (folder / 'fs').write_text(seed_fs)
            extra = ['-e', f'incus_seed_disk={folder / "disk"}']
        play = [{'hosts': 'localhost', 'gather_facts': False,
                 'vars': {'intent': {'nested': nested}, 'orbit_user': getpass.getuser(), 'orbit_home': str(folder / 'client')},
                 'tasks': [{'ansible.builtin.import_tasks': str(ANSIBLE / 'tasks/task-vm-incus.yml')}]}]
        (folder / 'play.yml').write_text(yaml.safe_dump(play))
        environment = {**os.environ, 'PATH': f'{fake}:{os.environ["PATH"]}', 'FAKE': str(folder),
                       'ANSIBLE_HOME': str(folder / 'home'), 'ANSIBLE_LOCAL_TEMP': str(folder / 'tmp')}
        done = subprocess.run(['ansible-playbook', '-i', 'localhost,', '-c', 'local', *extra, str(folder / 'play.yml')],
                              check=check, capture_output=True, text=True, env=environment)
        if check:
            config = folder / 'client/.config/incus/config.yml'
            self.assertEqual(yaml.safe_load(config.read_text()), {'default-remote': 'local'})
            if pools and (nested is None or networks):
                # A current config survives a second converge, with no further changes.
                config.write_text('default-remote: local\naliases: {ls: list}\n')
                again = subprocess.run(['ansible-playbook', '-i', 'localhost,', '-c', 'local', *extra, str(folder / 'play.yml')],
                                       check=True, capture_output=True, text=True, env=environment)
                self.assertIn('changed=0', again.stdout)
                self.assertEqual(yaml.safe_load(config.read_text())['aliases'], {'ls': 'list'})
        if not check:
            created = folder / 'created'
            return done, created.read_text() if created.exists() else None
        preseed = folder / 'preseed'
        return yaml.safe_load(preseed.read_text()) if preseed.exists() else None

    @unittest.skipUnless(shutil.which('ansible-playbook'), 'ansible-playbook is not installed')
    def test_a_fresh_task_vm_gets_the_pool_the_root_disk_and_the_nested_network(self):
        preseed = self.run_task(NESTED)
        self.assertEqual(preseed['storage_pools'], [{'name': 'default', 'driver': 'dir'}])
        self.assertEqual(preseed['profiles'][0]['devices']['root'], {'path': '/', 'pool': 'default', 'type': 'disk'})
        network = preseed['networks'][0]
        self.assertEqual((network['name'], network['type']), ('nested', 'bridge'))
        self.assertEqual(network['config']['ipv4.address'], '10.232.2.1/24')

    @unittest.skipUnless(shutil.which('ansible-playbook'), 'ansible-playbook is not installed')
    def test_an_initialised_task_vm_is_left_alone(self):
        self.assertIsNone(self.run_task(NESTED, pools='default,dir,,,1,CREATED\n', networks='nested,bridge,YES,10.232.2.1/24,,,1,CREATED\n'))

    @unittest.skipUnless(shutil.which('ansible-playbook'), 'ansible-playbook is not installed')
    def test_a_missing_network_is_added_to_an_initialised_incus(self):
        preseed = self.run_task(NESTED, pools='default,dir,,,1,CREATED\n')
        self.assertNotIn('storage_pools', preseed)
        self.assertEqual(preseed['networks'][0]['name'], 'nested')

    @unittest.skipUnless(shutil.which('ansible-playbook'), 'ansible-playbook is not installed')
    def test_a_blank_seed_disk_gets_the_seed_pool(self):
        done, created = self.run_task(NESTED, pools='default,dir,,,1,CREATED\n', networks='nested,bridge,YES\n', seed_fs='', check=False)
        self.assertEqual(done.returncode, 0, done.stdout)
        self.assertTrue(created.startswith('storage create topology-seed btrfs source='), created)

    @unittest.skipUnless(shutil.which('ansible-playbook'), 'ansible-playbook is not installed')
    def test_a_seed_disk_with_a_file_system_the_vm_does_not_know_is_refused_and_not_imported(self):
        done, created = self.run_task(NESTED, pools='default,dir,,,1,CREATED\n', networks='nested,bridge,YES\n', seed_fs='btrfs', check=False)
        self.assertNotEqual(done.returncode, 0)
        self.assertIn('The topology seed disk holds btrfs, but the Incus of this VM knows no pool on it.', done.stdout)
        self.assertIsNone(created)

    @unittest.skipUnless(shutil.which('ansible-playbook'), 'ansible-playbook is not installed')
    def test_a_seed_disk_of_a_known_pool_is_left_alone(self):
        done, created = self.run_task(NESTED, pools='default,dir,,,1,CREATED\ntopology-seed,btrfs,,,1,CREATED\n',
                                      networks='nested,bridge,YES\n', seed_fs='btrfs', check=False)
        self.assertEqual(done.returncode, 0, done.stdout)
        self.assertIsNone(created)

    @unittest.skipUnless(shutil.which('ansible-playbook'), 'ansible-playbook is not installed')
    def test_an_image_gets_a_pool_and_no_network(self):
        preseed = self.run_task(None)
        self.assertEqual(preseed['storage_pools'], [{'name': 'default', 'driver': 'dir'}])
        self.assertNotIn('networks', preseed)


if __name__ == '__main__':
    unittest.main()
