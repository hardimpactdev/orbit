"""The shipped host profile must pass its shipped probe guard before Incus starts it."""
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

ROOT = Path(__file__).resolve().parents[1]
ANSIBLE = ROOT / 'ansible'


def task(tasks, name):
    matches = [row for row in tasks if row.get('name') == name]
    if len(matches) != 1:
        raise AssertionError(f'Expected one shipped task named {name!r}, found {len(matches)}')
    return copy.deepcopy(matches[0])


class HostProbeContractTest(unittest.TestCase):
    def test_the_shipped_host_profile_passes_the_probe_guard_before_start(self):
        host = yaml.safe_load((ANSIBLE / 'tasks/instance-host.yml').read_text())
        caller = task(host, 'Prepare the Instance bridge, project and profile')
        prepare = yaml.safe_load((ANSIBLE / 'tasks' / caller['ansible.builtin.include_tasks']).read_text())
        probe = task(prepare, 'Prove that a copy is a clone')
        # Stop after the real guard/start boundary; this proof does not benchmark ZFS writes.
        start = task(probe['block'], 'Start and stop the clone probe copy once')
        probe['block'] = probe['block'][:probe['block'].index(start) + 1]
        profiles = [task(prepare, name) for name in ('Read the container profile',
                    'Refuse incompatible container profile devices', 'Converge the container profile')]
        with tempfile.TemporaryDirectory(prefix='orbit-host-probe-') as scratch:
            folder = Path(scratch)
            (folder / 'bin').mkdir()
            (folder / 'tasks').mkdir()
            for file in ('instance_network.py', 'instance_nested.py', 'tasks/instance-network-nic-check.yml'):
                shutil.copy(ANSIBLE / file, folder / file)
            stub = ROOT / 'tests/fixtures/instance-host-probe-incus.py'
            incus = folder / 'bin/incus'
            incus.write_text(f'#!{sys.executable}\n' + stub.read_text())
            incus.chmod(0o700)
            variables = {**caller['vars'], 'instance_project': 'orbit-instances-nested',
                         'instance_pool_read': {'stdout': '{"driver":"zfs"}'},
                         'container_image_socket': str(folder / 'unused.socket'),
                         'instances': {'project': 'orbit-instances', 'nested': {'project': 'orbit-instances-nested'},
                                       'incus': 'incus', 'ids': 215172736,
                                       'network': 'orbitinst0', 'nic_acl': 'orbitinst0-proxy', 'pool': 'fixture-pool',
                                       'address': '192.0.2.1/24', 'stock': 'fixture-image',
                                       'profile_config': {'environment.PATH': '/opt/orbit/runtime/current/bin:/usr/bin:/bin'},
                                       'folders': {'runtime': {'path': '/opt/orbit/runtime'},
                                                   'agent': {'path': '/opt/orbit/agent'}}}}
            (folder / 'tasks/probe-contract.yml').write_text(yaml.safe_dump([*profiles, probe], sort_keys=False))
            play = [{'hosts': 'localhost', 'gather_facts': False, 'connection': 'local',
                     'vars': variables, 'tasks': [{'ansible.builtin.include_tasks': 'tasks/probe-contract.yml'}]}]
            (folder / 'play.yml').write_text(yaml.safe_dump(play, sort_keys=False))
            environment = {**os.environ, 'PATH': f'{folder / "bin"}{os.pathsep}{os.environ["PATH"]}',
                           'PROBE_FIXTURE': scratch, 'ANSIBLE_NOCOLOR': '1',
                           'ANSIBLE_CONFIG': str(folder / 'ansible.cfg')}
            (folder / 'ansible.cfg').write_text('[defaults]\ninterpreter_python=auto_silent\n')
            result = subprocess.run([sys.executable, str(ROOT / 'tests/_support/fixture_supervisor.py'),
                                     '15', str(folder / 'supervisor.json'),
                                     'ansible-playbook', '-i', 'localhost,', 'play.yml'], cwd=folder,
                                    env=environment, capture_output=True, text=True, timeout=20, check=False)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            state = json.loads((folder / 'state.json').read_text())
            profile = state['profile']
            self.assertNotIn('size', profile['devices']['root'], 'This is the host profile, not a sized Instance intent')
            self.assertEqual(profile['devices']['root']['pool'], 'fixture-pool')
            self.assertEqual(profile['config']['environment.PATH'], variables['instances']['profile_config']['environment.PATH'])
            self.assertEqual(state['containers'], {}, 'The shipped probe cleanup must run')
            calls = state['calls']
            volume = ['query', '--', '/1.0/storage-pools/fixture-pool/volumes/container/orbit-clone-probe-copy?project=orbit-instances-nested']
            start = ['--project', 'orbit-instances-nested', 'start', 'orbit-clone-probe-copy']
            self.assertEqual(calls.count(volume), 1, 'The nested guard must execute, not silently use the plain NIC checker')
            self.assertEqual(calls.count(start), 1)
            self.assertLess(calls.index(volume), calls.index(start), 'Guard the inherited profile before starting the probe')


if __name__ == '__main__':
    unittest.main()
