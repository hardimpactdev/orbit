"""The outer machine of the runner: a task VM (user `orbit`) or an Instance container (user `agent`). The facts about it come from the runner's
own process; the facts about the nested Nodes never move. Every test goes through the public calls of the fleet."""
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

NODES = {'app-prod': {'instance': 'nested-app-prod', 'node_id': 212, 'bridge': '10.0.0.3'}}
TASK_VM_ENV = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': '/home/orbit', 'ORBIT_HOME': '/home/orbit/.orbit', 'COLUMNS': '240'}
INSTANCE_ENV = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': '/home/agent', 'ORBIT_HOME': '/home/agent/.orbit', 'COLUMNS': '240'}
INSTANCE_CHECKOUT = Path('/srv/workspace')


def instance_checkout():
    """The runner as it runs in an Instance container: its files are in /srv/workspace."""
    return patch.multiple(fleet_module, CHECKOUT=INSTANCE_CHECKOUT, ORBIT=['php', str(INSTANCE_CHECKOUT / 'apps/cli/orbit')])


def fresh_node(calls):
    """A `subprocess.run` for a Node that has neither the mount nor the profile; it records every command."""
    def run(argv, **kwargs):
        calls.append(list(argv))
        if argv[:4] == ['incus', 'config', 'device', 'get']:
            return completed(1)
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
        with outer_machine('/home/agent'), instance_checkout(), patch.object(fleet_module.subprocess, 'run', fresh_node(calls)):
            fleet_module.Fleet(NODES).prepare_node_cli('app-prod')
        push = next(call for call in calls if call[:3] == ['incus', 'file', 'push'])
        # Only the source is the home of agent. The target is the CLI home of the managed user `orbit` of the Node.
        self.assertEqual(push, ['incus', 'file', 'push', '/home/agent/.orbit/nested-ca.pem', 'nested-app-prod/home/orbit/.orbit/nested-ca.pem',
                                '--uid', '1000', '--gid', '1000', '--mode', '0644'])
        mount = next(call for call in calls if call[:4] == ['incus', 'config', 'device', 'add'])
        self.assertIn('source=/srv/workspace', mount)
        self.assertIn('path=/home/orbit/orbit', mount)
        profile = next(call for call in calls if 'gateway:add' in call)
        self.assertEqual(profile[:10], ['incus', 'exec', 'nested-app-prod', '--user', '1000', '--cwd', '/home/orbit', '--env', 'HOME=/home/orbit', '--env'])
        self.assertIn('--ca=/home/orbit/.orbit/nested-ca.pem', profile)
        self.assertEqual([call for call in calls if call is not push and any('/home/agent' in word for word in call)], [])


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


if __name__ == '__main__':
    unittest.main()
