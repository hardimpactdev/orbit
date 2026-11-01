"""Disposable native roots with unmodified official SSH and harmless host seams."""
import copy
import json
import os
import re
import shlex
import shutil
import socket
import subprocess
import sys
import time
import unittest
import urllib.request
from pathlib import Path

import yaml
from native_harness import ROOT, NativeTestCase

PIN = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB'


class NodeRemovalNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/node-removal-command', 'operations/node-role-converge-command')
        for file in ('fleet/cli_install.ts', 'fleet/cli_install_program.ts', 'node/network_native_program.ts', '_lib/node-commands.ts'):
            dest = self.repo / 'extensions/behaviors/operations' / file
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy(ROOT / 'extensions/behaviors/operations' / file, dest)
        # Copy the real static import closure, without registering unrelated families.
        seen = set()
        def dependencies(source):
            if source in seen:
                return
            seen.add(source)
            for relative in re.findall(r'(?:from|import)\s*[\"\'](\.[^\"\']+)[\"\']', source.read_text()):
                target = (source.parent / relative).resolve()
                if not target.is_file():
                    continue
                destination = self.repo / target.relative_to(ROOT)
                destination.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy(target, destination)
                dependencies(target)
        dependencies(ROOT / 'extensions/behaviors/operations/node-role-converge-command/assets.ts')
        dependencies(ROOT / 'extensions/behaviors/operations/node-removal-command/assets.ts')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.audit = self.repo / 'ssh-audit'
        ssh = self.repo / 'disposable-ssh'
        ssh.write_text('#!' + sys.executable + '\n' + '''import os,sys
from pathlib import Path
if '-O' in sys.argv or '-N' in sys.argv or '-MNf' in sys.argv or '-M' in sys.argv:raise SystemExit(0)
with Path(os.environ['SSH_AUDIT']).open('a') as stream:stream.write('action\\n')
raise SystemExit(int(os.environ.get('SSH_FAIL','0')))
''')
        ssh.chmod(0o755)
        self.native_env = {'SSH_AUDIT': str(self.audit), 'SSH_FAIL': '0'}
        for name in ('operation-route-php-delta', 'operation-route-caddy', 'route-dns-publish'):
            (self.repo / ('workflows/workflow-' + name + '.yaml')).write_text(yaml.safe_dump({
                'id': {'operation-route-php-delta': '639b4222-dc89-4eb5-bba3-7d6ab656b1b1',
                       'operation-route-caddy': '639b4222-dc89-4eb5-bba3-7d6ab656b1b2',
                       'route-dns-publish': '639b4222-dc89-4eb5-bba3-7d6ab656b1b3'}[name],
                'name': name, 'inputs': yaml.safe_load((ROOT / ('workflows/workflow-' + name + '.yaml')).read_text())['inputs'], 'jobs': [{'name': 'effect', 'steps': [{
                    'name': 'noop', 'task': {'type': 'assert', 'expr': ('has(inputs.intent.caddy_publication.connection.nodeHost)' if name == 'operation-route-caddy' else 'has(inputs.intent.connection.nodeHost)'), 'message': 'disposable captured publication connection'}}]}]}))
        for name in ('node-role-remove', 'operation-node-remove'):
            workflow = yaml.safe_load((ROOT / ('workflows/workflow-' + name + '.yaml')).read_text())
            self.assertEqual(workflow.pop('target'), 'orbit-node-209')
            for job in workflow['jobs']:
                for step in job['steps']:
                    self.assertEqual(step.pop('target'), 'orbit-node-209')
                    self.assertNotIn('labels', step)
                    task = step['task']
                    if task.get('behaviorType') == '@swamp/ssh':
                        task['globalArgs']['sshBinary'] = str(ssh)
                    if step['name'] == 'probe':
                        task['inputs']['run'] = "printf '%s' '{\"reachable\":true}'"
                    if step['name'] in ('worker_tokens', 'access_tokens'):
                        self.assertIn(' token list', task['inputs']['run'])
                        task['inputs']['run'] = "printf '%s' '[]'"
            (self.repo / ('workflows/workflow-' + name + '.yaml')).write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.ssh = {'target': {'host': 'disposable.test', 'user': 'fixture', 'port': 22, 'host_keys': [PIN]},
                    'identity_file': '/fixture/public-only-identity'}
        self.role = {'schema_version': 1, 'node_id': 701, 'assignment_id': 101, 'role': 'database', 'ssh': self.ssh,
                     'publications': [], 'stop_units': [], 'stop_containers': [], 'remove_containers': [],
                     'ci_host': [], 'firewall': [], 'certificates': [], 'remove_units': [], 'remove_footprints': [], 'reload_units': []}
        self.intent = {'schema_version': 1, 'node_id': 701, 'name': 'disposable', 'offline': False,
                       'platform': 'linux', 'probe': {'host': 'disposable.test', 'port': 22}, 'ssh': self.ssh,
                       'roles': [self.role], 'public_ssh': {'argv': ['ufw', 'allow', '22']},
                       'managed_keys': [PIN], 'hub': [], 'router_rules': [], 'peers': [], 'dns': [],
                       'metrics_files': [], 'metrics_reload': [], 'retained_on_node': [], 'preserved_on_node': ['User data and host-side WireGuard']}

    def run_root(self, name, intent, success=True, **extra):
        inputs = {'operation_id': 9001, 'node_id': 701, 'kind': 'node.remove', 'intent': intent,
                  'execution_secrets': {}, **extra}
        result = json.loads(self.cli('workflow', 'run', name, '--input', json.dumps(inputs), success=success).stdout)
        history = json.loads(self.cli('workflow', 'history', 'get', result['id']).stdout)
        evidence = os.environ.get('NODE_REMOVAL_NATIVE_EVIDENCE')
        if evidence:
            Path(evidence).mkdir(parents=True, exist_ok=True)
            Path(evidence, name + '-' + result['id'] + '.json').write_text(json.dumps(history, indent=2))
        return result, history

    def test_success_empty_fanouts_then_retirement_and_terminal_stdout(self):
        self.cli('workflow', 'validate', 'operation-node-remove')
        self.cli('workflow', 'validate', 'node-role-remove')
        result, history = self.run_root('operation-node-remove', self.intent)
        self.assertEqual(result['status'], 'succeeded', history)
        output = json.loads(history['jobs'][-1]['steps'][-1]['outputs']['stdout'])
        self.assertEqual(output['node_id'], 701)
        self.assertTrue(output['removed'])
        self.assertEqual(output['roles_shed'], ['database'])
        self.assertTrue(self.audit.exists())

    def test_failed_role_child_prevents_every_retirement_and_result(self):
        self.role['stop_units'] = [{'ssh': self.ssh, 'input': {'unit': 'orbit-websocket.service'}}]
        self.native_env['SSH_FAIL'] = '1'
        result, history = self.run_root('operation-node-remove', self.intent, success=False)
        self.assertEqual(result['status'], 'failed')
        steps = {step['name']: step for job in history['jobs'] for step in job['steps']}
        self.assertEqual(steps['roles-0']['status'], 'failed')
        self.assertEqual(len(self.audit.read_text().splitlines()), 1)
        self.assertEqual(steps['result']['status'], 'skipped')
        self.assertFalse(any(s['status'] == 'succeeded' for n, s in steps.items() if n.startswith('worker_')))

    def test_exact209_placement_delivers_assets_through_remote_only_serve(self):
        for path in (self.repo / 'workflows').glob('workflow-*.yaml'):
            workflow = yaml.safe_load(path.read_text())
            workflow['target'] = 'orbit-node-209'
            for job in workflow['jobs']:
                for step in job['steps']:
                    step['target'] = 'orbit-node-209'
            path.write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli('access', 'token', 'mint', 'fixture-admin', '--principal', 'user:fixture')
        admin = self.repo / 'admin'
        admin.write_text(json.loads(self.cli('access', 'token', 'reveal', 'fixture-admin', '--yes').stdout)['token'])
        admin.chmod(0o600)
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        binary = os.environ.get('SWAMP_BIN', 'swamp')
        env = dict(os.environ, **self.native_env, SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1')
        for key in ('SWAMP_SERVER_URL', 'SWAMP_SERVE_URL', 'SWAMP_SERVER_TOKEN', 'SWAMP_SERVER_TOKEN_FILE',
                    'SWAMP_REPO_DIR', 'SWAMP_CA_CERT', 'SWAMP_EXTENSIONS_DIR'):
            env.pop(key, None)
        serve = worker = None
        with (self.repo / 'serve.log').open('w') as log, (self.repo / 'worker.log').open('w') as worker_log:
            try:
                serve = subprocess.Popen([binary, 'serve', '--host', '127.0.0.1', '--port', str(port),
                    '--auth-mode', 'token', '--admins', 'user:fixture', '--no-schedule', '--remote-only'],
                    cwd=self.repo, env=env, stdout=log, stderr=log)
                for _ in range(100):
                    try:
                        urllib.request.urlopen(f'http://127.0.0.1:{port}/ready', timeout=1).close()
                        break
                    except OSError:
                        self.assertIsNone(serve.poll())
                        time.sleep(.1)
                flags = ('--server', f'ws://127.0.0.1:{port}', '--token-file', str(admin))
                enrollment = self.repo / 'enrollment'
                enrollment.write_text(json.loads(self.cli('worker', 'token', 'create', 'orbit-node-209',
                    '--duration', '10m', *flags).stdout)['token'])
                enrollment.chmod(0o600)
                self.cli('access', 'token', 'mint', 'fixture-worker', '--principal', 'worker:orbit-node-209', *flags)
                access = self.repo / 'worker-access'
                access.write_text(json.loads(self.cli('access', 'token', 'reveal', 'fixture-worker', '--yes').stdout)['token'])
                access.chmod(0o600)
                worker = subprocess.Popen([binary, 'worker', 'connect', f'ws://127.0.0.1:{port}',
                    '--token-file', str(enrollment), '--server-token-file', str(access),
                    '--cache-dir', str(self.repo / 'remote-cache'), '--label', 'node=209'],
                    cwd=self.repo, env=env, stdout=worker_log, stderr=worker_log)
                public = {'operation_id': 9002, 'node_id': 701, 'kind': 'node.remove',
                          'execution_secrets': {}, 'intent': self.intent}
                result = json.loads(self.cli('workflow', 'run', 'operation-node-remove', '--input', json.dumps(public), *flags).stdout)
                self.assertEqual(result['status'], 'succeeded')
                history = json.loads(self.cli('workflow', 'history', 'get', result['id'], *flags).stdout)
                self.assertTrue(json.loads(history['jobs'][-1]['steps'][-1]['outputs']['stdout'])['removed'])
                assets = next(s for j in history['jobs'] for s in j['steps'] if s['name'] == 'assets')
                self.assertIn('remote-cache', assets['outputs']['probe'])
                evidence = os.environ.get('NODE_REMOVAL_NATIVE_EVIDENCE')
                if evidence:
                    Path(evidence, 'remote209-' + result['id'] + '.json').write_text(json.dumps(history, indent=2))
            finally:
                for process in (worker, serve):
                    if process is not None:
                        process.terminate()
                        try:
                            process.wait(timeout=10)
                        except subprocess.TimeoutExpired:
                            process.kill()
                            process.wait(timeout=5)

    def test_real_native_cli_revokes_only_departing_node_tokens(self):
        for node in (701, 702):
            self.cli('worker', 'token', 'create', f'orbit-node-{node}', '--duration', '10m')
            self.cli('access', 'token', 'mint', f'orbit-node-{node}-access', '--principal', f'worker:orbit-node-{node}')
        workflow_path = self.repo / 'workflows/workflow-operation-node-remove.yaml'
        workflow = yaml.safe_load(workflow_path.read_text())
        binary = shlex.quote(os.environ.get('SWAMP_BIN', 'swamp'))
        flags = ' --repo-dir ' + shlex.quote(str(self.repo)) + ' --json'
        commands = {
            'worker_tokens': binary + ' worker token list' + flags,
            'access_tokens': binary + ' access token list' + flags,
            'revoke_worker': binary + " worker token revoke '${{ self.token }}'" + flags,
            'revoke_access': binary + " access token revoke '${{ self.token }}'" + flags,
        }
        for job in workflow['jobs']:
            for step in job['steps']:
                if step['name'] in commands:
                    step['task']['inputs'] = {'run': commands[step['name']]}
        workflow_path.write_text(yaml.safe_dump(workflow, sort_keys=False))
        result, history = self.run_root('operation-node-remove', self.intent)
        self.assertEqual(result['status'], 'succeeded', history)
        workers = json.loads(self.cli('worker', 'token', 'list').stdout)
        access = json.loads(self.cli('access', 'token', 'list').stdout)
        self.assertEqual(next(t['state'] for t in workers if t['name'] == 'orbit-node-701'), 'revoked')
        self.assertEqual(next(t['state'] for t in workers if t['name'] == 'orbit-node-702'), 'unused')
        self.assertEqual(next(t['state'] for t in access if t['name'] == 'orbit-node-701-access'), 'revoked')
        self.assertEqual(next(t['state'] for t in access if t['name'] == 'orbit-node-702-access'), 'active')

    def publications(self):
        return [{'node_id': 702, 'control_node_id': 209, 'host_keys': [PIN],
                 'connection': {'nodeHost': 'survivor.test', 'nodeUser': 'fixture', 'nodePort': 22,
                                'nodeIdentityFile': '/fixture/public-only-identity', 'become': True},
                 'before': {'php': {}, 'caddy': {}, 'dns': {}},
                 'after': {'php': {}, 'caddy': {'caddy': {}}, 'dns': {}}}]

    def test_nonempty_real_php_caddy_dns_child_contracts(self):
        self.role['publications'] = self.publications()
        result, history = self.run_root('node-role-remove', self.role, offline=False)
        self.assertEqual(result['status'], 'succeeded', history)
        steps = {s['name']: s for j in history['jobs'] for s in j['steps']}
        for name in ('php-0', 'caddy-0', 'dns-0'):
            self.assertEqual(steps[name]['status'], 'succeeded')

    def test_missing_publication_connection_propagates_child_failure(self):
        self.role['publications'] = self.publications()
        del self.role['publications'][0]['connection']['nodeHost']
        result, history = self.run_root('operation-node-remove', self.intent, success=False)
        self.assertEqual(result['status'], 'failed')
        self.assertFalse(self.audit.exists())
        self.assertEqual(history['jobs'][-1]['status'], 'skipped')

    def test_offline_role_cleans_survivor_but_never_ssh_to_departing_target(self):
        other = copy.deepcopy(self.ssh)
        other['target']['host'] = 'survivor.test'
        self.role['stop_units'] = [
            {'ssh': self.ssh, 'input': {'unit': 'orbit-websocket.service'}},
            {'ssh': other, 'input': {'unit': 'orbit-cadvisor.service'}}]
        self.role['publications'] = self.publications()
        result, history = self.run_root('node-role-remove', self.role, offline=True)
        self.assertEqual(result['status'], 'succeeded', history)
        self.assertEqual(len(self.audit.read_text().splitlines()), 1)
        output = history['jobs'][-1]['steps'][-1]['outputs']
        self.assertTrue(output['offline'])

    def test_child_refuses_control_node_without_ssh(self):
        self.role['node_id'] = 209
        result, _ = self.run_root('node-role-remove', self.role, success=False, offline=False)
        self.assertEqual(result['status'], 'failed')
        self.assertFalse(self.audit.exists())


if __name__ == '__main__':
    unittest.main()
