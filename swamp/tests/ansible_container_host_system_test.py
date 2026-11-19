"""Run the Incus installation guard through Ansible against real, private dpkg databases."""
import copy
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import yaml

SYSTEM = Path(__file__).resolve().parents[1] / 'ansible/tasks/incus-install.yml'


class ContainerHostPackageTest(unittest.TestCase):
    def probe(self, status, package_name='incus-base', others=(), required=None):
        """Keep the shipped query and guard; record installation at the package mutation boundary."""
        source = {task['name']: task for task in yaml.safe_load(SYSTEM.read_text())}
        read = copy.deepcopy(source['Read the installed Incus package'])
        install = copy.deepcopy(source['Install Incus from the recipe'])
        command = shutil.which('ansible-playbook') or str(Path(sys.executable).parent / 'ansible-playbook')
        with tempfile.TemporaryDirectory(prefix='incus-package-status-') as folder:
            root = Path(folder)
            db = root / 'dpkg'
            db.mkdir()
            rows = ([(package_name, status)] if status else []) + list(others)
            (db / 'status').write_text(''.join(f'Package: {name}\nStatus: {state}\nArchitecture: amd64\nVersion: 1.0\n'
                                               'Description: isolated package status fixture\n\n' for name, state in rows))
            abbrev = subprocess.run(['dpkg-query', '--admindir', str(db), '--show', '--showformat', '${db:Status-Abbrev}', package_name],
                                    check=False, capture_output=True, text=True, timeout=5).stdout
            argv = read['ansible.builtin.command']['argv']
            read['ansible.builtin.command']['argv'] = [argv[0], '--admindir', str(db), *argv[1:]]
            marker = root / 'installed'
            # No package operation reaches the real machine. The production block's condition decides whether this runs.
            install['block'] = [{'name': 'Record the package installation', 'ansible.builtin.copy': {
                'dest': str(marker), 'content': 'installed', 'mode': '0600'}}]
            play = root / 'probe.yml'
            play.write_text(yaml.safe_dump([{'name': 'Check the package state', 'hosts': 'localhost', 'gather_facts': False,
                                            'vars': {'container_host': {'incus': {'packages': []}},
                                                     **({'incus_install_package': required} if required else {})},
                                            'tasks': [read, install]}]))
            result = subprocess.run([command, '-i', 'localhost,', '-c', 'local', str(play)],
                                    check=False, capture_output=True, text=True, timeout=30,
                                    env={**os.environ, 'ANSIBLE_STDOUT_CALLBACK': 'default',
                                         'ANSIBLE_CALLBACKS_ENABLED': 'default', 'ANSIBLE_COLLECTIONS_PATH': folder})
            return result, marker.exists(), abbrev

    def test_held_and_unheld_installed_packages_are_not_reinstalled(self):
        for selection, abbrev in [('hold', 'hi '), ('install', 'ii ')]:
            with self.subTest(selection=selection):
                result, installed, observed = self.probe(f'{selection} ok installed')
                self.assertEqual(observed, abbrev)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertFalse(installed, 'An installed package must not be reinstalled because of its selection')
                self.assertIn('changed=0', result.stdout)

    def test_config_files_left_after_removal_do_not_count_as_installed(self):
        result, installed, abbrev = self.probe('deinstall ok config-files')
        self.assertEqual(abbrev, 'rc ')
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertTrue(installed)

    def test_an_incus_without_the_metapackage_is_not_reinstalled(self):
        # Node 4 (release 2159): incus-base and incus-client of a newer build of the line, no `incus`. The pinned set would downgrade
        # them, which apt refuses with rc 100.
        result, installed, _abbrev = self.probe('install ok installed', others=[('incus-client', 'install ok installed')])
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertFalse(installed, 'A running Incus without the metapackage must not get the pinned set')

    def test_the_metapackage_alone_does_not_count_as_incus(self):
        result, installed, _abbrev = self.probe(None, others=[('incus', 'install ok installed')])
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertTrue(installed)

    def test_a_caller_that_needs_the_metapackage_gets_the_set(self):
        # The Gateway release host checks the version of all three packages after the install.
        result, installed, _abbrev = self.probe(None, package_name='incus', required='incus',
                                                others=[('incus-base', 'install ok installed'), ('incus-client', 'install ok installed')])
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertTrue(installed)

    def test_an_absent_package_is_installed(self):
        result, installed, _abbrev = self.probe(None)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertTrue(installed)


if __name__ == '__main__':
    unittest.main()
