"""Refresh runs real base-scoped CLI tasks before snapshots; failure never promotes stale Nodes."""
import getpass
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
COMMAND = shutil.which('ansible-playbook') or str(Path(sys.executable).parent / 'ansible-playbook')


@unittest.skipUnless(Path(COMMAND).exists(), 'ansible-playbook is not installed')
class FleetBaseTest(unittest.TestCase):
    def run_refresh(self, *, fail=0, direct=False, nodes=None):
        with tempfile.TemporaryDirectory() as temporary:
            folder = Path(temporary)
            (folder / 'bin').mkdir()
            (folder / 'stamps').mkdir()
            (folder / 'stamps/grants-snapshot').write_text('current')
            nodes = nodes if nodes is not None else [
                {'id': 4, 'name': 'app-prod'}, {'id': 2, 'name': 'operator'},
                {'id': 1, 'name': 'gateway'}, {'id': 3, 'name': 'app-dev'},
            ]
            (folder / 'nodes.json').write_text(json.dumps({'nodes': nodes, 'request_id': 'fixture'}))
            (folder / 'bin/php').write_text('#!' + sys.executable + '\n' + r'''
import json, os, sys
from pathlib import Path
folder = Path(os.environ['FIXTURE'])
args = sys.argv[1:]
assert os.environ['ORBIT_HOME'] == str(folder / '.orbit')
assert args[0] == str(folder / 'apps/cli/orbit')
if args[1:] == ['node:list', '--json']:
    print((folder / 'nodes.json').read_text())
else:
    assert args[1] == 'node:converge' and args[3:] == ['--base', '--json'], args
    with (folder / 'events').open('a') as out:
        out.write('base:' + args[2] + '\n')
    failed = args[2] == os.environ['FAIL_NODE']
    print(json.dumps({'id': 123, 'status': 'failed' if failed else 'succeeded'}))
    sys.exit(1 if failed else 0)
''')
            (folder / 'bin/incus').write_text('#!/bin/sh\nset -eu\n'
                                              'test "$1 $2 $3" = "snapshot create --reuse"\n'
                                              'echo "snapshot:$4" >> "$FIXTURE/events"\n')
            for tool in (folder / 'bin').iterdir():
                tool.chmod(0o755)
            role = yaml.safe_load((ANSIBLE / 'task-vm-role.yml').read_text())
            converge = next(task for task in role[0]['tasks'] if task['name'] == 'Converge nested Node bases for the image')
            converge['ansible.builtin.include_tasks']['file'] = str(ANSIBLE / 'tasks/task-vm-fleet-base.yml')
            variables = {
                'orbit_user': getpass.getuser(), 'orbit_home': str(folder),
                'intent': {'direct': direct, 'checkout': {'path': str(folder)}, 'fleet': {'guests': {
                    'gateway': 'nested-gateway', 'app-dev': 'nested-app-dev', 'app-prod': 'nested-app-prod'}}},
                'fleet_new': [], 'checkout_current': ['code'], 'converge_due': {'operator': False, 'tokens': False},
                'fleet_grants': {'changed': False}, 'fleet_grants_snapshot_digest': 'current',
                'fleet_grants_snapshot_stamp': str(folder / 'stamps/grants-snapshot'),
                'converge_stamps': {'grants-snapshot': 'current'},
            }
            play = [{'hosts': 'localhost', 'gather_facts': False, 'vars': variables, 'tasks': [converge]},
                    {'hosts': 'localhost', 'gather_facts': False, 'vars': variables, 'tasks': role[1]['tasks']}]
            (folder / 'play.yml').write_text(json.dumps(play))
            env = {**os.environ, 'PATH': str(folder / 'bin') + ':' + os.environ['PATH'], 'FIXTURE': str(folder),
                   'FAIL_NODE': str(fail), 'ANSIBLE_CONFIG': str(ANSIBLE / 'ansible.cfg'), 'ANSIBLE_STDOUT_CALLBACK': 'default'}
            result = subprocess.run([COMMAND, '--tags', 'fleet', '-i', 'localhost,', '-c', 'local', '-e', 'ansible_become=false',
                                     '-e', 'ansible_python_interpreter=' + sys.executable, str(folder / 'play.yml')],
                                    capture_output=True, text=True, timeout=60, env=env, check=False)
            events = (folder / 'events').read_text().splitlines() if (folder / 'events').exists() else []
            return result, events

    def test_refresh_converges_all_nodes_in_order_before_all_snapshots_even_when_code_is_current(self):
        result, events = self.run_refresh()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(events, ['base:1', 'base:3', 'base:4',
                                  'snapshot:nested-gateway', 'snapshot:nested-app-dev', 'snapshot:nested-app-prod'])

    def test_failed_node_stops_later_nodes_and_all_snapshots(self):
        result, events = self.run_refresh(fail=3)
        self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(events, ['base:1', 'base:3'])

    def test_direct_task_start_does_not_converge_or_snapshot(self):
        result, events = self.run_refresh(direct=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(events, [])

    def test_incomplete_fleet_fails_before_convergence(self):
        result, events = self.run_refresh(nodes=[{'id': 1, 'name': 'gateway'}, {'id': 4, 'name': 'app-prod'}])
        self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(events, [])


if __name__ == '__main__':
    unittest.main()
