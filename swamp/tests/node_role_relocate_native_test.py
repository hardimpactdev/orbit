"""node.role_relocate on the actual DAG behind official SSH; never contacts a fleet Node.

The fake `ssh` runs nothing: it logs each argv with a digest of its stdin, so the test sees the exact commands,
their order and that Reverb's environment arrives only on stdin. The Metrics role, certificate, Caddy, DNS,
grace and node:role:remove children are stubs that log their call; their own suites prove them.
"""
import copy
import hashlib
import json
import shutil
import sys
import tempfile
import uuid
from pathlib import Path

import yaml
from native_harness import ROOT, NativeTestCase

WORKFLOW = 'operation-node-role-relocate'
SECRET = 'APP_KEY=base64:relocate-fixture-private-value\nREVERB_REGISTRY_TOKEN=relocate-fixture-private-token\n'
PIN = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND'
TARGETED = {
    'operation-metrics-enable': '${{ string(inputs.node_id) }}',
    'operation-node-role-remove-direct': '${{ string(inputs.node_id) }} ${{ string(inputs.intent.node_id) }} ${{ inputs.intent.ssh.target.host }}',
}
CHILDREN = ['operation-metrics-enable', 'node-role-certificate-publish', 'operation-route-caddy', 'route-dns-publish',
            'route-dns-grace', 'operation-node-role-remove-direct']

FAKE = '''import hashlib,json,shlex,sys
from pathlib import Path
if '-M' in sys.argv or '-O' in sys.argv:raise SystemExit(0)
args=shlex.split(sys.argv[-1]);body=sys.stdin.read()
sudo=args[:3]==['sudo','-n','--']
if sudo:args=args[3:]
assert args[0]=='timeout',args
with Path(CALLS).open('a') as f:f.write(json.dumps({'argv':args[2:],'sudo':sudo,'stdin':hashlib.sha256(body.encode()).hexdigest() if body else None})+'\\n')
failure=Path(FAILURE).read_text() if Path(FAILURE).exists() else ''
if failure and failure in args[2:]:raise SystemExit(1)
if args[2]=='curl':print('000' if failure=='silent' else '426',end='')
'''


class RoleRelocateNative(NativeTestCase):
    native_timeout = 180

    def setUp(self):
        super().setUp()
        inventory = json.loads((ROOT / 'extensions/models/upstream_extensions.json').read_text())
        self.cli('extension', 'pull', '@swamp/ssh@' + inventory['@swamp/ssh']['version'])
        # Logs live outside the scanned repository; they hold digests, never stdin.
        self.outside = Path(tempfile.mkdtemp(prefix='orbit-relocate-calls-'))
        self.addCleanup(shutil.rmtree, self.outside, True)
        self.calls = self.outside / 'calls.jsonl'
        self.children = self.outside / 'children.jsonl'
        self.targets = self.outside / 'targets.txt'
        self.failure = self.outside / 'failure'
        fake = self.repo / 'ssh'
        fake.write_text('#!' + sys.executable + '\n' + FAKE.replace('CALLS', repr(str(self.calls))).replace('FAILURE', repr(str(self.failure))))
        fake.chmod(0o700)
        self.flow = yaml.safe_load((ROOT / 'workflows' / ('workflow-' + WORKFLOW + '.yaml')).read_text())
        local = copy.deepcopy(self.flow)
        for job in local['jobs']:
            for step in job['steps']:
                self.assertEqual(step.pop('labels'), {'role': 'swamp'})
                if step['task'].get('behaviorType') == '@swamp/ssh':
                    step['task']['globalArgs'] = {**step['task']['globalArgs'], 'sshBinary': str(fake)}
        (self.repo / 'workflows' / ('workflow-' + WORKFLOW + '.yaml')).write_text(yaml.safe_dump(local, sort_keys=False))
        for child in CHILDREN:
            fail = str(self.outside / ('fail-' + child))
            stub = {'id': str(uuid.uuid4()), 'name': child, 'inputs': {'type': 'object'}, 'jobs': [{'name': 'stub', 'steps': [{
                'name': 'record', 'task': {'type': 'behavior', 'behaviorType': 'command/shell', 'behaviorName': 'stub-' + child, 'methodName': 'execute',
                                           'inputs': {'run': "printf '%s\\n' '" + child + "' >> '" + str(self.children) + "' && test ! -e '" + fail + "'"}}}]}]}
            if child in TARGETED:
                # Record the Node each targeted child acts on, so a mixed-up source shows here.
                record = TARGETED[child]
                stub['jobs'][0]['steps'][0]['task']['inputs']['run'] = ("printf '%s\\n' '" + child + ' ' + record + "' >> '" + str(self.targets) + "' && "
                                                                        + stub['jobs'][0]['steps'][0]['task']['inputs']['run'])
            (self.repo / 'workflows' / ('workflow-' + child + '.yaml')).write_text(yaml.safe_dump(stub, sort_keys=False))
        identity = self.repo / 'identity'
        identity.write_text('fixture SSH identity')
        self.ssh = {'identity_file': str(identity), 'target': {'name': 'target', 'host': 'fixture.invalid', 'user': 'orbit', 'port': 22, 'host_keys': [PIN]}}
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        self.cli('vault', 'put', 'orbit-control-inputs', 'node-role-relocate-operation-12', input=SECRET)
        self.secrets = (SECRET.strip().split('\n')[0], 'relocate-fixture-private-token')

    def intent(self, role):
        websocket = None
        if role == 'websocket':
            websocket = {'user': 'orbit', 'install_path': '/opt/orbit/websocket', 'releases': '/opt/orbit/websocket.releases',
                         'release': '/opt/orbit/websocket.releases/release.' + 'a' * 32, 'repository': 'https://github.com/hardimpactdev/orbit-reverb.git',
                         'ref': 'f15545c04a30ef26cf3a11dd675668ced217f394', 'port': 8790,
                         'unit': {'path': '/etc/systemd/system/orbit-websocket.service', 'content': '[Unit]\nDescription=Orbit websocket role\n'}}
        gateway = None
        if role == 'gateway':
            gateway = {'drop_in': {'path': '/etc/systemd/system/wg-quick@orbit.service.d/orbit-gateway-dns.conf', 'content': '# Managed by Orbit.\n[Service]\n'},
                       'route': [['resolvectl', 'domain', 'orbit', '~orbit'], ['resolvectl', 'default-route', 'orbit', 'false'], ['resolvectl', 'dns', 'orbit', '10.44.0.1']]}
        publication = {'node_id': 12, 'control_node_id': 209, 'host_keys': [PIN], 'connection': {'nodeUser': 'orbit'},
                       'before': {'caddy': None, 'php': None, 'dns': {'records': []}}, 'after': {'caddy': {'content': 'site'}, 'php': None, 'dns': {'records': ['reverb.orbit']}}}
        rules = [['ufw', 'allow', 'in', 'on', 'orbit', 'to', 'any', 'port', '443', 'proto', 'tcp', 'comment', 'orbit:' + role + '-https']]
        return {'schema_version': 1, 'role': role, 'assignment_id': 5, 'target_node_id': 12, 'source_node_id': 7, 'ssh': self.ssh,
                'metrics': {'enabled': True} if role == 'metrics' else None, 'websocket': websocket, 'gateway': gateway,
                'certificates': {'leaves': [{'node_id': 12, 'scope': 'role-websocket', 'ssh': {'name': 'relocate-certificate-12'}}] if role == 'websocket' else [], 'withdrawals': []},
                'firewall': {'desired': [] if role == 'metrics' else rules, 'remove': [] if role == 'metrics' else [['ufw', '--force', 'delete', 'allow', '22/tcp']]},
                'publications': [] if role == 'metrics' else [publication],
                'grace': [{'seconds': 31}] if role == 'websocket' else [], 'metrics_converge': None,
                'source': {'node_id': 7, 'ssh': {'target': {'host': '192.0.2.7'}}}}

    def run_relocate(self, role, failure=None, failed_child=None, success=True):
        for path in (self.calls, self.children, self.failure, self.targets):
            path.unlink(missing_ok=True)
        for child in CHILDREN:
            (self.outside / ('fail-' + child)).unlink(missing_ok=True)
        if failure:
            self.failure.write_text(failure)
        if failed_child:
            (self.outside / ('fail-' + failed_child)).write_text('')
        secrets = {'websocket_env': {'vault': 'orbit-control-inputs', 'key': 'node-role-relocate-operation-12'}} if role == 'websocket' else {}
        inputs = {'operation_id': 12, 'node_id': 12, 'kind': 'node.role_relocate', 'intent': self.intent(role), 'execution_secrets': secrets}
        self.cli('workflow', 'validate', WORKFLOW)
        run = json.loads(self.cli('workflow', 'run', WORKFLOW, '--input', json.dumps(inputs), success=success).stdout)
        history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        steps = {step['name']: step['status'] for job in history['jobs'] for step in job['steps']}
        calls = [json.loads(line) for line in self.calls.read_text().splitlines()] if self.calls.exists() else []
        children = self.children.read_text().split() if self.children.exists() else []
        return run, steps, calls, children

    @staticmethod
    def status(steps, name):
        return [status for key, status in steps.items() if key == name or key.startswith(name + '-')]

    def test_websocket_builds_the_release_on_the_target_before_anything_leaves_the_source(self):
        run, steps, calls, children = self.run_relocate('websocket')
        self.assertEqual(run['status'], 'succeeded')
        self.assertEqual(steps['Confirm the move'], 'succeeded')
        self.assertEqual(steps['Apply the Metrics role'], 'skipped')
        release = self.intent('websocket')['websocket']['release']
        self.assertEqual([c['argv'][:2] for c in calls], [
            ['install', '-d'], ['git', 'clone'], ['git', '-C'], ['composer', 'install'], ['install', '-m'], ['install', '-m'],
            ['ln', '-sfnT'], ['systemctl', 'daemon-reload'], ['systemctl', 'enable'], ['systemctl', 'restart'],
            ['ufw', 'allow'], ['ufw', '--force'], ['curl', '--silent']])
        self.assertEqual(calls[1]['argv'][-1], release)
        self.assertEqual(calls[2]['argv'][-1], 'f15545c04a30ef26cf3a11dd675668ced217f394')
        # The role's user is the SSH user: the release is built without sudo, the unit and link with it.
        self.assertEqual([c['sudo'] for c in calls[:7]], [True, False, False, False, False, True, True])
        env, unit = calls[4], calls[5]
        self.assertEqual(env['argv'][-1], release + '/.env')
        self.assertEqual(env['stdin'], hashlib.sha256(SECRET.encode()).hexdigest())
        self.assertEqual(unit['stdin'], hashlib.sha256(self.intent('websocket')['websocket']['unit']['content'].encode()).hexdigest())
        self.assertEqual(calls[6]['argv'][-2:], [release, '/opt/orbit/websocket'])
        self.assertEqual(children, ['node-role-certificate-publish', 'operation-route-caddy', 'route-dns-publish', 'route-dns-grace',
                                    'operation-node-role-remove-direct'])

    def test_a_failed_release_leaves_the_source_serving(self):
        run, steps, calls, children = self.run_relocate('websocket', failure='composer', success=False)
        self.assertEqual(run['status'], 'failed')
        self.assertEqual(self.status(steps, 'Install Reverb packages'), ['failed'])
        self.assertEqual(calls[-1]['argv'][0], 'composer')
        self.assertNotIn('operation-node-role-remove-direct', children)
        self.assertNotEqual(steps.get('Confirm the move'), 'succeeded')

    def test_a_silent_reverb_fails_the_end_check(self):
        run, steps, _calls, children = self.run_relocate('websocket', failure='silent', success=False)
        self.assertEqual(run['status'], 'failed')
        self.assertIn('operation-node-role-remove-direct', children)
        self.assertEqual(steps['Confirm the move'], 'failed')

    def test_gateway_writes_its_route_and_a_failed_resolver_is_best_effort(self):
        run, steps, calls, children = self.run_relocate('gateway', failure='default-route')
        self.assertEqual(run['status'], 'succeeded')
        self.assertEqual(steps['Confirm the move'], 'succeeded')
        argv = [c['argv'] for c in calls]
        self.assertEqual(argv[0][:4], ['install', '-D', '-m', '0644'])
        self.assertEqual(argv[1], ['systemctl', 'daemon-reload'])
        # Domain and default-route come before the server, one at a time.
        self.assertEqual([a[1] for a in argv[2:5]], ['domain', 'default-route', 'dns'])
        self.assertTrue(any(name.startswith('Route the private domain') and status == 'failed' for name, status in steps.items()))
        self.assertNotIn('route-dns-grace', children)
        self.assertEqual(children[-1], 'operation-node-role-remove-direct')

    def test_metrics_uses_its_role_workflow_and_touches_no_node_itself(self):
        run, _steps, calls, children = self.run_relocate('metrics')
        self.assertEqual(run['status'], 'succeeded')
        self.assertEqual(calls, [])
        self.assertEqual(children, ['operation-metrics-enable', 'operation-node-role-remove-direct'])
        # The destination gets the role; the removal child acts on the source Node and its address only.
        self.assertEqual(self.targets.read_text().splitlines(),
                         ['operation-metrics-enable 12', 'operation-node-role-remove-direct 7 7 192.0.2.7'])

    def test_a_failed_source_removal_fails_the_move(self):
        run, steps, _calls, _children = self.run_relocate('metrics', failed_child='operation-node-role-remove-direct', success=False)
        self.assertEqual(run['status'], 'failed')
        self.assertEqual(steps['Remove from the source'], 'failed')
        self.assertNotEqual(steps.get('Confirm the move'), 'succeeded')

    def test_behaviors_are_named_by_target_and_every_step_runs_on_swamp(self):
        names = []
        for job in self.flow['jobs']:
            for step in job['steps']:
                self.assertEqual(step['labels'], {'role': 'swamp'})
                self.assertNotIn('target', step)
                if step['task'].get('behaviorType') == '@swamp/ssh':
                    names.append(step['task']['behaviorName'])
                    self.assertTrue(step.get('writes') or step['name'] == 'Check Reverb', step['name'])
        self.assertTrue(all('inputs.intent.target_node_id' in name and 'run.id' not in name and 'operation_id' not in name for name in names))
        self.assertEqual(len(set(names)), len(names))
