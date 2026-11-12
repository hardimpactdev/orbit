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
 print(json.dumps({'node':{'version':version,'source':'.nvmrc','source_path':str(Path.cwd()/'.nvmrc'),'bin_path':str(binary)}}))
else:
 assert sys.argv[1:]==['env','install',version]
 if os.environ.get('FAKE_NODE_FAIL'):
  print('checksum mismatch',file=sys.stderr);sys.exit(1)
 binary.parent.mkdir(parents=True,exist_ok=True);binary.write_text('private node')
"""

FAKE_INCUS = """#!/usr/bin/python3
import json,os,subprocess,sys
from pathlib import Path
root=Path(os.environ['NODE_FIXTURE'])
args=sys.argv[1:]
with (root/'incus-calls').open('a') as f:f.write(json.dumps(args)+'\\n')
if 'file' in args:
 target=root/'guest/usr/local/libexec/orbit-instance-node-runtime.py'
 target.parent.mkdir(parents=True,exist_ok=True)
 target.write_text(sys.stdin.read());sys.exit()
if 'device' in args:
 if 'add' in args: assert 'readonly=true' in args and 'shift=true' in args
 sys.exit()
args=args[args.index('--')+1:]
if args[0]=='cat':
 p=root/'guest/usr/local/libexec/orbit-instance-node-runtime.py'
 if not p.exists():sys.exit(1)
 print(p.read_text(),end='');sys.exit()
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
            self.assertFalse(runtime.prepare(self.home, self.shared))
            self.assertEqual(binary.read_bytes(), (self.shared / 'js_runtime/node/24.22.1/bin/node').read_bytes())
        finally:
            process.terminate()
            process.wait(timeout=2)

    def test_recipe_upgrade_and_seed_copy_keep_downloaded_versions_after_shared_pruning(self):
        runtime.prepare(self.home, self.shared)
        self.fetch()
        next_home = self.root / 'next'
        self.seed(next_home, '26.0.0')
        self.assertTrue(runtime.prepare(self.home, next_home))
        shutil.rmtree(self.shared)
        self.assertEqual(runtime.versions(self.home), ['22.23.5', '24.22.1', '26.0.0'])
        copy = self.root / 'clone'
        shutil.copytree(self.home, copy)
        with patch.dict(os.environ, self.environment):
            self.assertEqual(runtime.fetch(self.container, copy, self.vp, pwd.getpwuid(os.getuid()).pw_name)[0], False)
        self.assertFalse(runtime.prepare(self.home, next_home))

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
        self.assertFalse(self.fetch()[0])
        (self.checkout / '.nvmrc').unlink()
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

    @unittest.skipUnless(shutil.which('ansible-playbook'), 'pinned Ansible required')
    def test_production_phase_removes_read_only_source_mount_after_failure_without_downtime(self):
        import yaml
        (self.home / 'js_runtime').symlink_to(self.shared / 'js_runtime')
        script = SOURCE.read_text().replace("Path('/var/lib/orbit/vite-plus-home')", 'Path(' + repr(str(self.home)) + ')')
        script = script.replace("Path('/opt/orbit-runtime/current/vite-plus-home')", 'Path(' + repr(str(self.shared)) + ')')
        script = script.replace("VP = '/opt/orbit-runtime/current/bin/vp'", 'VP = ' + repr(str(self.vp)))
        (self.root / 'instance_node_runtime.py').write_text(script)
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
        self.assertFalse(any('systemctl' in call for call in calls))
        self.assertEqual((self.checkout / '.nvmrc').read_bytes(), before)
        workflow = yaml.safe_load((ROOT / 'swamp/workflows/workflow-operation-instance-migrate.yaml').read_text())
        steps = {step['name']: step for step in workflow['jobs'][0]['steps']}
        self.assertEqual(steps['quiesce']['dependsOn'], [{'step': 'node-runtime', 'condition': {'type': 'succeeded'}}])


if __name__ == '__main__':
    unittest.main()
