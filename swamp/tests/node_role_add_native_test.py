"""Native role-add control flow and unmodified official file/systemd behaviors."""
import json
import os
import pwd
import grp
import sys

import yaml
from native_harness import ROOT, NativeTestCase

PIN = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB'


class NodeRoleAddNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        inventory = json.loads((ROOT / 'extensions/models/upstream_extensions.json').read_text())
        for package in ('@swamp/ssh', '@swamp/cfgmgmt'):
            self.cli('extension', 'pull', package + '@' + inventory[package]['version'])
        self.host = self.repo / 'host'
        self.host.mkdir()
        self.state = self.host / 'state.json'
        self.state.write_text(json.dumps({'active': False, 'enabled': False, 'calls': []}))
        tools = self.repo / 'tools'
        tools.mkdir()
        self.native_env.update(PATH=str(tools) + ':' + os.environ['PATH'], STATE=str(self.state))
        scripts = {
            'ssh': '''import subprocess,sys
if '-M' in sys.argv or '-O' in sys.argv:sys.exit(0)
sys.exit(subprocess.call(['/bin/sh','-c',sys.argv[-1]]))
''',
            'scp': '''import shutil,sys
shutil.copyfile(sys.argv[-2],sys.argv[-1].split(':',1)[1])
''',
            'sudo': '''import subprocess,sys
a=sys.argv[1:];a=a[a.index('--')+1:]
sys.exit(subprocess.call(a))
''',
            'systemctl': '''import json,os,sys
from pathlib import Path
p=Path(os.environ['STATE']);s=json.loads(p.read_text());a=sys.argv[1:]
s['calls'].append(a)
if a[0]=='enable':s['enabled']=True
if a[0]=='start':s['active']=True
p.write_text(json.dumps(s))
if a[0]=='start' and os.environ.get('FAIL_START')=='1':sys.exit(1)
if a[0]=='show':
 print('LoadState=loaded')
 print('ActiveState='+('active' if s['active'] and os.environ.get('DRIFT')!='1' else 'inactive'))
 print('UnitFileState='+('enabled' if s['enabled'] else 'disabled'))
''',
            'cat': '''import subprocess,sys
if sys.argv[1:]==['/etc/os-release']:print('ID=ubuntu\\nVERSION_CODENAME=resolute')
else:sys.exit(subprocess.call(['/bin/cat',*sys.argv[1:]]))
''',
            'dpkg-query': '''import json,os,subprocess,sys
from pathlib import Path
if sys.argv[-1] in json.loads(Path(os.environ['STATE']).read_text()).get('packages',[]):print('install ok installed 1.0');sys.exit(0)
if os.environ.get('DOCKER_CE')=='1':
 if sys.argv[-1] in ('docker-ce','docker-ce-cli','containerd.io'):print('install ok installed 29.0.0');sys.exit(0)
 if sys.argv[-1]=='docker.io':sys.exit(1)
sys.exit(subprocess.call(['/usr/bin/dpkg-query',*sys.argv[1:]]))
''',
            'apt-get': '''import json,os,sys
from pathlib import Path
p=Path(os.environ['STATE']);s=json.loads(p.read_text());s['calls'].append(['apt-get',*sys.argv[1:]]);p.write_text(json.dumps(s))
if os.environ.get('DOCKER_CE')=='1' and 'docker.io' in sys.argv:sys.exit(1)
if 'install' in sys.argv:
 s['packages']=sys.argv[sys.argv.index('install')+1:];p.write_text(json.dumps(s))
''', 'chown': 'pass\n', 'chgrp': 'pass\n',
        }
        for name, source in scripts.items():
            path = tools / name
            path.write_text('#!' + sys.executable + '\n' + source)
            path.chmod(0o700)
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-node-role-add.yaml').read_text())
        for job in workflow['jobs']:
            job.pop('labels', None)
            for step in job['steps']:
                self.assertEqual(step.pop('labels'), {'role': 'swamp'})
                task = step['task']
                if task.get('behaviorType') == '@swamp/ssh':
                    task['globalArgs']['sshBinary'] = str(tools / 'ssh')
        (self.repo / 'workflows/workflow-operation-node-role-add.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.intent = {'node_id': 701, 'assignment_id': 101, 'role': 'database',
                       'ssh': {'target': {'host': 'fixture', 'user': 'fixture', 'port': 22, 'host_keys': [PIN]}, 'identity_file': '/fixture/key'},
                       'releases': ['resolute'], 'packages': [], 'caddy_package': None, 'bun': None, 'directories': [], 'files': [], 'php_packages': [], 'php': [], 'managers': [],
                       'certificates': {'leaves': [], 'withdrawals': []}, 'publications': [], 'exporters': [], 'prometheus': None,
                       'services': [{'name': 'docker.service', 'index': 0}], 'firewall': {'desired': [], 'remove': []}}
        self.intent['runtime'] = {'directories': [], 'files': [], 'secret_files': [], 'units': [], 'probes': [], 'groups': [], 'users': [],
                                  'websocket': None, 'metrics': None, 'analytics': None, 'gateway_dns': None, 'swamp': None, 'ci_host': False}

    def run_role(self, success=True):
        result = json.loads(self.cli('workflow', 'run', 'operation-node-role-add', '--input', json.dumps({
            'operation_id': 9001, 'node_id': 701, 'kind': 'node.role_add', 'intent': self.intent, 'execution_secrets': {},
        }), success=success).stdout)
        return json.loads(self.cli('workflow', 'history', 'get', result['id']).stdout)

    def test_empty_optional_work_does_not_skip_the_common_role_runtime(self):
        self.intent['files'] = [{'path': str(self.host / 'role.conf'), 'content': 'managed role\n',
                                 'owner': pwd.getpwuid(os.getuid()).pw_name, 'group': grp.getgrgid(os.getgid()).gr_name, 'mode': '0644'}]
        history = self.run_role()
        state = json.loads(self.state.read_text())
        self.assertTrue(state['active'])
        self.assertTrue(state['enabled'])
        statuses = {step['name']: step['status'] for job in history['jobs'] for step in job['steps']}
        self.assertEqual(statuses['Check the role'], 'succeeded')
        self.assertEqual(statuses['Check role files-0'], 'succeeded')
        self.assertEqual((self.host / 'role.conf').read_text(), 'managed role\n')

    def test_service_start_failure_stops_the_role(self):
        self.native_env['FAIL_START'] = '1'
        history = self.run_role(success=False)
        statuses = {step['name']: step['status'] for job in history['jobs'] for step in job['steps']}
        self.assertEqual(statuses['Start role services-0'], 'failed')
        self.assertNotEqual(statuses.get('Check the role'), 'succeeded')

    def test_drift_in_the_official_end_check_fails_the_native_assertion(self):
        self.native_env['DRIFT'] = '1'
        history = self.run_role(success=False)
        statuses = {step['name']: step['status'] for job in history['jobs'] for step in job['steps']}
        self.assertEqual(statuses['Check the role'], 'failed')

    def test_healthy_docker_ce_satisfies_the_private_container_prerequisite(self):
        self.native_env['DOCKER_CE'] = '1'
        self.state.write_text(json.dumps({'active': True, 'enabled': True, 'calls': []}))
        self.intent['packages'] = ['docker.io']
        history = self.run_role()
        statuses = {step['name']: step['status'] for job in history['jobs'] for step in job['steps']}
        self.assertEqual(statuses['Check the role'], 'succeeded')
        self.assertEqual(statuses['Install prerequisites'], 'skipped')
        self.assertFalse(any('docker.io' in call for call in json.loads(self.state.read_text())['calls']))

    def test_prerequisite_install_cannot_remove_or_upgrade_existing_packages(self):
        self.intent['packages'] = ['fixture-prerequisite']
        history = self.run_role()
        statuses = {step['name']: step['status'] for job in history['jobs'] for step in job['steps']}
        self.assertEqual(statuses['Check the role'], 'succeeded')
        install = next(call for call in json.loads(self.state.read_text())['calls'] if call[0] == 'apt-get' and 'install' in call)
        self.assertIn('--no-remove', install)
        self.assertIn('--no-upgrade', install)
        self.assertIn('DPkg::Lock::Timeout=60', install)
