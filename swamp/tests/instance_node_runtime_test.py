"""Real private-store conversion and production Ansible phase; vp and Incus are local boundaries."""
import importlib.util
import json
import os
import pwd
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / 'swamp/ansible/instance_node_runtime.py'
SPEC = importlib.util.spec_from_file_location('node_runtime', SOURCE)
runtime = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(runtime)

FAKE_VP = """#!/usr/bin/python3
import json,os,sys
from pathlib import Path
home=Path(os.environ['VP_HOME'])
with (home/'calls').open('a') as f: f.write(json.dumps(sys.argv[1:])+'\\n')
version=os.environ.get('FAKE_NODE_VERSION','22.23.5')
binary=home/'js_runtime/node'/version/'bin/node'
if sys.argv[1:]==['env','current','node','--json']:
 if os.environ.get('FAKE_NODE_WARNING'):print(os.environ['FAKE_NODE_WARNING'],file=sys.stderr)
 print(json.dumps({'node':{'version':version,'source':os.environ.get('FAKE_NODE_SOURCE','.nvmrc' if (Path.cwd()/'.nvmrc').exists() else 'default'),'source_path':str(Path.cwd()/'.nvmrc'),'bin_path':str(binary)}}))
else:
 assert sys.argv[1:]==['env','install',version]
 if os.environ.get('FAKE_NODE_FAIL'):
  print(os.environ.get('FAKE_NODE_REASON','checksum mismatch'),file=sys.stderr);sys.exit(1)
 binary.parent.mkdir(parents=True,exist_ok=True);binary.write_text('private node')
"""

FAKE_INCUS = """#!/usr/bin/python3
import json,os,subprocess,sys
from pathlib import Path
root=Path(os.environ['NODE_FIXTURE'])
args=sys.argv[1:]
with (root/'incus-calls').open('a') as f:f.write(json.dumps(args)+'\\n')
if 'file' in args:
 target=root/'guest'/args[-1].split('/',1)[1]
 target.parent.mkdir(parents=True,exist_ok=True)
 target.write_text(sys.stdin.read());sys.exit()
if 'device' in args:
 if 'add' in args: assert 'readonly=true' in args and 'shift=true' in args
 sys.exit()
args=args[args.index('--')+1:]
if args[0]=='cat':
 p=root/'guest'/args[1].lstrip('/')
 if not p.exists():sys.exit(1)
 print(p.read_text(),end='');sys.exit()
if args[0]=='systemctl':sys.exit(0 if args[1]!='is-enabled' else 1)
assert args[0]=='python3'
args[1]=str(root/'guest/usr/local/libexec/orbit-instance-node-runtime.py')
sys.exit(subprocess.run(args,input=sys.stdin.read(),text=True,check=False).returncode)
"""


class NodeRuntimeTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='node-runtime-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.home = self.root / 'home'
        self.home.mkdir()
        self.shared = self.root / 'shared'
        self.seed(self.shared, '24.22.1')
        self.vp = self.root / 'vp'
        self.vp.write_text(FAKE_VP)
        self.vp.chmod(0o755)
        self.checkout = self.root / 'checkout'
        self.checkout.mkdir()
        (self.checkout / '.nvmrc').write_text('22')
        self.container = {'workspace': str(self.checkout), 'processes': [
            {'name': 'vp-dev', 'content': '[Service]\nWorkingDirectory=' + str(self.checkout) + '\nExecStart=vp dev\n'}]}
        self.environment = {'FAKE_NODE_VERSION': '22.23.5'}

    def seed(self, home, version):
        binary = home / 'js_runtime/node' / version / 'bin/node'
        binary.parent.mkdir(parents=True)
        shutil.copy2(sys.executable, binary)
        return binary

    def fetch(self):
        with patch.dict(os.environ, self.environment):
            return runtime.fetch(self.container, self.home, self.vp, pwd.getpwuid(os.getuid()).pw_name)

    def test_conversion_keeps_running_executable_and_is_unchanged_on_repeat(self):
        (self.home / 'js_runtime').symlink_to(self.shared / 'js_runtime')
        binary = self.home / 'js_runtime/node/24.22.1/bin/node'
        process = subprocess.Popen([str(binary), '-c', 'import time; time.sleep(30)'])
        try:
            pid = process.pid
            executable = Path('/proc') / str(pid) / 'exe'
            before = executable.resolve()
            self.assertTrue(runtime.prepare(self.home, self.shared))
            self.assertFalse((self.home / 'js_runtime').is_symlink())
            self.assertEqual(process.pid, pid)
            self.assertIsNone(process.poll())
            self.assertEqual(executable.resolve(), before)
            runtime.prepare(self.home, self.shared, owner=(os.getuid(), os.getgid()))
            before_metadata = [(path.stat().st_ctime_ns, path.stat().st_mode) for path in [self.home / 'js_runtime', self.home / 'js_runtime/node']]
            self.assertFalse(runtime.prepare(self.home, self.shared, owner=(os.getuid(), os.getgid())))
            self.assertEqual(before_metadata, [(path.stat().st_ctime_ns, path.stat().st_mode) for path in [self.home / 'js_runtime', self.home / 'js_runtime/node']])
            self.assertEqual(binary.read_bytes(), (self.shared / 'js_runtime/node/24.22.1/bin/node').read_bytes())
        finally:
            process.terminate()
            process.wait(timeout=2)

    def test_recipe_upgrade_and_seed_copy_keep_downloaded_versions_after_shared_pruning(self):
        runtime.prepare(self.home, self.shared)
        self.fetch()
        old_home = self.root / 'old'
        self.shared.rename(old_home)
        self.shared.symlink_to(old_home)
        next_home = self.root / 'next'
        self.seed(next_home, '26.0.0')
        self.shared.unlink()
        self.shared.symlink_to(next_home)
        shutil.rmtree(old_home)
        # A stopped root and an image built against the old recipe both use this same boot refresh.
        self.assertTrue(runtime.prepare(self.home, self.shared, convert=False))
        self.assertEqual(runtime.versions(self.home), ['22.23.5', '26.0.0'])
        self.assertFalse((self.home / 'js_runtime/node/24.22.1').is_symlink())
        self.assertTrue((self.home / 'js_runtime/node/26.0.0').is_symlink())
        copy = self.root / 'clone'
        shutil.copytree(self.home, copy, symlinks=True)
        with patch.dict(os.environ, self.environment):
            self.assertEqual(runtime.fetch(self.container, copy, self.vp, pwd.getpwuid(os.getuid()).pw_name)[0], False)
        self.assertFalse(runtime.prepare(self.home, self.shared))
        image = self.root / 'stale-image'
        self.assertTrue(runtime.prepare(image, self.shared, convert=False))
        self.assertEqual(runtime.versions(image), ['26.0.0'])
        self.assertEqual((image / 'js_runtime/node/26.0.0').readlink(), self.shared / 'js_runtime/node/26.0.0')
        unit = (ROOT / 'swamp/ansible/instance-node-runtime.service').read_text()
        self.assertIn('Before=basic.target', unit)
        self.assertIn(' refresh {}', unit)
        self.assertIn('ExecStartPre=+', (ROOT / 'swamp/ansible/instance-node-runtime.conf').read_text())

    def test_vite_alone_resolves_and_fetches_once_as_the_app_user(self):
        runtime.prepare(self.home, self.shared)
        self.assertTrue(self.fetch()[0])
        calls = [json.loads(x) for x in (self.home / 'calls').read_text().splitlines()]
        self.assertEqual(calls, [['env', 'current', 'node', '--json'], ['env', 'install', '22.23.5']])
        changed, pins = self.fetch()
        self.assertFalse(changed)
        self.assertEqual(pins[0]['pinned'], '22')
        self.assertEqual(pins[0]['version'], '22.23.5')

    def test_recipe_pin_and_unpinned_projects_need_no_install(self):
        runtime.prepare(self.home, self.shared)
        self.environment['FAKE_NODE_VERSION'] = '24.22.1'
        (self.checkout / '.nvmrc').write_text('24.22.1\n')
        self.assertFalse(self.fetch()[0])
        (self.checkout / '.nvmrc').unlink()
        self.assertFalse(self.fetch()[0])
        self.environment['FAKE_NODE_SOURCE'] = 'default'
        for value in ('', '# comment only\\n'):
            (self.checkout / '.nvmrc').write_text(value)
            self.assertFalse(self.fetch()[0])
        calls = (self.home / 'calls').read_text()
        self.assertNotIn('"install"', calls)

    def test_download_integrity_and_timeout_failures_name_the_pin(self):
        runtime.prepare(self.home, self.shared)
        self.environment['FAKE_NODE_FAIL'] = '1'
        with self.assertRaisesRegex(RuntimeError, '.nvmrc pins Node 22: checksum verification failed'):
            self.fetch()
        with (patch.object(runtime.subprocess, 'run', side_effect=subprocess.TimeoutExpired('vp', 30)),
              self.assertRaisesRegex(RuntimeError, '.nvmrc pins Node 22: Node preparation timed out')):
            self.fetch()

    def test_malformed_and_ambiguous_pins_refuse_instead_of_using_the_default(self):
        runtime.prepare(self.home, self.shared)
        self.environment.update(FAKE_NODE_VERSION='24.22.1', FAKE_NODE_SOURCE='default')
        for pin, warning, reason in [('banana', "warning: invalid version 'banana' in .nvmrc, ignoring", 'invalid Node version'),
                                     ('22\n24\n', '', 'ambiguous .nvmrc: more than one version declaration')]:
            with self.subTest(pin=pin):
                (self.checkout / '.nvmrc').write_text(pin)
                self.environment['FAKE_NODE_WARNING'] = warning
                with self.assertRaisesRegex(RuntimeError, reason):
                    self.fetch()
        self.assertNotIn('"install"', (self.home / 'calls').read_text())

    def test_download_failures_keep_specific_safe_reasons(self):
        runtime.prepare(self.home, self.shared)
        self.environment['FAKE_NODE_FAIL'] = '1'
        for diagnostic, reason in [('DNS lookup failed: network unreachable', 'network failure'),
                                   ('HTTP status server error (503 Service Unavailable)', 'HTTP 503'),
                                   ('checksum mismatch', 'checksum verification failed'),
                                   ('No space left on device (os error 28)', 'not enough disk space'),
                                   ('Read-only file system (os error 30)', 'store is read-only'),
                                   ("No version matching '999' found", 'unknown Node version'),
                                   ('Version 999.0.0 not found for node', 'unknown Node version'),
                                   ("Unknown LTS codename: 'banana'", 'unknown Node version')]:
            with self.subTest(diagnostic=diagnostic):
                self.environment['FAKE_NODE_REASON'] = diagnostic
                with self.assertRaisesRegex(RuntimeError, '.nvmrc pins Node 22: .*' + reason):
                    self.fetch()

    def test_non_vite_projects_do_not_resolve_or_download_unused_pins(self):
        for command in (None, '/usr/bin/php artisan queue:work', '/usr/bin/python3 worker.py node'):
            with self.subTest(command=command):
                container = {**self.container, 'processes': [] if command is None else [
                    {'content': 'WorkingDirectory=' + str(self.checkout) + '\nExecStart=' + command}]}
                with patch.object(runtime.pwd, 'getpwnam', side_effect=AssertionError('No app-user or Node dependency')):
                    self.assertEqual(runtime.fetch(container, self.home, self.vp), (False, []))
        self.assertFalse((self.home / 'calls').exists())

    def test_boot_refresh_never_converts_an_existing_legacy_store(self):
        (self.home / 'js_runtime').symlink_to(self.shared / 'js_runtime')
        self.assertFalse(runtime.prepare(self.home, self.shared, convert=False))
        self.assertTrue((self.home / 'js_runtime').is_symlink())

    @unittest.skipUnless(shutil.which('ansible-playbook'), 'pinned Ansible required')
    def test_production_phase_removes_read_only_source_mount_after_failure_without_downtime(self):
        import yaml
        (self.home / 'js_runtime').symlink_to(self.shared / 'js_runtime')
        script = SOURCE.read_text().replace("Path('/var/lib/orbit/vite-plus-home')", 'Path(' + repr(str(self.home)) + ')')
        script = script.replace("Path('/opt/orbit-runtime/current/vite-plus-home')", 'Path(' + repr(str(self.shared)) + ')')
        script = script.replace("app_user=\'orbit\'", "app_user=" + repr(pwd.getpwuid(os.getuid()).pw_name))
        script = script.replace("VP = '/opt/orbit-runtime/current/bin/vp'", 'VP = ' + repr(str(self.vp)))
        (self.root / 'instance_node_runtime.py').write_text(script)
        shutil.copy(ROOT / 'swamp/ansible/instance-node-runtime.service', self.root)
        shutil.copy(ROOT / 'swamp/ansible/instance-node-runtime.conf', self.root)
        (self.root / 'tasks').mkdir()
        shutil.copy(ROOT / 'swamp/ansible/tasks/instance-node-runtime.yml', self.root / 'tasks')
        incus = self.root / 'incus'
        incus.write_text(FAKE_INCUS)
        incus.chmod(0o755)
        container = {**self.container, 'project': 'fixture', 'name': 'fixture'}
        play = [{'hosts': 'localhost', 'connection': 'local', 'gather_facts': False,
                 'vars': {'ansible_python_interpreter': sys.executable, 'container': container,
                          'intent': {'migrate': {'checkout': str(self.checkout)}},
                          'phase': 'node-runtime', 'node_fetch': True,
                          'as_root': [str(incus), 'exec', '--project', 'fixture', 'fixture', '--'],
                          'incus_project': [str(incus), '--project', 'fixture']},
                 'tasks': [{'ansible.builtin.include_tasks': 'tasks/instance-node-runtime.yml'}]}]
        path = self.root / 'play.yml'
        path.write_text(yaml.safe_dump(play))
        before = (self.checkout / '.nvmrc').read_bytes()
        env = {**os.environ, 'NODE_FIXTURE': str(self.root), 'FAKE_NODE_FAIL': '1', 'FAKE_NODE_VERSION': '22.23.5',
               'ANSIBLE_LOCAL_TEMP': str(self.root / 'ansible-local'), 'ANSIBLE_REMOTE_TEMP': str(self.root / 'ansible-remote'),
               'ANSIBLE_STDOUT_CALLBACK': 'default', 'ANSIBLE_COLLECTIONS_PATH': str(self.root / 'empty')}
        done = subprocess.run(['ansible-playbook', '-i', 'localhost,', str(path)], env=env, capture_output=True, text=True, check=False)
        self.assertNotEqual(done.returncode, 0, done.stdout + done.stderr)
        self.assertIn('instance.node_runtime_prepare_failed', done.stdout + done.stderr)
        calls = [json.loads(line) for line in (self.root / 'incus-calls').read_text().splitlines()]
        self.assertTrue(any('add' in call and 'readonly=true' in call for call in calls))
        self.assertTrue(any('remove' in call and 'orbit-node-source' in call for call in calls))
        self.assertFalse(any('systemctl' in call and any(x in call for x in ('start', 'stop', 'restart', 'try-restart', '--now')) for call in calls))
        self.assertEqual((self.checkout / '.nvmrc').read_bytes(), before)
        workflow = yaml.safe_load((ROOT / 'swamp/workflows/workflow-operation-instance-migrate.yaml').read_text())
        steps = {step['name']: step for step in workflow['jobs'][0]['steps']}
        self.assertEqual(steps['quiesce']['dependsOn'], [{'step': 'node-runtime', 'condition': {'type': 'succeeded'}}])


if __name__ == '__main__':
    unittest.main()
