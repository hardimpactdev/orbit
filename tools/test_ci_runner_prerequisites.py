"""Run hosted prerequisite setup against private commands, never the host's apt."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import time
import unittest

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
import json, os, pathlib, signal, sys, time
args = sys.argv[1:]
source = pathlib.Path(next(a.split('=', 1)[1] for a in args if a.startswith('Dir::Etc::sourcelist=')))
mirror = 'fallback' if 'https://archive.ubuntu.com/' in source.read_text() else 'primary'
operation = 'install' if 'install' in args else 'update'
with open(os.environ['FIXTURE_LOG'], 'a') as log:
    log.write(json.dumps({'mirror': mirror, 'operation': operation, 'args': args,
                         'source': source.read_text()}) + '\\n')
mode = os.environ.get('FIXTURE_' + mirror.upper(), 'ok')
if mode == 'install-hang' and operation == 'update':
    time.sleep(1)
if mode == 'hang' or (mode == 'install-hang' and operation == 'install'):
    signal.signal(signal.SIGTERM, signal.SIG_IGN)
    time.sleep(60)
if mode == 'partial' and operation == 'update':
    # Apt normally returns 0 for partial updates unless strict error mode is set.
    sys.exit(100 if 'APT::Update::Error-Mode=any' in args else 0)
if mode == 'fail' or (mode == 'install-fail' and operation == 'install'):
    sys.exit(100)
if operation == 'install' and mode != 'no-tools':
    names = ['nft'] if args[-1] == 'nftables' else ['setfacl', 'getfacl']
    for name in names:
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
        for name in ('nft', 'setfacl', 'getfacl'):
            self.command(name, "print('fixture-version')\n")
        for package in ('nftables', 'acl'):
            with self.subTest(package=package):
                result = self.run_helper(package)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(self.calls, [])
                print(f'{package} present: {self.elapsed:.3f}s')

    def test_missing_tools_are_installed_and_verified(self):
        for package, names in (('nftables', ('nft',)), ('acl', ('setfacl', 'getfacl'))):
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

    def test_one_missing_acl_command_and_a_broken_nft_require_install(self):
        self.command('setfacl', "print('fixture-version')\n")
        self.assertEqual(self.run_helper('acl').returncode, 0)
        self.assertTrue(self.apt)
        self.command('nft', 'raise SystemExit(1)\n')
        result = self.run_helper()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('Verified nftables', result.stdout)

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
                self.assertIn('https://archive.ubuntu.com/ubuntu', self.apt[-1]['source'])
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

    def test_real_deadlines_bound_two_stalled_mirrors(self):
        result = self.run_helper(primary='hang', fallback='hang')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('after both Ubuntu mirrors', result.stderr)
        self.assertEqual([(c['mirror'], c['operation']) for c in self.apt],
                         [('primary', 'update'), ('fallback', 'update')])
        deadlines = [c['sudo'][:4] for c in self.calls if c.get('sudo', [None, None])[1] == 'python3']
        self.assertEqual(deadlines, [['-n', 'python3', '-', '10'], ['-n', 'python3', '-', '20']])
        self.assertLess(self.elapsed, 33)
        print(f'both mirrors stalled, real process guard: {self.elapsed:.3f}s (30s budget plus process overhead)')

    def test_stalled_primary_still_reaches_a_working_fallback(self):
        for mode in ('hang', 'install-hang'):
            with self.subTest(mode=mode):
                (self.bin / 'nft').unlink(missing_ok=True)
                self.log.unlink(missing_ok=True)
                result = self.run_helper(primary=mode)
                self.assertEqual(result.returncode, 0, result.stderr)
                primary = [('primary', 'update')]
                if mode == 'install-hang':
                    primary.append(('primary', 'install'))
                self.assertEqual([(c['mirror'], c['operation']) for c in self.apt],
                                 primary + [('fallback', 'update'), ('fallback', 'install')])
                # The delayed update and stalled install share the same primary budget.
                self.assertLess(self.elapsed, 12)
                print(f'primary {mode}, mocked fallback: {self.elapsed:.3f}s')

    def test_workflow_keeps_both_kernel_cases_and_pi_acl_tests(self):
        source = (ROOT / '.github/workflows/ci.yml').read_text()
        gateway = source.split('  gateway-static:\n', 1)[1].split('\n  gateway-tests:\n', 1)[0]
        pi = source.split('  pi:\n', 1)[1].split('\n  agent:\n', 1)[0]
        for job, package in ((gateway, 'nftables'), (pi, 'acl')):
            self.assertIn('    runs-on: ubuntu-26.04\n', job)
            self.assertIn('    timeout-minutes: 2\n', job)
            self.assertIn(f'../../bin/ci-runner-prerequisites {package}', job)
            self.assertNotIn('apt-get', job)
        for path in ('tests/Feature/Extensions/AiRouter/AiRouterModelForwarderTest.php',
                     'tests/Feature/Domain/Instances/InstanceEgressTest.php'):
            self.assertIn(f"vendor/bin/pest --default-time-limit=5 --group privileged {path} --filter 'on a real kernel'", gateway)
        self.assertEqual(gateway.count("ORBIT_REAL_KERNEL_TESTS: '1'"), 2)
        self.assertIn('sudo -n -u nobody true', pi)
        self.assertIn('run: bun run test', pi)


if __name__ == '__main__':
    unittest.main()
