"""instance.rollback on pinned Swamp with unmodified official cfgmgmt and SSH; the Node is a scratch directory."""

import json
import os
import pwd
import sys
from pathlib import Path

import yaml

from native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[1]
HOME = '/home/orbit-app-7'


class InstanceRollbackNative(NativeTestCase):
    native_timeout = 180

    def setUp(self):
        super().setUp()
        inventory = json.loads((ROOT / 'extensions/models/upstream_extensions.json').read_text())
        for package in ['@swamp/ssh', '@swamp/cfgmgmt']:
            self.cli('extension', 'pull', f"{package}@{inventory[package]['version']}")
        self.home = self.repo / 'node-home'
        for release in ['r1', 'r2']:
            (self.home / 'releases' / release / 'admin').mkdir(parents=True)
        (self.home / 'current').symlink_to('releases/r2')
        self.state = self.repo / 'node.json'
        self.save(calls=[], fail=[])
        tools = self.repo / 'tools'
        tools.mkdir()
        self.native_env['PATH'] = str(tools) + ':' + os.environ['PATH']
        record = """import json,sys
from pathlib import Path
p=Path(STATE);s=json.loads(p.read_text());s['calls'].append([NAME,*sys.argv[1:]])
fail=bool(s['fail']) and s['fail'][0]==sys.argv[1]
if fail:s['fail'].pop(0)
p.write_text(json.dumps(s));sys.exit(1 if fail else 0)
""".replace('STATE', repr(str(self.state)))
        scripts = {
            # The Node runs each command as given, with the production home in a scratch directory.
            'ssh': """import subprocess,sys
if '-M' in sys.argv or '-O' in sys.argv: sys.exit(0)
sys.exit(subprocess.call(['/bin/sh','-c',sys.argv[-1].replace(HOME,NODE)]))
""".replace('HOME', repr(HOME)).replace('NODE', repr(str(self.home))),
            'sudo': """import subprocess,sys
args=sys.argv[1:];args=args[args.index('--')+1:]
sys.exit(subprocess.call(args))
""",
            'chown': 'pass\n',
            'systemctl': record.replace('NAME', "'systemctl'"),
            'docker': record.replace('NAME', "'docker'"),
        }
        for name, source in scripts.items():
            path = tools / name
            path.write_text('#!' + sys.executable + '\n' + source)
            path.chmod(0o700)
        source = ROOT / 'workflows/workflow-operation-instance-rollback.yaml'
        workflow = yaml.safe_load(source.read_text())
        self.name = workflow['name']
        for job in workflow['jobs']:
            self.assertEqual(job.pop('labels'), {'role': 'swamp'})
            for step in job['steps']:
                self.assertEqual(step.pop('labels'), {'role': 'swamp'})
                task = step['task']
                if task.get('behaviorType') == '@swamp/ssh':
                    task['globalArgs'].update(sshBinary=str(tools / 'ssh'), scpBinary=str(tools / 'ssh'))
        (self.repo / 'workflows' / source.name).write_text(yaml.safe_dump(workflow, sort_keys=False))
        (self.repo / 'key').write_text('fixture key never consumed')
        user = pwd.getpwuid(os.getuid()).pw_name
        self.inputs = {
            'operation_id': 41, 'node_id': 9, 'kind': 'instance.rollback', 'execution_secrets': {},
            'intent': {
                'rollback': {'instance_id': 42, 'release': 'r1', 'user': 'orbit-app-7', 'home': HOME, 'links': [
                    {'path': f'{HOME}/releases/r1/admin/.env', 'target': '../../../env/admin/.env'},
                    {'path': f'{HOME}/current.next', 'target': 'releases/r1'}]},
                'php_input': {'service': 'orbit-orbit-app-7-php8.5-fpm.service'},
                'runtime_definitions': {'restart': [
                    {'process_id': 3, 'runtime': 'systemd', 'name': 'orbit-process-3-horizon.service'},
                    {'process_id': 4, 'runtime': 'docker', 'name': 'orbit-process-4-cache'}]},
                'ssh_execution': {'identity_file': str(self.repo / 'key'), 'target': {
                    'host': 'fixture', 'user': user, 'port': 22,
                    'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ111111111111111111111111111111111111111111']}},
            },
        }

    def save(self, **changes):
        state = json.loads(self.state.read_text()) if self.state.exists() else {}
        state.update(changes)
        self.state.write_text(json.dumps(state))

    def calls(self):
        return json.loads(self.state.read_text())['calls']

    def rollback(self, success=True):
        self.cli('workflow', 'validate', self.name)
        result = self.cli('workflow', 'run', self.name, '--stdin', input=json.dumps(self.inputs), success=success)
        run = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
        return {step['name']: step['status'] for job in run['jobs'] for step in job['steps']}

    def test_selects_the_release_reloads_php_restarts_processes_and_checks(self):
        before = os.lstat(self.home / 'current').st_ino
        steps = self.rollback()

        # current.next was renamed over current: a new link inode under the old name, and no current.next left.
        self.assertEqual(os.readlink(self.home / 'current'), 'releases/r1')
        self.assertNotEqual(os.lstat(self.home / 'current').st_ino, before)
        self.assertFalse(os.path.lexists(self.home / 'current.next'))
        self.assertEqual((steps['Swap current'], steps['Read current back']), ('succeeded', 'succeeded'))
        self.assertEqual(os.readlink(self.home / 'releases/r1/admin/.env'), '../../../env/admin/.env')
        self.assertEqual(self.calls(), [
            ['systemctl', 'reload', 'orbit-orbit-app-7-php8.5-fpm.service'],
            ['systemctl', 'reload-or-restart', 'orbit-process-3-horizon.service'],
            ['docker', 'restart', 'orbit-process-4-cache']])
        self.assertEqual(steps['Check the selection'], 'succeeded')
        # A rerun is safe: the links already match, and the reload and restarts repeat.
        self.rollback()
        self.assertEqual(os.readlink(self.home / 'current'), 'releases/r1')

    def test_no_php_and_no_processes_only_moves_current(self):
        self.inputs['intent']['php_input'] = None
        self.inputs['intent']['runtime_definitions']['restart'] = []
        steps = self.rollback()

        self.assertEqual(os.readlink(self.home / 'current'), 'releases/r1')
        self.assertEqual(self.calls(), [])
        self.assertEqual(steps['Refresh PHP cache'], 'skipped')
        self.assertEqual(steps['Check the selection'], 'succeeded')

    def test_a_failed_restart_keeps_the_rollback_release_selected(self):
        self.save(fail=['reload-or-restart'])
        steps = self.rollback(success=False)

        self.assertEqual(os.readlink(self.home / 'current'), 'releases/r1')
        self.assertEqual(steps['Restart Processes-0'], 'failed')

    def test_check_fails_when_current_does_not_select_the_release(self):
        # A wrong release name is renamed into place, so the live read-back must catch it.
        self.inputs['intent']['rollback']['release'] = 'r3'
        steps = self.rollback(success=False)

        self.assertEqual(steps['Read current back'], 'succeeded')
        self.assertEqual(steps['Check the selection'], 'failed')

    def test_a_removed_release_fails_before_current_moves(self):
        (self.home / 'releases/r1/admin').rmdir()
        (self.home / 'releases/r1').rmdir()
        steps = self.rollback(success=False)

        self.assertEqual(os.readlink(self.home / 'current'), 'releases/r2')
        self.assertFalse(os.path.lexists(self.home / 'current.next'))
        self.assertEqual(self.calls(), [])
        self.assertEqual((steps['Activate release-0'], steps['Activate release-1']), ('failed', 'skipped'))
        self.assertEqual(steps['Swap current'], 'skipped')
        self.assertNotIn('Check the selection', [name for name, status in steps.items() if status == 'succeeded'])
