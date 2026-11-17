"""Run hosted prerequisite setup against private commands, never the host's apt."""
import json
import os
from pathlib import Path
import shutil
import signal
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import Mock, call, patch

ROOT = Path(__file__).resolve().parents[1]
HELPER = ROOT / 'bin/ci-runner-prerequisites'


class RunnerPrerequisitesTest(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory(prefix='ci-prerequisites-test-')
        self.addCleanup(self.scratch.cleanup)
        self.root = Path(self.scratch.name)
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        for command in ('bash', 'chmod', 'mkdir', 'mktemp', 'rm'):
            (self.bin / command).symlink_to(shutil.which(command))
        (self.bin / 'python3').symlink_to(sys.executable)
        self.log = self.root / 'commands.jsonl'
        self.env = dict(os.environ, PATH=str(self.bin), TMPDIR=str(self.root),
                        FIXTURE_BIN=str(self.bin), FIXTURE_LOG=str(self.log))
        self.command('sudo', '''
import json, os, sys
with open(os.environ['FIXTURE_LOG'], 'a') as log:
    log.write(json.dumps({'sudo': sys.argv[1:]}) + '\\n')
assert sys.argv[1] == '-n'
os.execvp(sys.argv[2], sys.argv[2:])
''')
        self.command('apt-get', '''
import json, os, pathlib, sys
args = sys.argv[1:]
source = pathlib.Path(next(a.split('=', 1)[1] for a in args if a.startswith('Dir::Etc::sourcelist=')))
mirror = 'fallback' if 'https://mirrors.edge.kernel.org/' in source.read_text() else 'primary'
operation = 'install' if 'install' in args else 'update'
with open(os.environ['FIXTURE_LOG'], 'a') as log:
    log.write(json.dumps({'mirror': mirror, 'operation': operation, 'args': args,
                         'source': source.read_text()}) + '\\n')
mode = os.environ.get('FIXTURE_' + mirror.upper(), 'ok')
if mode == 'partial' and operation == 'update':
    # Apt normally returns 0 for partial updates unless strict error mode is set.
    sys.exit(100 if 'APT::Update::Error-Mode=any' in args else 0)
if mode == 'fail' or (mode == 'install-fail' and operation == 'install'):
    sys.exit(100)
if mode == 'restart-hooks' and operation == 'install' and os.environ.get('NEEDRESTART_SUSPEND') != '1':
    # The disposable runner must not spend its bounded install restarting unrelated services.
    sys.exit(100)
if operation == 'install' and mode != 'no-tools':
    for name in (['valkey-server', 'valkey-cli'] if args[-1] == 'valkey-server' else ['nft']):
        path = pathlib.Path(os.environ['FIXTURE_BIN']) / name
        path.write_text('#!/bin/bash\\necho fixture-version\\n')
        path.chmod(0o755)
''')

    def command(self, name, body):
        path = self.bin / name
        path.write_text(f'#!{sys.executable}\n' + body)
        path.chmod(0o755)

    def run_helper(self, package='nftables', **modes):
        self.env.update({f'FIXTURE_{key.upper()}': value for key, value in modes.items()})
        started = time.monotonic()
        result = subprocess.run([str(HELPER), package], env=self.env, capture_output=True,
                                text=True, timeout=40, check=False)
        self.elapsed = time.monotonic() - started
        self.calls = [json.loads(line) for line in self.log.read_text().splitlines()] if self.log.exists() else []
        self.apt = [call for call in self.calls if 'operation' in call]
        self.assertEqual(list(self.root.glob('orbit-ci-apt.*')), [], result.stderr)
        return result

    def test_installed_tools_skip_all_privileged_setup(self):
        self.command('nft', "print('fixture-version')\n")
        result = self.run_helper()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.calls, [])
        print(f'nftables present: {self.elapsed:.3f}s')

    def test_missing_tools_are_installed_and_verified(self):
        for package, names in (('nftables', ('nft',)), ('valkey-server', ('valkey-server', 'valkey-cli'))):
            with self.subTest(package=package):
                self.log.unlink(missing_ok=True)
                result = self.run_helper(package)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual([(c['mirror'], c['operation']) for c in self.apt],
                                 [('primary', 'update'), ('primary', 'install')])
                self.assertIn(f'Verified {package}', result.stdout)
                for name in names:
                    self.assertTrue((self.bin / name).is_file())
                print(f'{package} missing, mocked install: {self.elapsed:.3f}s')
        for call in self.apt:
            for option in ('Dir::Etc::sourceparts=-', 'Acquire::Retries=0', 'Acquire::Languages=none',
                           'Acquire::http::Timeout=3', 'Acquire::https::Timeout=3',
                           'APT::Update::Error-Mode=any', 'DPkg::Lock::Timeout=0'):
                self.assertIn(option, call['args'])
            self.assertIn('[signed-by=/usr/share/keyrings/ubuntu-archive-keyring.gpg]', call['source'])
            self.assertIn(' resolute main\n', call['source'])
        self.assertIn('--no-install-recommends', self.apt[-1]['args'])
        self.assertIn('--yes', self.apt[-1]['args'])

    def test_a_broken_nft_requires_install(self):
        self.command('nft', 'raise SystemExit(1)\n')
        result = self.run_helper()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('Verified nftables', result.stdout)

    def test_disposable_runner_install_suspends_unrelated_service_restart_hooks(self):
        result = self.run_helper('valkey-server', primary='restart-hooks', fallback='restart-hooks')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual([(c['mirror'], c['operation']) for c in self.apt],
                         [('primary', 'update'), ('primary', 'install')])

    def test_update_and_install_failures_try_the_known_fallback(self):
        for mode in ('fail', 'partial', 'install-fail'):
            with self.subTest(mode=mode):
                (self.bin / 'nft').unlink(missing_ok=True)
                self.log.unlink(missing_ok=True)
                result = self.run_helper(primary=mode)
                self.assertEqual(result.returncode, 0, result.stderr)
                primary = [c['operation'] for c in self.apt if c['mirror'] == 'primary']
                self.assertEqual(primary, ['update', 'install'] if mode == 'install-fail' else ['update'])
                self.assertEqual([(c['mirror'], c['operation']) for c in self.apt][-2:],
                                 [('fallback', 'update'), ('fallback', 'install')])
                deadlines = [c['sudo'][:4] for c in self.calls if c.get('sudo', [None, None])[1] == 'python3']
                self.assertEqual(deadlines, [['-n', 'python3', '-', '10'], ['-n', 'python3', '-', '20']])
                self.assertIn('https://mirrors.edge.kernel.org/ubuntu', self.apt[-1]['source'])
                self.assertIn('[signed-by=/usr/share/keyrings/ubuntu-archive-keyring.gpg]', self.apt[-1]['source'])
                # Partial primary indexes cannot satisfy fallback's update or install.
                lists = [next(a for a in c['args'] if a.startswith('Dir::State::lists=')) for c in self.apt]
                self.assertNotEqual(lists[0], lists[-1])
                print(f'primary {mode}, mocked fallback: {self.elapsed:.3f}s')

    def test_all_mirror_failures_and_missing_post_install_tools_fail(self):
        for mode in ('fail', 'partial', 'install-fail', 'no-tools'):
            with self.subTest(mode=mode):
                self.log.unlink(missing_ok=True)
                result = self.run_helper(primary=mode, fallback=mode)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn('after both Ubuntu mirrors', result.stderr)
                self.assertEqual({c['mirror'] for c in self.apt}, {'primary', 'fallback'})
                if mode == 'partial':
                    self.assertEqual([c['operation'] for c in self.apt], ['update', 'update'])

    def test_workflow_keeps_both_kernel_cases(self):
        source = (ROOT / '.github/workflows/ci.yml').read_text()
        gateway = source.split('  gateway-static:\n', 1)[1].split('\n  gateway-tests:\n', 1)[0]
        self.assertIn('    runs-on: ubuntu-26.04\n', gateway)
        self.assertIn('    timeout-minutes: 2\n', gateway)
        self.assertIn('../../bin/ci-runner-prerequisites nftables', gateway)
        self.assertNotIn('apt-get', gateway)
        self.assertNotIn('ci-runner-prerequisites acl', source)
        for path in ('tests/Feature/Extensions/AiRouter/AiRouterModelForwarderTest.php',
                     'tests/Feature/Domain/Instances/InstanceEgressTest.php'):
            self.assertIn(f"vendor/bin/pest --group privileged {path} --filter 'on a real kernel'", gateway)
        self.assertEqual(gateway.count("ORBIT_REAL_KERNEL_TESTS: '1'"), 2)

    def test_valkey_requires_both_server_and_client(self):
        self.command('valkey-server', "print('fixture-version')\n")
        result = self.run_helper('valkey-server')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(self.apt, 'A server alone must not bypass installing the missing client')
        self.log.unlink()
        result = self.run_helper('valkey-server')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.calls, [])

    def test_an_unknown_prerequisite_is_refused_without_running_commands(self):
        result = self.run_helper('unknown')
        self.assertEqual(result.returncode, 2)
        self.assertIn('Usage: ci-runner-prerequisites {nftables|valkey-server}', result.stderr)
        self.assertEqual(self.calls, [])


class MirrorDeadlineTest(unittest.TestCase):
    # Execute the shipped guard with a controlled clock and process boundary. The shell tests above
    # own fallback on a nonzero guard exit; no test needs to wait for the mirror budgets to expire.
    guard = HELPER.read_text().split("<<'PYTHON'\n", 1)[1].split('\nPYTHON', 1)[0]

    def test_update_and_install_share_each_mirrors_deadline(self):
        for budget in (10, 20):
            with self.subTest(budget=budget):
                child = Mock(pid=123)
                child.wait.return_value = 0
                with patch.object(sys, 'argv', ['-', str(budget), 'nftables', '-o', 'fixture']), \
                        patch('time.monotonic', side_effect=[100, 100, 104]), \
                        patch('subprocess.Popen', return_value=child) as start:
                    exec(self.guard, {})
                self.assertEqual(start.call_args_list, [
                    call(['apt-get', '-o', 'fixture', 'update'], start_new_session=True),
                    call(['apt-get', '-o', 'fixture', 'install', '--no-install-recommends', '--yes', 'nftables'],
                         start_new_session=True)])
                self.assertEqual(child.wait.call_args_list, [call(timeout=budget), call(timeout=budget - 4)])

    def test_a_stalled_update_or_install_kills_and_reaps_its_group(self):
        for operation in ('update', 'install'):
            with self.subTest(operation=operation):
                child = Mock(pid=123)
                child.wait.side_effect = ([0] if operation == 'install' else []) + [subprocess.TimeoutExpired('apt-get', 10), 0]
                with patch.object(sys, 'argv', ['-', '10', 'nftables']), \
                        patch('time.monotonic', side_effect=[100, 100, 104]), \
                        patch('subprocess.Popen', return_value=child), patch('os.killpg') as kill, \
                        self.assertRaises(SystemExit) as stopped:
                    exec(self.guard, {})
                self.assertEqual(stopped.exception.code, 124)
                kill.assert_called_once_with(123, signal.SIGKILL)
                self.assertEqual(child.wait.call_args_list[-1], call())

    def test_an_expired_budget_never_starts_install(self):
        child = Mock()
        child.wait.return_value = 0
        with patch.object(sys, 'argv', ['-', '10', 'nftables']), \
                patch('time.monotonic', side_effect=[100, 100, 110]), \
                patch('subprocess.Popen', return_value=child) as start, self.assertRaises(SystemExit) as stopped:
            exec(self.guard, {})
        self.assertEqual(stopped.exception.code, 124)
        self.assertEqual(start.call_count, 1)

if __name__ == '__main__':
    unittest.main()
