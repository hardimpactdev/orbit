"""Production removal graphs and unmodified official behaviors on disposable host fixtures."""
import json
import os
import shutil
import sys
from pathlib import Path

import yaml
from native_harness import ROOT, NativeTestCase

PIN = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB'


class NodeRemovalNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        inventory = json.loads((ROOT / 'extensions/models/upstream_extensions.json').read_text())
        for package in ('@swamp/ssh', '@swamp/cfgmgmt'):
            self.cli('extension', 'pull', package + '@' + inventory[package]['version'])
        self.host = self.repo / 'host'
        self.host.mkdir()
        self.home = self.host / 'home'
        (self.home / '.ssh').mkdir(parents=True)
        self.keys = self.home / '.ssh/authorized_keys'
        self.keys.write_text(PIN + ' control\nssh-ed25519 AAAAUnrelated operator\n')
        self.audit = self.repo / 'calls'
        tools = self.repo / 'tools'
        tools.mkdir()
        self.native_env.update(PATH=str(tools) + ':' + os.environ['PATH'], HOST=str(self.host), AUDIT=str(self.audit))
        scripts = {
            'ssh': '''import os,subprocess,sys
if '-M' in sys.argv or '-O' in sys.argv:sys.exit(0)
if os.environ.get('OFFLINE')=='1':sys.exit(255)
sys.exit(subprocess.call(['/bin/sh','-c',sys.argv[-1]]))
''',
            'scp': '''import shutil,sys
shutil.copyfile(sys.argv[-2],sys.argv[-1].split(':',1)[1])
''',
            'sudo': '''import subprocess,sys
a=sys.argv[1:];a=a[a.index('--')+1:]
sys.exit(subprocess.call(a))
''',
            'systemctl': '''import os,sys
from pathlib import Path
a=sys.argv[1:]
with open(os.environ['AUDIT'],'a') as f:f.write('systemctl '+ ' '.join(a)+'\\n')
if os.environ.get('FAIL_STOP')=='1' and a[0]=='stop':sys.exit(1)
if a[0]=='show':
 if '--value' in a:print('loaded')
 else:print('LoadState=loaded\\nActiveState=inactive\\nUnitFileState=disabled')
''',
            'getent': '''import os,sys
if sys.argv[1:]==['passwd','fixture']:print('fixture:x:1000:1000::'+os.environ['HOST']+'/home:/bin/sh')
else:sys.exit(2)
''',
            'chown': 'pass\n', 'chgrp': 'pass\n',
            'ufw': '''import os,sys
with open(os.environ['AUDIT'],'a') as f:f.write('ufw '+ ' '.join(sys.argv[1:])+'\\n')
if os.environ.get('FIREWALL_ERROR')=='1':print('ERROR: permission denied');sys.exit(1)
if 'delete' in sys.argv:print('Could not delete non-existent rule');sys.exit(1)
''',
        }
        for name, source in scripts.items():
            path = tools / name
            path.write_text('#!' + sys.executable + '\n' + source)
            path.chmod(0o700)
        for name in ('node-role-remove', 'operation-node-remove', 'operation-node-role-remove-direct'):
            w = yaml.safe_load((ROOT / ('workflows/workflow-' + name + '.yaml')).read_text())
            self.assertEqual(w.pop('labels'), {'role': 'swamp'})
            for job in w['jobs']:
                for step in job['steps']:
                    self.assertEqual(step.pop('labels'), {'role': 'swamp'})
                    task = step['task']
                    if task.get('behaviorType') == '@swamp/ssh':
                        task['globalArgs']['sshBinary'] = str(tools / 'ssh')
            (self.repo / ('workflows/workflow-' + name + '.yaml')).write_text(yaml.safe_dump(w, sort_keys=False))
        self.ssh = {'target': {'host': 'fixture', 'user': 'fixture', 'port': 22, 'host_keys': [PIN]}, 'identity_file': '/fixture/identity'}
        self.file = self.host / 'orbit-owned-unit'
        self.file.write_text('owned')
        self.config = self.host / 'orbit-owned-config'
        self.config.mkdir()
        (self.config / 'secret').write_text('owned')
        self.data = self.host / 'user-data'
        self.data.write_text('keep')
        self.role = {'schema_version': 1, 'node_id': 701, 'assignment_id': 101, 'role': 'websocket', 'ssh': self.ssh,
                     'public_ssh': [], 'ci_host': [], 'commands': [], 'publications': [], 'stop_units': [{'ssh': self.ssh, 'input': {'unit': 'orbit-websocket.service'}}],
                     'remove_containers': [], 'firewall': [{'ssh': self.ssh, 'input': {'argv': ['ufw', '--force', 'delete', 'allow', '6001', 'comment', 'orbit:role:websocket']}}],
                     'certificates': [], 'files': [{'ssh': self.ssh, 'path': str(self.file)}],
                     'directories': [{'ssh': self.ssh, 'path': str(self.config)}], 'reload_units': [{'ssh': self.ssh}]}
        self.intent = {'schema_version': 1, 'node_id': 701, 'name': 'disposable', 'offline': False, 'machine_deleted': False,
                       'platform': 'linux', 'ssh': self.ssh, 'roles': [], 'public_ssh': {'argv': ['ufw', 'allow', '22']},
                       'managed_keys': [PIN], 'hub': [], 'router_rules': [], 'peers': [], 'dns': [],
                       'footprints': {'files': self.role['files'], 'directories': self.role['directories']},
                       'metrics_files': [], 'metrics_reload': [], 'retained_on_node': [], 'preserved_on_node': ['User data and host-side WireGuard']}

    def run_root(self, name, intent, success=True, **extra):
        inputs = {'operation_id': 9001, 'node_id': 701, 'kind': 'node.remove', 'intent': intent, 'execution_secrets': {}, **extra}
        result = json.loads(self.cli('workflow', 'run', name, '--input', json.dumps(inputs), success=success).stdout)
        history = json.loads(self.cli('workflow', 'history', 'get', result['id']).stdout)
        return result, history

    def test_role_removes_owned_paths_and_tolerates_only_missing_firewall_rule(self):
        self.run_root('node-role-remove', self.role, offline=False)
        self.assertFalse(self.file.exists())
        self.assertFalse(self.config.exists())
        self.assertEqual(self.data.read_text(), 'keep')
        self.assertIn('stop orbit-websocket.service', self.audit.read_text())
        self.assertIn('daemon-reload', self.audit.read_text())

    def test_standalone_role_root_uses_the_same_resource_graph(self):
        self.run_root('operation-node-role-remove-direct', self.role, offline=False)
        self.assertFalse(self.file.exists())
        self.assertTrue(self.data.exists())

    def test_failed_stop_keeps_following_files_and_reports_failure(self):
        self.native_env['FAIL_STOP'] = '1'
        self.run_root('node-role-remove', self.role, success=False, offline=False)
        self.assertTrue(self.file.exists())
        self.assertTrue(self.config.exists())

    def test_firewall_permission_error_fails_end_check(self):
        self.native_env['FIREWALL_ERROR'] = '1'
        self.run_root('node-role-remove', self.role, success=False, offline=False)

    def test_online_node_removal_revokes_control_key_last_and_keeps_unrelated_data(self):
        self.run_root('operation-node-remove', self.intent)
        self.assertFalse(self.file.exists())
        self.assertFalse(self.config.exists())
        self.assertNotIn(PIN, self.keys.read_text())
        self.assertIn('AAAAUnrelated', self.keys.read_text())
        self.assertEqual(self.data.read_text(), 'keep')

    def test_offline_consent_does_not_skip_reachable_node(self):
        self.intent['offline'] = True
        self.run_root('operation-node-remove', self.intent)
        self.assertFalse(self.file.exists())

    def test_unreachable_node_requires_consent_and_preserves_target(self):
        self.native_env['OFFLINE'] = '1'
        self.run_root('operation-node-remove', self.intent, success=False)
        self.assertTrue(self.file.exists())
        self.intent['offline'] = True
        self.run_root('operation-node-remove', self.intent)
        self.assertTrue(self.file.exists())
        self.assertIn(PIN, self.keys.read_text())

    def test_machine_deleted_skips_target_without_any_ssh_identity(self):
        self.intent.update(machine_deleted=True, ssh=None, offline=True, roles=[])
        self.run_root('operation-node-remove', self.intent)
        self.assertTrue(self.file.exists())
        self.assertFalse(self.audit.exists())


if __name__ == '__main__':
    import unittest
    unittest.main()
