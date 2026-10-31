"""Filesystem/process contract tests; substitute only the host systemd boundary."""
import json
import os
import pathlib
import signal
import subprocess
import tempfile
import time
import unittest

SOURCE = pathlib.Path(__file__).with_name('script_program.ts').read_text().split('String.raw`', 1)[1].rsplit('`;', 1)[0]
ADAPTER = r'''
import sys, json, os, signal
value = json.load(sys.stdin)
namespace = {'__name__': 'orbit_script'}
exec(value['program'], namespace)
def command(root, path, args, unit, tmp, ssr):
    os.environ['TMPDIR'] = tmp
    os.environ.pop('ORBIT_SWAMP_RUN_TOKEN', None)
    os.environ.pop('COMPOSER_AUTH', None)
    return [os.path.join(root, path), *args]
def stop():
    process = namespace['PROCESS']
    if process is not None:
        try: os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError: pass
namespace['command'] = command
namespace['stop_unit'] = stop
try: result = namespace['run'](value['input'])
except namespace['Refusal'] as error: result = {'error_code': str(error)}
print(json.dumps(result))
'''

class ScriptNativeTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = pathlib.Path(self.directory.name)
        self.git('init', '-q')
        self.git('config', 'user.email', 'test@example.test')
        self.git('config', 'user.name', 'Test')
        self.path = self.root / 'scripts' / 'run'
        self.path.parent.mkdir()
        self.install('#!/usr/bin/env python3\nimport sys,json,pathlib\npathlib.Path("args").write_text(json.dumps(sys.argv[1:]))\nprint("done")\n')
        self.git('add', '.')
        self.git('commit', '-qm', 'fixture')
        self.input = {'checkout_path': str(self.root), 'script': 'scripts/run', 'args': [], 'execution_key': 'operation-8', 'ssr_port': None}

    def git(self, *args):
        return subprocess.check_output(['git', '-C', str(self.root), *args], stderr=subprocess.DEVNULL).decode().strip()

    def install(self, text):
        self.path.write_text(text)
        self.path.chmod(0o755)

    def invoke(self):
        result = subprocess.run(['python3', '-c', ADAPTER], input=json.dumps({'program': SOURCE, 'input': self.input}), text=True, capture_output=True, timeout=10, check=True)
        return json.loads(result.stdout)

    def test_literal_arguments_and_replay_after_result_loss(self):
        self.input['args'] = ['$(touch injected); & hello', ' spaces ', '']
        result = self.invoke()
        self.assertEqual(result['outcome'], 'passed')
        self.assertEqual(json.loads((self.root / 'args').read_text()), self.input['args'])
        self.assertTrue(result['changed'])
        self.assertFalse((self.root / 'injected').exists())
        (self.root / 'args').unlink()
        self.assertEqual(self.invoke(), result)
        self.assertFalse((self.root / 'args').exists())

    def test_refuses_untracked_changed_nonexecutable_and_symlinked_entrypoints(self):
        for mode in ['changed', 'untracked', 'mode', 'symlink']:
            with self.subTest(mode=mode):
                self.git('reset', '--hard', '-q', 'HEAD')
                self.git('clean', '-fdq')
                self.input['execution_key'] = 'operation-' + str(20 + ['changed', 'untracked', 'mode', 'symlink'].index(mode))
                self.input['script'] = 'scripts/run'
                if mode == 'changed': self.path.write_text('#!/bin/sh\necho changed\n')
                elif mode == 'untracked':
                    (self.root / 'untracked').write_text('#!/bin/sh\nexit 0\n')
                    (self.root / 'untracked').chmod(0o755)
                    self.input['script'] = 'untracked'
                elif mode == 'mode': self.path.chmod(0o644)
                else:
                    target = self.root / 'target'
                    self.path.rename(target)
                    self.path.symlink_to(target)
                self.assertEqual(self.invoke(), {'error_code': 'tasks.script_unavailable'})

    def test_nonzero_is_business_failure_and_temp_directory_is_removed(self):
        self.install('#!/usr/bin/env python3\nimport os,pathlib,sys\nprint(os.environ["TMPDIR"])\npathlib.Path(os.environ["TMPDIR"], "scratch").write_text("tmp")\nsys.exit(23)\n')
        self.git('add', '.')
        self.git('commit', '-qm', 'nonzero')
        result = self.invoke()
        self.assertEqual(result['exit_code'], 23)
        self.assertEqual(result['outcome'], 'failed')
        self.assertFalse(pathlib.Path(result['output'].strip()).exists())

    def test_output_is_bounded_and_redacted_before_persistence(self):
        self.install('#!/usr/bin/env python3\nprint("log line\\n" * 10000)\nprint("Authorization: Bearer hidden-value")\nprint("DB_PASSWORD=hidden-password")\n')
        self.git('add', '.')
        self.git('commit', '-qm', 'output')
        result = self.invoke()
        self.assertLessEqual(len(result['output'].encode()), 16384)
        self.assertTrue(result['output_truncated'])
        self.assertNotIn('hidden-', json.dumps(result))
        record = self.root / '.git/orbit/scripts/operation-8/result.json'
        self.assertNotIn('hidden-', record.read_text())

    def test_interrupted_marker_never_redispatches(self):
        directory = self.root / '.git/orbit/scripts/operation-8'
        directory.mkdir(parents=True)
        import hashlib
        identity = hashlib.sha256(json.dumps(self.input, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
        (directory / 'input').write_text(identity)
        self.assertEqual(self.invoke(), {'error_code': 'tasks.script_interrupted'})
        self.assertFalse((self.root / 'args').exists())

    def test_cancellation_stops_the_owned_process_and_descendants(self):
        self.install('#!/bin/sh\nsleep 60 &\necho $! > child\ntouch ready\nwait\n')
        self.git('add', '.')
        self.git('commit', '-qm', 'cancellable')
        process = subprocess.Popen(['python3', '-c', ADAPTER], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        process.stdin.write(json.dumps({'program': SOURCE, 'input': self.input}))
        process.stdin.close()
        process.stdin = None
        deadline = time.monotonic() + 3
        while not (self.root / 'ready').exists() and time.monotonic() < deadline:
            time.sleep(0.01)
        self.assertTrue((self.root / 'ready').exists())
        process.send_signal(signal.SIGTERM)
        output, _ = process.communicate(timeout=3)
        self.assertTrue(json.loads(output)['cancelled'])
        pid = int((self.root / 'child').read_text())
        status = pathlib.Path('/proc', str(pid), 'stat')
        self.assertTrue(not status.exists() or status.read_text().split()[2] == 'Z')

    def test_production_command_uses_task_user_cgroup_and_closed_environment(self):
        namespace = {'__name__': 'orbit_script'}
        exec(SOURCE, namespace)
        os.environ['ORBIT_SWAMP_RUN_TOKEN'] = 'never-forward-this'
        self.addCleanup(os.environ.pop, 'ORBIT_SWAMP_RUN_TOKEN', None)
        command = namespace['command'](str(self.root), 'scripts/run', ['literal'], 'unit.service', '/tmp/check', 4177)
        self.assertIn('--property=KillMode=control-group', command)
        self.assertIn('--property=RuntimeMaxSec=3600', command)
        self.assertIn('--uid=' + str(os.getuid()), command)
        self.assertIn('--setenv=INERTIA_SSR_PORT=4177', command)
        self.assertFalse(any('never-forward-this' in arg for arg in command))
        self.assertEqual(command[-3:], ['--', str(self.path), 'literal'])

if __name__ == '__main__':
    unittest.main()
