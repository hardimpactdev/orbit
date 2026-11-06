"""The task VM role initialises Incus once: a pool, a root disk and, on a task VM, the nested network from the nested intent."""
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
  "storage list") cat "$FAKE/pools" ;;
  "network list") cat "$FAKE/networks" ;;
  "admin init") cat > "$FAKE/preseed" ;;
  "list nested-") ;;
  *) echo "unexpected incus call: $*" >&2; exit 1 ;;
esac
"""
NESTED = {'subnet': '10.232.2.0/24', 'interface': 'nested'}


class TaskVmIncusTest(unittest.TestCase):
    def test_the_role_includes_it_in_the_tools_phase(self):
        tools = yaml.safe_load((ANSIBLE / 'tasks/task-vm-tools.yml').read_text())
        names = [task['name'] for task in tools]
        self.assertNotIn('Serve the checkout', names)
        self.assertEqual(tools[names.index('Initialise Incus')]['ansible.builtin.import_tasks'], 'task-vm-incus.yml')

    def run_task(self, nested, pools='', networks=''):
        folder = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, folder, True)
        fake = folder / 'bin'
        fake.mkdir()
        (fake / 'incus').write_text(FAKE_INCUS)
        (fake / 'incus').chmod(stat.S_IRWXU)
        (folder / 'pools').write_text(pools)
        (folder / 'networks').write_text(networks)
        play = [{'hosts': 'localhost', 'gather_facts': False, 'vars': {'intent': {'nested': nested}},
                 'tasks': [{'ansible.builtin.import_tasks': str(ANSIBLE / 'tasks/task-vm-incus.yml')}]}]
        (folder / 'play.yml').write_text(yaml.safe_dump(play))
        environment = {**os.environ, 'PATH': f'{fake}:{os.environ["PATH"]}', 'FAKE': str(folder),
                       'ANSIBLE_HOME': str(folder / 'home'), 'ANSIBLE_LOCAL_TEMP': str(folder / 'tmp')}
        subprocess.run(['ansible-playbook', '-i', 'localhost,', '-c', 'local', str(folder / 'play.yml')],
                       check=True, capture_output=True, env=environment)
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
    def test_an_image_gets_a_pool_and_no_network(self):
        preseed = self.run_task(None)
        self.assertEqual(preseed['storage_pools'], [{'name': 'default', 'driver': 'dir'}])
        self.assertNotIn('networks', preseed)


if __name__ == '__main__':
    unittest.main()
