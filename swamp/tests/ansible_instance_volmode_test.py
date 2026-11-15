"""Run the shipped inner-volume volmode task of the Instance host through Ansible against stand-ins for incus and zfs."""
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import yaml

HOST = Path(__file__).resolve().parents[1] / 'ansible/tasks/instance-host.yml'
TASK = 'Make inner-volume copies with their device in dev mode'


class InnerVolumeVolmodeTest(unittest.TestCase):
    def converge(self, volmode='default', nested=True, driver='zfs', runs=1):
        """The shipped task alone; `zfs` keeps the custom dataset's volmode in a file and logs every call."""
        task = next(task for task in yaml.safe_load(HOST.read_text()) if task.get('name') == TASK)
        command = shutil.which('ansible-playbook') or str(Path(sys.executable).parent / 'ansible-playbook')
        with tempfile.TemporaryDirectory(prefix='instance-volmode-') as folder:
            root = Path(folder)
            (root / 'bin').mkdir()
            (root / 'volmode').write_text(volmode)
            log = root / 'calls'
            (root / 'bin/incus').write_text(f'#!/bin/sh\necho "incus $*" >> {log}\n'
                                            '[ "$*" = "storage get orbit-instances zfs.pool_name" ] && echo fast/orbit-instances\n')
            (root / 'bin/zfs').write_text(
                f'#!/bin/sh\necho "zfs $*" >> {log}\n'
                f'[ "$*" = "get -H -o value volmode fast/orbit-instances/custom" ] && exec cat {root / "volmode"}\n'
                f'[ "$*" = "set volmode=dev fast/orbit-instances/custom" ] && exec printf dev > {root / "volmode"}\n'
                'exit 1\n')
            for name in ('incus', 'zfs'):
                (root / 'bin' / name).chmod(0o755)
            instances = {'pool': 'orbit-instances', **({'nested': {'project': 'orbit-instances-nested'}} if nested else {})}
            play = root / 'play.yml'
            play.write_text(yaml.safe_dump([{'name': 'Converge', 'hosts': 'localhost', 'gather_facts': False,
                                            'vars': {'instances': instances,
                                                     'instance_pool_read': {'stdout': json.dumps({'driver': driver})}},
                                            'tasks': [task]}]))
            results = [subprocess.run([command, '-i', 'localhost,', '-c', 'local', str(play)], check=False, capture_output=True,
                                      text=True, timeout=60,
                                      env={**os.environ, 'PATH': str(root / 'bin') + os.pathsep + os.environ['PATH'],
                                           'ANSIBLE_STDOUT_CALLBACK': 'default', 'ANSIBLE_CALLBACKS_ENABLED': 'default'})
                       for _ in range(runs)]
            for result in results:
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            calls = log.read_text().splitlines() if log.exists() else []
            return results, calls, (root / 'volmode').read_text()

    def test_a_nested_zfs_host_sets_dev_on_the_pools_custom_volumes_once(self):
        results, calls, volmode = self.converge(runs=2)
        self.assertEqual(volmode, 'dev')
        self.assertEqual([call for call in calls if call.startswith('zfs set')], ['zfs set volmode=dev fast/orbit-instances/custom'])
        self.assertIn('changed=1', results[0].stdout)
        # The rerun reads dev and changes nothing.
        self.assertIn('changed=0', results[1].stdout)

    def test_a_host_already_in_dev_mode_changes_nothing(self):
        results, calls, volmode = self.converge(volmode='dev')
        self.assertEqual(volmode, 'dev')
        self.assertFalse([call for call in calls if call.startswith('zfs set')])
        self.assertIn('changed=0', results[0].stdout)

    def test_a_host_without_nested_fleets_or_on_a_directory_pool_touches_no_dataset(self):
        for shape in ({'nested': False}, {'driver': 'dir'}):
            with self.subTest(**shape):
                _, calls, volmode = self.converge(**shape)
                self.assertEqual(calls, [])
                self.assertEqual(volmode, 'default')


if __name__ == '__main__':
    unittest.main()
