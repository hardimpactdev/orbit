"""Run the shipped apt installs against a fake dpkg: an installed package reads no index and installs nothing, a missing one does both."""
import copy
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import yaml

ANSIBLE = Path(__file__).resolve().parents[1] / 'ansible'
TASKS = ANSIBLE / 'tasks'

# The fake prints what dpkg-query --show prints for each named package it holds and fails like it on the others.
DPKG_QUERY = r'''#!/usr/bin/env python3
import json, os, sys
held = json.loads(os.environ['FAKE_DPKG'])
names = sys.argv[sys.argv.index('--') + 1:]
missing = False
for name in names:
    if name in held:
        version, status = held[name]
        sys.stdout.write(f'{name}\t{version}\t{status}\n')
    else:
        sys.stderr.write(f'dpkg-query: no packages found matching {name}\n')
        missing = True
sys.exit(1 if missing else 0)
'''


def task(path, name):
    found = [t for t in yaml.safe_load(path.read_text()) if t.get('name') == name]
    assert len(found) == 1, (path, name)
    return copy.deepcopy(found[0])


class AptMissingTest(unittest.TestCase):
    def run_install(self, finder, install, held, variables=None):
        """Run the shipped finder and install task, with the apt module swapped for a record of its arguments."""
        command = shutil.which('ansible-playbook') or str(Path(sys.executable).parent / 'ansible-playbook')
        probe = copy.deepcopy(install)
        probe['ansible.builtin.debug'] = {'msg': {'apt': probe.pop('ansible.builtin.apt')}}
        probe.pop('environment', None)
        with tempfile.TemporaryDirectory(prefix='apt-missing-') as folder:
            folder = Path(folder)
            shutil.copy(TASKS / 'apt-missing.yml', folder / 'apt-missing.yml')
            fake = folder / 'bin/dpkg-query'
            fake.parent.mkdir()
            fake.write_text(DPKG_QUERY)
            fake.chmod(0o755)
            finder = copy.deepcopy(finder)
            finder.pop('when', None)
            finder['ansible.builtin.import_tasks'] = str(folder / 'apt-missing.yml')
            playbook = folder / 'probe.yml'
            playbook.write_text(yaml.safe_dump([{
                'name': 'Install what misses', 'hosts': 'localhost', 'gather_facts': False, 'vars': variables or {},
                'tasks': [finder, probe],
            }]))
            result = subprocess.run(
                [command, '-i', 'localhost,', '-c', 'local', '-v', str(playbook)], check=False, capture_output=True, text=True,
                timeout=60, env={**os.environ, 'PATH': f'{fake.parent}:{os.environ["PATH"]}', 'FAKE_DPKG': json.dumps(held),
                                 'ANSIBLE_STDOUT_CALLBACK': 'default', 'ANSIBLE_CALLBACKS_ENABLED': 'default',
                                 'ANSIBLE_COLLECTIONS_PATH': str(folder)})
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            return result.stdout

    def squid(self, held):
        path = TASKS / 'instance-egress.yml'
        return self.run_install(task(path, 'Find Squid'), task(path, 'Install Squid'), held)

    def test_an_installed_package_reads_no_index_and_changes_nothing(self):
        output = self.squid({'squid': ['6.13-0ubuntu0.24.04.3', 'ii ']})
        self.assertRegex(output, r'TASK \[Install Squid\][^\n]*\n[^\n]*skipping')
        self.assertIn('changed=0', output)

    def test_a_held_package_counts_as_installed(self):
        output = self.squid({'squid': ['6.13', 'hi ']})
        self.assertRegex(output, r'TASK \[Install Squid\][^\n]*\n[^\n]*skipping')

    def test_a_missing_package_reads_the_index_and_installs(self):
        output = self.squid({})
        self.assertNotRegex(output, r'TASK \[Install Squid\][^\n]*\n[^\n]*skipping')
        self.assertRegex(output, r'"update_cache": true')
        self.assertRegex(output, r'"name": "squid"')

    def test_removed_with_its_config_left_is_missing(self):
        output = self.squid({'squid': ['6.13', 'rc ']})
        self.assertRegex(output, r'"update_cache": true')

    def test_only_the_wrong_pin_or_a_missing_package_reads_the_index(self):
        path = TASKS / 'node-update-policy.yml'
        finder, install = task(path, 'Find the update tools'), task(path, 'Install the update tools')
        held = {'unattended-upgrades': ['2.9.1', 'ii '], 'needrestart': ['3.6', 'ii ']}
        pinned = {'update_policy': {'packages': ['unattended-upgrades=2.9.1', 'needrestart']}}
        output = self.run_install(finder, install, held, pinned)
        self.assertIn('changed=0', output)
        self.assertRegex(output, r'TASK \[Install the update tools\][^\n]*\n[^\n]*skipping')
        wrong = {'update_policy': {'packages': ['unattended-upgrades=2.10', 'needrestart']}}
        self.assertRegex(self.run_install(finder, install, held, wrong), r'"update_cache": true')

    def test_every_index_read_of_an_install_waits_for_a_missing_package(self):
        """An apt install that reads the index runs only when its finder names a missing package, or under a guard of its own."""
        # These read the index only on their own path to an install: Incus missing, or a tool that misses its pin.
        guarded = {('incus-install.yml', 'Read the package index'), ('node-converge-apt-config.yml', 'Read the package indexes')}
        seen = []
        for path in [*ANSIBLE.glob('*.yml'), *TASKS.glob('*.yml')]:
            pending = list(yaml.safe_load(path.read_text()) or [])
            while pending:
                item = pending.pop()
                if not isinstance(item, dict):
                    continue
                for key in ('block', 'rescue', 'always', 'tasks'):
                    pending.extend(item.get(key) or [])
                apt = item.get('ansible.builtin.apt')
                if isinstance(apt, dict) and apt.get('update_cache'):
                    seen.append((path.name, item['name']))
                    if (path.name, item['name']) not in guarded:
                        self.assertIn('apt_missing | length > 0', str(item.get('when')), f'{path.name}: {item["name"]}')
        self.assertTrue({('instance-egress.yml', 'Install Squid'), ('instance-fleet-node.yml', 'Install the btrfs tools on the fleet Node'),
                         ('node-update-policy.yml', 'Install the update tools'), ('role-prerequisites.yml', 'Install prerequisites'),
                         ('operation-node-add.yml', 'Install the base packages')} <= set(seen), seen)


if __name__ == '__main__':
    unittest.main()
