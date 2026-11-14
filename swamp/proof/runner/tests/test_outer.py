"""The outer machine of the runner: a task VM (user `orbit`) or an Instance container (user `agent`). The facts about it come from the runner's
own process; the facts about the nested Nodes never move. Every test goes through the public calls of the fleet."""
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parent))
import fleet as fleet_module
from fakes import completed, outer_machine

NODES = {'gateway': {'instance': 'nested-gateway'}, 'app-prod': {'instance': 'nested-app-prod', 'node_id': 212, 'bridge': '10.0.0.3'}}
TASK_VM_ENV = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': '/home/orbit', 'ORBIT_HOME': '/home/orbit/.orbit', 'COLUMNS': '240'}
INSTANCE_ENV = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': '/home/agent', 'ORBIT_HOME': '/home/agent/.orbit', 'COLUMNS': '240'}
INSTANCE_CHECKOUT = Path('/srv/workspace')


def instance_checkout():
    """The runner as it runs in an Instance container: its files are in /srv/workspace."""
    return patch.multiple(fleet_module, CHECKOUT=INSTANCE_CHECKOUT, ORBIT=['php', str(INSTANCE_CHECKOUT / 'apps/cli/orbit')])


def fresh_node(calls):
    """A VM lacks the mount; a copy has the mount from base. Both lack the profile."""
    def run(argv, **kwargs):
        calls.append(list(argv))
        if argv[:4] == ['incus', 'config', 'device', 'get'] and argv[-1] == 'shift':
            return completed(0, 'false\n' if fleet_module.CHECKOUT == INSTANCE_CHECKOUT else 'true\n')
        if argv[:4] == ['incus', 'config', 'device', 'get']:
            return completed(0, str(INSTANCE_CHECKOUT)) if fleet_module.CHECKOUT == INSTANCE_CHECKOUT else completed(1)
        if 'getent' in argv:
            return completed(2)
        if argv[-3:-1] == ['-q', '"nested"']:
            return completed(1)
        return completed(0, '{}')
    return run


class TaskVmTest(unittest.TestCase):
    """The runner as the user `orbit` with HOME=/home/orbit: exactly what the constants gave before."""

    def test_the_cli_gets_the_environment_a_task_vm_always_gave_it(self):
        with outer_machine('/home/orbit'), patch.object(fleet_module.subprocess, 'run', return_value=completed(0, '{}')) as run:
            fleet_module.Fleet(NODES).cli(['node:list'])
        self.assertEqual(run.call_args.kwargs['env'], TASK_VM_ENV)

    def test_a_background_cli_and_a_terminal_cli_get_the_same_environment(self):
        with outer_machine('/home/orbit'), patch.object(fleet_module.subprocess, 'Popen') as popen, \
                patch.object(fleet_module.terminal, 'drive', return_value={'rc': 0, 'text': ''}) as drive:
            fleet_module.Fleet(NODES).cli_background(['node:list'])
            fleet_module.Fleet(NODES).cli_tty(['node:list'], [])
        self.assertEqual(popen.call_args.kwargs['env'], TASK_VM_ENV)
        self.assertEqual(drive.call_args.args[2], {**TASK_VM_ENV, 'TERM': 'xterm-256color'})

    def test_the_operation_audit_and_the_node_push_use_the_pinned_ca_of_the_home_of_orbit(self):
        calls = []
        with outer_machine('/home/orbit'), patch.object(fleet_module.subprocess, 'run', fresh_node(calls)):
            fleet_module.Fleet(NODES).operations()
            fleet_module.Fleet(NODES).prepare_node_cli('app-prod')
        self.assertEqual(calls[0][calls[0].index('--cacert') + 1], '/home/orbit/.orbit/nested-ca.pem')
        push = next(call for call in calls if call[:3] == ['incus', 'file', 'push'])
        self.assertEqual(push, ['incus', 'file', 'push', '/home/orbit/.orbit/nested-ca.pem', 'nested-app-prod/home/orbit/.orbit/nested-ca.pem',
                                '--uid', '1000', '--gid', '1000', '--mode', '0644'])


class InstanceTest(unittest.TestCase):
    """The runner as the user `agent` with HOME=/home/agent, from the checkout /srv/workspace."""

    def test_the_cli_gets_the_home_of_the_user_that_runs_the_runner(self):
        with outer_machine('/home/agent'), instance_checkout(), patch.object(fleet_module.subprocess, 'run', return_value=completed(0, '{}')) as run:
            fleet_module.Fleet(NODES).cli(['node:list'])
        self.assertEqual(run.call_args.kwargs['env'], INSTANCE_ENV)
        self.assertEqual(run.call_args.args[0][:2], ['php', '/srv/workspace/apps/cli/orbit'])

    def test_a_background_cli_and_a_terminal_cli_get_the_home_of_agent(self):
        with outer_machine('/home/agent'), patch.object(fleet_module.subprocess, 'Popen') as popen, \
                patch.object(fleet_module.terminal, 'drive', return_value={'rc': 0, 'text': ''}) as drive:
            fleet_module.Fleet(NODES).cli_background(['node:list'])
            fleet_module.Fleet(NODES).cli_tty(['node:list'], [])
        self.assertEqual(popen.call_args.kwargs['env'], INSTANCE_ENV)
        self.assertEqual(drive.call_args.args[2], {**INSTANCE_ENV, 'TERM': 'xterm-256color'})

    def test_the_home_is_the_one_of_the_user_and_not_a_home_that_the_environment_names_after_a_user_change(self):
        with outer_machine('/home/agent'), patch.dict(os.environ, {'HOME': '/root'}), patch.object(fleet_module.subprocess, 'run', return_value=completed(0, '{}')) as run:
            fleet_module.Fleet(NODES).cli(['node:list'])
        self.assertEqual(run.call_args.kwargs['env']['HOME'], '/home/agent')

    def test_an_orbit_home_in_the_environment_does_not_move_the_cli_home_or_the_ca(self):
        with outer_machine('/home/agent'), patch.dict(os.environ, {'ORBIT_HOME': 'relative/elsewhere'}), \
                patch.object(fleet_module.subprocess, 'run', return_value=completed(0, '{}')) as run:
            fleet_module.Fleet(NODES).cli(['node:list'])
            cli_env = run.call_args.kwargs['env']
            fleet_module.Fleet(NODES).operations()
        self.assertEqual((cli_env['HOME'], cli_env['ORBIT_HOME']), ('/home/agent', '/home/agent/.orbit'))
        audit = run.call_args.args[0]
        self.assertEqual(audit[audit.index('--cacert') + 1], '/home/agent/.orbit/nested-ca.pem')

    def test_the_audit_checks_the_nested_gateway_against_the_pinned_ca_of_agent(self):
        calls = []
        with outer_machine('/home/agent'), patch.object(fleet_module.subprocess, 'run', fresh_node(calls)):
            fleet_module.Fleet(NODES).operations()
        self.assertEqual(calls[0][calls[0].index('--cacert') + 1], '/home/agent/.orbit/nested-ca.pem')

    def test_a_fresh_node_gets_the_ca_of_agent_and_the_nested_facts_stay_the_same(self):
        calls = []
        with outer_machine('/home/agent'), instance_checkout(), patch.object(Path, 'stat', return_value=type('Stat', (), {'st_gid': 2760})()), patch.object(fleet_module.subprocess, 'run', fresh_node(calls)):
            fleet_module.Fleet(NODES).prepare_node_cli('app-prod')
        push = next(call for call in calls if call[:3] == ['incus', 'file', 'push'])
        # Only the source is the home of agent. The target is the CLI home of the managed user `orbit` of the Node.
        self.assertEqual(push, ['incus', 'file', 'push', '/home/agent/.orbit/nested-ca.pem', 'nested-app-prod/home/orbit/.orbit/nested-ca.pem',
                                '--uid', '1000', '--gid', '1000', '--mode', '0644'])
        self.assertFalse(any(call[:4] == ['incus', 'config', 'device', 'add'] for call in calls))
        self.assertIn(['incus', 'exec', 'nested-app-prod', '--', 'groupadd', '--gid', '2760', 'work'], calls)
        membership = ['incus', 'exec', 'nested-app-prod', '--', 'usermod', '--append', '--groups', 'work', 'orbit']
        self.assertIn(membership, calls)
        self.assertLess(calls.index(membership), next(i for i, call in enumerate(calls) if 'gateway:add' in call))
        profile = next(call for call in calls if 'gateway:add' in call)
        self.assertEqual(profile[:14], ['incus', 'exec', 'nested-app-prod', '--cwd', '/home/orbit', '--env', 'HOME=/home/orbit', '--env', 'COLUMNS=240', '--', 'runuser', '-u', 'orbit', '--'])
        self.assertIn('--ca=/home/orbit/.orbit/nested-ca.pem', profile)
        self.assertEqual([call for call in calls if call is not push and any('/home/agent' in word for word in call)], [])


class PrepareTest(unittest.TestCase):
    """`prepare` reads the fleet that the preparation process prints. Anything else is an infrastructure failure that names the text."""

    def test_output_that_is_not_the_fleet_is_a_fleet_error_with_its_text(self):
        fleet = {role: {'instance': 'nested-' + role, 'node_id': number, 'bridge': f'10.232.2.{10 + number}'} for number, role in enumerate(('gateway', 'app-dev', 'app-prod'), 1)}
        printed = 'Installing the pinned shape tools into /home/agent/.cache/orbit-shape-gate/venv-0123\n' + json.dumps(fleet) + '\n'
        with patch.object(fleet_module.Fleet, 'must', return_value=completed(out=printed)):
            with self.assertRaises(fleet_module.FleetError) as caught:
                fleet_module.prepare()
        self.assertIn('Installing the pinned shape tools', str(caught.exception))

    def test_the_printed_fleet_is_the_fleet(self):
        with patch.object(fleet_module.Fleet, 'must', return_value=completed(out='{"app-prod": {"instance": "nested-app-prod"}}\n')):
            self.assertEqual(fleet_module.prepare().nodes, {'app-prod': {'instance': 'nested-app-prod'}})


class ControllerTest(unittest.TestCase):
    """`prepare_observers` needs the pinned Ansible tools on the outer machine. A missing tool names its path."""

    def gate(self, tools, tool_path):
        return {'default_tools': lambda: tools, 'SYSTEM_TOOLS': Path('/opt/orbit-shape-gate'), 'venv_name': lambda: 'venv-0123',
                'tool_path': tool_path, 'collections_path': lambda path: path / 'collections-4567'}

    def test_tools_that_cannot_be_built_name_the_pinned_directory(self):
        def build(tools):
            raise PermissionError(13, 'Permission denied', '/home/agent/.cache')
        gate = self.gate(Path('/home/agent/.cache/orbit-shape-gate'), build)
        with patch.object(fleet_module.runpy, 'run_path', return_value=gate):
            with self.assertRaises(fleet_module.FleetError) as caught:
                fleet_module.proof_controller()
        self.assertIn('/opt/orbit-shape-gate/venv-0123', str(caught.exception))
        self.assertIn('/home/agent/.cache/orbit-shape-gate', str(caught.exception))

    def test_the_pinned_directory_survives_the_callers_cut_of_a_long_build_error(self):
        def build(tools):
            raise subprocess.CalledProcessError(1, ['pip', 'install', '--require-hashes', '-r', 'x' * 400], stderr='e' * 400)
        gate = self.gate(Path('/home/agent/.cache/orbit-shape-gate'), build)
        with patch.object(fleet_module.runpy, 'run_path', return_value=gate):
            with self.assertRaises(fleet_module.FleetError) as caught:
                fleet_module.proof_controller()
        # `Fleet.must` keeps the last 200 characters of what the preparation process printed.
        self.assertIn('/opt/orbit-shape-gate/venv-0123', str(caught.exception)[-200:])

    def test_a_missing_program_names_its_path(self):
        with tempfile.TemporaryDirectory() as directory:
            bin_dir = Path(directory)
            (bin_dir / 'ansible-playbook').write_text('#!/bin/sh\n')
            (bin_dir / 'ansible-playbook').chmod(0o755)
            with patch.object(fleet_module.runpy, 'run_path', return_value=self.gate(Path(directory), lambda tools: bin_dir)):
                with self.assertRaises(fleet_module.FleetError) as caught:
                    fleet_module.proof_controller()
        self.assertIn(str(bin_dir / 'ansible-galaxy'), str(caught.exception))

    def test_complete_tools_give_the_controller_and_the_collections_path(self):
        with tempfile.TemporaryDirectory() as directory:
            bin_dir = Path(directory)
            for program in ('ansible-galaxy', 'ansible-playbook'):
                (bin_dir / program).write_text('#!/bin/sh\n')
                (bin_dir / program).chmod(0o755)
            with patch.object(fleet_module.runpy, 'run_path', return_value=self.gate(Path('/opt/orbit-shape-gate'), lambda tools: bin_dir)):
                controller, collections = fleet_module.proof_controller()
        self.assertEqual((controller, collections), (bin_dir, Path('/opt/orbit-shape-gate/collections-4567')))


class CopyCallerMountTest(unittest.TestCase):
    def test_an_unshifted_fleet_with_a_missing_caller_device_requires_refresh_without_remapping_it(self):
        calls = []
        def run(argv, **kwargs):
            calls.append(argv)
            return completed(0, 'false') if argv[-1] == 'shift' else completed(1)
        with instance_checkout(), patch.object(fleet_module.subprocess, 'run', run):
            with self.assertRaisesRegex(fleet_module.FleetError, 'refresh the fleet'):
                fleet_module.Fleet(NODES).prepare_node_cli('app-prod')
        self.assertEqual(len(calls), 2)
        self.assertEqual(calls[0], ['incus', 'config', 'device', 'get', 'nested-gateway', 'checkout', 'shift'])

    def test_a_copy_reuses_its_existing_checkout_group_and_reports_a_failed_group_read(self):
        for rc, output in [(0, 'work:x:2760:orbit\n'), (1, '')]:
            calls = []
            def run(argv, **kwargs):
                calls.append(argv)
                if argv[-1] == 'shift':
                    return completed(out='false')
                if argv[-1] == 'source':
                    return completed(out=str(INSTANCE_CHECKOUT))
                if 'getent' in argv:
                    return completed(rc, output)
                return completed()
            with self.subTest(rc=rc), instance_checkout(), patch.object(Path, 'stat', return_value=type('Stat', (), {'st_gid': 2760})()), patch.object(fleet_module.subprocess, 'run', run):
                if rc:
                    with self.assertRaisesRegex(fleet_module.FleetError, 'cannot read the checkout group'):
                        fleet_module.Fleet(NODES).prepare_node_cli('app-prod')
                else:
                    fleet_module.Fleet(NODES).prepare_node_cli('app-prod')
            self.assertFalse(any('groupadd' in call for call in calls))
            self.assertEqual(any('usermod' in call for call in calls), rc == 0)

    def test_fingerprint_artisan_runs_as_orbit_with_its_groups_not_uid_alone(self):
        fleet = fleet_module.Fleet(NODES)
        counts = dict.fromkeys(fleet_module.FINGERPRINT_KEYS, 0)
        with patch.object(fleet, 'cli', return_value={'rc': 0}), \
                patch.object(fleet_module.subprocess, 'run', return_value=completed(out=json.dumps(counts))) as run:
            result = fleet_module.fingerprint(fleet)
        argv = run.call_args.args[0]
        self.assertEqual(argv[argv.index('--') + 1:][:6], ['runuser', '-u', 'orbit', '--', 'php', '/home/orbit/orbit/apps/gateway/artisan'])
        self.assertNotIn('--user', argv)
        self.assertEqual(result, {'doctor_healthy': True, **counts})


if __name__ == '__main__':
    unittest.main()
