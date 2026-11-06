"""A Node of a task VM's nested fleet reads every apt package through the VM's package cache; every other Node reads the archive."""
import json
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
ANSIBLE = ROOT / 'swamp/ansible'
PROXY = 'http://10.232.2.1:3142'
DROP_IN = '90orbit-package-cache'


def names(tasks):
    return [task['name'] for task in tasks]


class AptProxyTest(unittest.TestCase):
    def test_every_apt_run_of_a_nested_node_comes_after_the_drop_in(self):
        prerequisites = yaml.safe_load((ANSIBLE / 'tasks/role-prerequisites.yml').read_text())
        self.assertEqual(prerequisites[0]['ansible.builtin.import_tasks'], 'apt-proxy.yml')
        self.assertEqual(prerequisites[0]['vars'], {'apt_proxy': '{{ intent.apt_proxy | default(none) }}'})
        exporter = yaml.safe_load((ANSIBLE / 'tasks/exporter.yml').read_text())
        self.assertEqual(exporter[0]['ansible.builtin.import_tasks'], 'apt-proxy.yml')
        self.assertEqual(exporter[0]['vars'], {'apt_proxy': '{{ exporter.apt_proxy | default(none) }}'})
        machine = yaml.safe_load((ANSIBLE / 'operation-node-add.yml').read_text())[1]
        tasks = machine['tasks']
        self.assertEqual(tasks[names(tasks).index('Point apt at the package cache')]['ansible.builtin.import_tasks'], 'tasks/apt-proxy.yml')
        self.assertLess(names(tasks).index('Point apt at the package cache'), names(tasks).index('Install the base packages'))

    def test_the_drop_in_is_scoped_to_http_and_leaves_https_direct(self):
        tasks = yaml.safe_load((ANSIBLE / 'tasks/apt-proxy.yml').read_text())
        copy = next(task for task in tasks if task['name'] == 'Point apt at the package cache')['ansible.builtin.copy']
        self.assertEqual(copy['dest'], "{{ apt_conf_directory | default('/etc/apt/apt.conf.d') }}/" + DROP_IN)
        self.assertEqual(copy['content'].splitlines(), [
            'Acquire::http::Proxy "{{ apt_proxy }}";', 'Acquire::http::Pipeline-Depth "0";', 'Acquire::https::Proxy "DIRECT";'])

    def run_task_file(self, extra, *flags, stale=False):
        folder = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, folder, True)
        (folder / 'conf').mkdir()
        if stale:
            (folder / 'conf' / DROP_IN).write_text('stale\n')
        play = [{'hosts': 'localhost', 'gather_facts': False,
                 'vars': {'apt_conf_directory': str(folder / 'conf'), 'intent': {'apt_proxy': None}},
                 'tasks': [{'ansible.builtin.import_tasks': str(ANSIBLE / 'tasks/apt-proxy.yml'), 'vars': {'apt_proxy': '{{ intent.apt_proxy | default(none) }}'}}]}]
        (folder / 'play.yml').write_text(yaml.safe_dump(play))
        environment = {**os.environ, 'ANSIBLE_HOME': str(folder / 'home'), 'ANSIBLE_LOCAL_TEMP': str(folder / 'tmp'), 'ANSIBLE_NOCOLOR': '1'}
        done = subprocess.run(['ansible-playbook', '-i', 'localhost,', '-c', 'local', *flags, *extra, str(folder / 'play.yml')],
                              check=True, capture_output=True, text=True, env=environment)
        return folder / 'conf' / DROP_IN, done.stdout

    @unittest.skipUnless(shutil.which('ansible-playbook'), 'ansible-playbook is not installed')
    def test_a_nested_node_gets_the_drop_in(self):
        # Check mode with a diff shows the file the play would write without the root account that its owner needs.
        _path, output = self.run_task_file(['-e', json.dumps({'intent': {'apt_proxy': PROXY}})], '--check', '--diff')
        for line in (f'+Acquire::http::Proxy "{PROXY}";', '+Acquire::http::Pipeline-Depth "0";', '+Acquire::https::Proxy "DIRECT";'):
            self.assertIn(line, output)
        self.assertIn('changed=1', output)

    @unittest.skipUnless(shutil.which('ansible-playbook'), 'ansible-playbook is not installed')
    def test_every_other_node_gets_none_and_loses_a_stale_one(self):
        path, output = self.run_task_file([])
        self.assertFalse(path.exists())
        self.assertIn('changed=0', output)
        path, output = self.run_task_file([], stale=True)
        self.assertFalse(path.exists())
        self.assertIn('changed=1', output)


if __name__ == '__main__':
    unittest.main()
