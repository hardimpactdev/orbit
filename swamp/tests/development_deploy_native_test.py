"""Actual development DAG on the pinned Swamp: official SSH argv against a scratch checkout, private stdin commands."""
import glob
import hashlib
import json
import os
import subprocess
import sys

import yaml
from native_harness import ROOT, NativeTestCase

DEVELOPMENT_JOBS = ('development-source', 'development-checkout', 'production-release', 'dev-vp-capture', 'dev-vp-workspaces', 'dev-vp-install', 'deploy')
# The empty production keys the Gateway adds to a development run (ProductionDeployInput::placeholder).
PRODUCTION_PLACEHOLDER = {'production': {'instance_id': 0, 'user': '', 'home': '', 'release': '', 'release_path': '', 'commit': None, 'branch': '', 'credential': '',
                                         'step_file': '', 'prepare': [], 'links': [], 'grants': [], 'steps': [], 'before': [], 'after': [], 'cleanup': []},
                          'environment': {'instance_id': 0, 'environment': 'production', 'base': '', 'user': '', 'keys': [], 'owned_keys': [], 'sha256': '', 'testing': None},
                          'process_members': [], 'runtime_definitions': {'restart': []}, 'php_input': None}


class DevelopmentDeployNative(NativeTestCase):
    native_timeout = 180

    def git(self, cwd, *args):
        return subprocess.run(['git', '-C', str(cwd), *args], check=True, capture_output=True, text=True).stdout.strip()

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        # The origin has main with a package that the next commit removes; the checkout is at the first commit.
        origin, self.checkout = self.repo / 'origin', self.repo / 'checkout'
        self.git(self.repo, 'init', '--quiet', '--initial-branch=main', str(origin))
        for key, value in (('user.name', 'proof'), ('user.email', 'proof@example.test')):
            self.git(origin, 'config', key, value)
        (origin / 'packages/old').mkdir(parents=True)
        (origin / 'packages/old/index.js').write_text('old\n')
        (origin / 'README').write_text('one\n')
        self.git(origin, 'add', '.')
        self.git(origin, 'commit', '--quiet', '-m', 'one')
        subprocess.run(['git', 'clone', '--quiet', str(origin), str(self.checkout)], check=True)
        (self.checkout / 'packages/old/node_modules').mkdir()
        (self.checkout / 'packages/old/node_modules/dep.js').write_text('stale\n')
        (self.checkout / '.env').write_text('KEEP=1\n')
        self.git(origin, 'rm', '--quiet', '-r', 'packages')
        (origin / 'README').write_text('two\n')
        self.git(origin, 'commit', '--quiet', '-am', 'two')
        self.target = self.git(origin, 'rev-parse', 'HEAD')
        # The real fetch needs GitHub; the fake ssh fetches from the scratch origin instead and runs every other argv.
        subprocess.run(['git', '-C', str(self.checkout), 'fetch', '--quiet', 'origin'], check=True)
        binary = self.repo / 'fixture-ssh'
        binary.write_text('#!' + sys.executable + '\n' + '''
import os,shlex,shutil,sys
argv=shlex.split(sys.argv[-1])
if 'fetch' in argv:
    sys.exit(0)
if not os.path.exists(argv[0]):
    argv[0]=shutil.which(os.path.basename(argv[0]))
os.execv(argv[0],argv)
''')
        binary.chmod(0o700)
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        identity = self.repo / 'identity'
        identity.write_text('synthetic unused identity')
        user = os.environ.get('USER', 'orbit')
        self.inputs = {'operation_id': 17, 'node_id': 9, 'kind': 'instance.deploy', 'execution_secrets': {}, 'intent': {**PRODUCTION_PLACEHOLDER,
            'instance_id': 4, 'commit': None, 'application': str(self.checkout), 'vp_setup': None, 'account_home': str(self.repo),
            'ssh_execution': {'identity_file': str(identity), 'target': {'name': 'target', 'user': user, 'port': 22, 'host': 'fixture.invalid',
                              'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}},
            'deployment': {'variant': 'development', 'needs_vp': False, 'steps': [], 'node_user': user, 'branch': 'main',
                           'repository': str(origin), 'home': str(self.checkout)}}}
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-instance-deploy-direct.yaml').read_text())
        workflow['jobs'] = [job for job in workflow['jobs'] if job['name'] in DEVELOPMENT_JOBS]
        for job in workflow['jobs']:
            job.pop('labels')
            for step in job['steps']:
                step.pop('labels', None)
                task = step['task']
                if task.get('behaviorType') == '@swamp/ssh':
                    task['globalArgs']['sshBinary'] = str(binary)
        (self.repo / 'workflows/workflow-operation-instance-deploy-direct.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False, width=1000000))

    def command(self, command, required=True):
        index = len(self.inputs['intent']['deployment']['steps'])
        key = f'development-deploy-operation-17-step-{index}'
        self.inputs['intent']['deployment']['steps'].append({'name': f'command-{index}', 'phase': 'before_activation', 'required': required,
            'timeout_seconds': 5, 'command_digest': hashlib.sha256(command.encode()).hexdigest()})
        self.cli('vault', 'put', 'orbit-control-inputs', key, input=command)
        self.inputs['execution_secrets'][f'step_{index}'] = {'vault': 'orbit-control-inputs', 'key': key}

    def run_workflow(self, success=True):
        self.inputs['execution_secrets']['credential'] = {'vault': 'orbit-control-inputs', 'key': 'development-deploy-operation-17-credential'}
        self.cli('vault', 'put', 'orbit-control-inputs', 'development-deploy-operation-17-credential', input='https://x-access-token:unused@github.com')
        reply = self.cli('workflow', 'run', 'operation-instance-deploy-direct', '--stdin', input=json.dumps(self.inputs), success=success)
        # A failed run must fail in a step; a missing CEL key fails the whole run instead.
        self.assertNotIn('Invalid expression', reply.stdout + reply.stderr)
        history = json.loads(self.cli('workflow', 'history', 'get', json.loads(reply.stdout)['id']).stdout)
        self.steps = {s['name']: s for job in history['jobs'] for s in job['steps']}
        return history

    def test_deploys_the_commit_cleans_removed_directories_and_keeps_private_commands_out_of_history(self):
        sentinel = 'native-private-' + os.urandom(12).hex()
        self.secrets = (sentinel,)
        self.command(f'printf %s {sentinel}; printf x >> .git/proof-marker')
        self.command(f'printf %s {sentinel}; exit 3', required=False)
        self.command('printf y >> .git/proof-marker')
        history = self.run_workflow()
        self.assertEqual(history['status'], 'succeeded', json.dumps(self.steps)[:2000])
        self.assertEqual(self.git(self.checkout, 'rev-parse', 'HEAD'), self.target)
        self.assertEqual(self.git(self.checkout, 'symbolic-ref', '--short', 'HEAD'), 'main')
        self.assertEqual((self.checkout / '.git/proof-marker').read_text(), 'xy')
        self.assertFalse((self.checkout / 'packages').exists(), 'the removed package directory and its untracked files went')
        self.assertTrue((self.checkout / '.env').exists(), 'untracked files in kept directories stay')
        self.assertEqual(self.steps['Check the checkout']['status'], 'succeeded')
        self.assert_private_absent(json.dumps(history))

    def test_tracked_changes_stop_before_any_change(self):
        (self.checkout / 'README').write_text('local edit\n')
        before = self.git(self.checkout, 'rev-parse', 'HEAD')
        self.command('printf x >> .git/proof-marker')
        history = self.run_workflow(success=False)
        self.assertEqual(history['status'], 'failed')
        self.assertEqual(self.steps['Check tracked changes']['status'], 'failed')
        self.assertEqual(self.git(self.checkout, 'rev-parse', 'HEAD'), before)
        self.assertFalse((self.checkout / '.git/proof-marker').exists())

    def test_a_failed_required_command_skips_the_rest(self):
        self.command('exit 4')
        self.command('printf x >> .git/proof-marker')
        history = self.run_workflow(success=False)
        self.assertEqual(history['status'], 'failed')
        self.assertFalse((self.checkout / '.git/proof-marker').exists())

    def test_a_development_run_skips_every_production_step(self):
        history = self.run_workflow()
        self.assertEqual(history['status'], 'succeeded', json.dumps(self.steps)[:2000])
        production = [name for name in self.steps if name in ('Write the release credential', 'Remove the release credential', 'Read the release commit', 'Check for foreign keys',
                                                               'Activate release', 'Swap current', 'Refresh PHP cache', 'Read current back', 'Check the deployment')]
        self.assertEqual(len(production), 9)
        self.assertEqual({self.steps[name]['status'] for name in production}, {'skipped'})

    def test_checks_out_when_nothing_needs_cleaning(self):
        (self.checkout / 'packages/old/node_modules/dep.js').unlink()
        (self.checkout / 'packages/old/node_modules').rmdir()
        history = self.run_workflow()
        self.assertEqual(history['status'], 'succeeded', json.dumps(self.steps)[:2000])
        self.assertEqual(self.git(self.checkout, 'rev-parse', 'HEAD'), self.target)

    def test_a_best_effort_timeout_continues_and_a_command_cannot_read_its_own_rest(self):
        self.command('sleep 10', required=False)
        self.inputs['intent']['deployment']['steps'][0]['timeout_seconds'] = 1
        # read consumes stdin: with the script on stdin it would swallow the marker line.
        self.command('read -r line || true\nprintf x >> .git/proof-marker')
        history = self.run_workflow()
        self.assertEqual(history['status'], 'succeeded', json.dumps(self.steps)[:2000])
        self.assertEqual((self.checkout / '.git/proof-marker').read_text(), 'x')
        self.assertEqual(self.steps['Run the deploy steps-0']['outputs']['stdout'].strip(), '124')
        self.assertFalse(glob.glob('/tmp/orbit-git-credential-' + history['id']))

    def test_a_held_checkout_lock_stops_before_the_checkout(self):
        import fcntl
        handle = os.open(self.checkout, os.O_RDONLY)
        self.addCleanup(os.close, handle)
        fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        before = self.git(self.checkout, 'rev-parse', 'HEAD')
        history = self.run_workflow(success=False)
        self.assertEqual(history['status'], 'failed')
        self.assertEqual(self.steps['Check the checkout lock']['status'], 'failed')
        self.assertEqual(self.git(self.checkout, 'rev-parse', 'HEAD'), before)


if __name__ == '__main__':
    import unittest
    unittest.main()
