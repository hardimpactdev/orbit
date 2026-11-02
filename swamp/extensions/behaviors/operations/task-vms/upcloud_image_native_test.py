"""Actual native UpCloud template DAG on official e459: inline curl from 209 against a disposable provider API,
and official SSH/SCP against disposable process boundaries. No server, guest or fleet is touched.

A `curl` fixture first on PATH checks that no vault value is in its argv, drops `--proto =https`, points the fixed
UpCloud origin at the local server and runs the real curl, so its own `--variable` expansion builds the requests.
"""
import hashlib
import http.server
import json
import os
import shutil
import sys
import threading
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

PIN = 'a' * 64
IDENTITY = 'sha256:' + 'b' * 64
STAMP = '20261102120000'
NAME = 'tvm-image-' + STAMP
BOOT = '878a384f-8b8a-4066-bd17-dcad8eac3e86'
SOURCE = '01000000-0000-4000-8000-000030260200'
HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG ' + NAME
TOKEN = 'ucat_fixture_secret_token_3f31c'
PRIVATE = '-----BEGIN OPENSSH PRIVATE KEY-----\nfixture-secret-host-key\n-----END OPENSSH PRIVATE KEY-----\n'
PHASES = ['apt-source', 'packages', 'javascript', 'rust', 'agent', 'pi']
WORKFLOWS = ['operation-task-vm-upcloud-image-build', 'task-vm-upcloud-image-server', 'task-vm-upcloud-image-pi',
             'task-vm-upcloud-image-phases', 'task-vm-upcloud-image-prepare']

# The fake guest answers each remote argv the way the build servers would. Stdin is recorded as parsed JSON.
FAKE_SSH = r'''
import json, os, shlex, sys
from pathlib import Path
argv = shlex.split(sys.argv[-1])
body = sys.stdin.read() if not sys.stdin.isatty() else ''
state_path = Path(STATE)
state = json.loads(state_path.read_text())
call = {'argv': argv, 'ssh': sys.argv[1:-1]}
if body.strip().startswith('{'):
    call['stdin'] = json.loads(body)
state['calls'].append(call)
state_path.write_text(json.dumps(state))
def reply(data):
    print(json.dumps({'data': data, 'ok': True}, sort_keys=True))
if 'command' in argv and argv[argv.index('command') - 1].split('/')[-1].startswith('orbit-agent'):
    name = argv[argv.index('command') + 1]
    request = call['stdin']['input']
    if name == os.environ.get('FIXTURE_REFUSE'):
        print(json.dumps({'error': {'code': 'agent.version_mismatch', 'message': 'refused'}, 'ok': False}, sort_keys=True))
    elif name == 'agent.install':
        reply({'sha256': request['sha256'], 'replaced': True})
    elif name == 'image.inspect-phase':
        reply({'boot_id': BOOT, 'unit': 'u', 'state': 'missing' if request['boot_id'] is None else 'complete'})
    elif name == 'image.start-phase':
        reply({'boot_id': request['boot_id'], 'unit': 'u', 'state': 'running'})
    else:
        reply({'ready': True})
elif '--property=MainPID' in argv:
    print('4242')
'''


class UpCloudImageNative(NativeTestCase):
    native_timeout = 900

    def setUp(self):
        super().setUp()
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        module = self.repo / 'extensions/behaviors/operations/task-vms'
        module.mkdir(parents=True)
        for name in ('upcloud_image_api.ts', 'image_build_inputs.ts', 'images.ts'):
            shutil.copy(ROOT / 'extensions/behaviors/operations/task-vms' / name, module)
        self.serve_provider()
        self.state = self.repo / 'guest.json'
        self.state.write_text(json.dumps({'calls': []}))
        fake = self.repo / 'ssh'
        fake.write_text('#!' + sys.executable + '\n' + FAKE_SSH.replace('STATE', repr(str(self.state))).replace('BOOT', repr(BOOT)))
        fake.chmod(0o755)
        self.copies = self.repo / 'copies.jsonl'
        scp = self.repo / 'scp'
        scp.write_text('#!' + sys.executable + '\nimport os,sys,json\nfrom pathlib import Path\n'
                       'Path(' + repr(str(self.copies)) + ').open("a").write(json.dumps(sys.argv[1:])+"\\n")\n'
                       'dst=sys.argv[-1]\nif ":" not in dst:Path(dst).write_bytes(os.environ["FIXTURE_BUILD"].encode())\n')
        scp.chmod(0o755)
        self.agent = self.repo / 'orbit-agent'
        self.agent.write_text('pinned agent fixture')
        for name in WORKFLOWS:
            source = yaml.safe_load((ROOT / f'workflows/workflow-{name}.yaml').read_text())
            for job in source['jobs']:
                self.assertNotIn('labels', job)
                for step in job['steps']:
                    self.assertEqual(step.pop('labels'), {'role': 'swamp'}, step['name'])
                    task = step['task']
                    if task.get('behaviorType') == '@swamp/ssh':
                        task['globalArgs']['sshBinary'] = str(fake)
                        task['globalArgs']['scpBinary'] = str(scp)
                        if task['inputs'].get('src') == '/usr/local/bin/orbit-agent':
                            task['inputs']['src'] = str(self.agent)
            (self.repo / f'workflows/workflow-{name}.yaml').write_text(yaml.safe_dump(source, sort_keys=False))
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        self.cli('vault', 'put', 'orbit-control-inputs', 'task-vm-upcloud-token', input=TOKEN)
        self.cli('vault', 'put', 'orbit-control-inputs', 'task-vm-image-bootstrap-9', input=PRIVATE)
        self.secrets = (TOKEN, 'fixture-secret-host-key')
        self.build = 'pinned pi build'
        self.native_env.update(FIXTURE_BUILD=self.build)
        key = lambda phase: {'identity': IDENTITY, 'profile': 'base', 'phase': phase, 'execution': 'upcloud-9'}
        envelope = lambda seconds, data: json.dumps({'agent_sha256': PIN, 'deadline_ms': seconds * 1000, 'input': data}) + '\n'
        recipe = {'identity': IDENTITY, 'agent_sha256': PIN, 'pi_sha256': None}
        gateway = {'control_node_id': 209, 'identity_file': '/fixture/key', 'public_key': 'ssh-ed25519 WORKER',
                   'target': {'name': 'target', 'host': '192.0.2.14', 'user': 'orbit', 'port': 22, 'host_keys': [HOST_KEY]}}
        self.intent = {
            'build_id': 9, 'node_id': 14, 'identity': IDENTITY, 'generation': 1, 'stamp': STAMP, 'name': NAME,
            'source': SOURCE, 'agent_sha256': PIN, 'recipe': recipe,
            'upcloud': {'zone': 'nl-ams1', 'plan': 'STARTER-2xCPU-4GB', 'disk_gb': 30}, 'pinned_templates': [self.uuid(52)],
            'host_key': HOST_KEY, 'ssh': {'identity_file': '/etc/orbit/swamp-role-worker/ssh-identity', 'public_key': 'ssh-ed25519 WORKER'},
            'pi_artifact': None, 'pi_source': gateway, 'candidate': '/var/tmp/orbit-agent-candidate-' + PIN,
            'envelopes': {'install': envelope(180, {'candidate': '/var/tmp/orbit-agent-candidate-' + PIN, 'sha256': PIN}),
                          'cloud_init': envelope(45, {}), 'owner': envelope(120, {}),
                          'inspect_boot': envelope(30, {'key': key('apt-source'), 'boot_id': None}),
                          'marker': envelope(120, {'identity': IDENTITY, 'profile': 'base'}), 'verify': envelope(120, recipe),
                          'boot': envelope(120, {'lean': False}), 'firewall': envelope(120, {}), 'clean': envelope(120, {}),
                          'check': envelope(120, {'lean_boot': False, 'firewall': True})},
            'phase_keys': {phase: key(phase) for phase in PHASES},
            'units': {phase: 'orbit-recipe-image-' + hashlib.sha256(f'{IDENTITY}:base:{phase}:upcloud-9'.encode()).hexdigest()[:32] + '.service'
                      for phase in PHASES},
            'template_data': json.dumps({'storage': {'title': f'orbit-task-base-{STAMP}-unverified'}}),
            'promote_data': json.dumps({'storage': {'title': f'orbit-task-base-{STAMP}', 'labels': [
                {'key': 'orbit.recipe', 'value': IDENTITY}, {'key': 'orbit-task-image', 'value': STAMP}]}}),
        }

    @staticmethod
    def uuid(n):
        return f'00000000-0000-4000-8000-{n:012d}'

    def serve_provider(self):
        self.servers, self.templates, self.requests, self.bodies = {}, {}, [], []
        self.stop_lag = False
        # Older verified templates: 50 is the newest kept, 51 goes, 52 stays because a live VM pins it.
        for n, at in ((50, '20261101120000'), (51, '20261031120000'), (52, '20261030120000')):
            self.templates[self.uuid(n)] = {'uuid': self.uuid(n), 'title': 'orbit-task-base-' + at, 'access': 'private', 'state': 'online',
                                            'labels': [{'key': 'orbit.recipe', 'value': IDENTITY}, {'key': 'orbit-task-image', 'value': at}]}
        owner = self

        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def answer(self, status, value):
                self.send_response(status)
                self.end_headers()
                self.wfile.write(json.dumps(value).encode())

            def body(self):
                length = int(self.headers.get('Content-Length', '0'))
                return json.loads(self.rfile.read(length)) if length else None

            def handle_any(self, method):
                body = self.body()
                owner.requests.append((method, self.path))
                if self.headers.get('Authorization') != 'Bearer ' + TOKEN:
                    return self.answer(403, {})
                path = self.path.removeprefix('/1.3/')
                if method == 'GET' and path == 'plan':
                    return self.answer(200, {'plans': {'plan': [{'name': 'STARTER-2xCPU-4GB', 'storage_tier': 'standard'}]}})
                if method == 'GET' and path == 'server?label=orbit-task-image':
                    return self.answer(200, {'servers': {'server': list(owner.servers.values())}})
                if method == 'GET' and path == 'storage/template':
                    return self.answer(200, {'storages': {'storage': list(owner.templates.values())}})
                if method == 'POST' and path == 'server':
                    owner.bodies.append(body)
                    source = body['server']['storage_devices']['storage_device'][0]['storage']
                    if source in owner.templates and owner.templates[source]['state'] != 'online':
                        owner.templates[source]['state'] = 'online'
                        return self.answer(409, {'error': {'error_code': 'STORAGE_STATE_ILLEGAL'}})
                    n = 60 + len(owner.servers)
                    row = {'uuid': owner.uuid(n), 'title': body['server']['title'], 'hostname': body['server']['hostname'],
                           'state': 'started', 'labels': body['server']['labels'],
                           'ip_addresses': {'ip_address': [{'access': 'public', 'family': 'IPv4', 'address': f'93.184.216.{n}'}]},
                           'storage_devices': {'storage_device': [{'storage': owner.uuid(n + 100), 'type': 'disk'}]}}
                    owner.servers[row['uuid']] = row
                    return self.answer(202, {'server': row})
                if method == 'POST' and path.endswith('/stop'):
                    row = owner.servers[path.split('/')[1]]
                    owner.bodies.append(body)
                    row['state'] = 'maintenance' if owner.stop_lag else 'stopped'
                    return self.answer(200, {'server': row})
                if method == 'POST' and path.endswith('/templatize'):
                    owner.bodies.append(body)
                    disk = path.split('/')[1]
                    row = next(row for row in owner.servers.values() if row['storage_devices']['storage_device'][0]['storage'] == disk)
                    if row['state'] != 'stopped':
                        row['state'] = 'stopped'
                        return self.answer(409, {'error': {'error_code': 'SERVER_STATE_ILLEGAL'}})
                    template = {'uuid': owner.uuid(70), 'title': body['storage']['title'], 'access': 'private', 'state': 'maintenance'}
                    owner.templates[template['uuid']] = template
                    return self.answer(201, {'storage': template})
                if method == 'PUT' and path.startswith('storage/'):
                    owner.bodies.append(body)
                    owner.templates[path.split('/')[1]].update(body['storage'])
                    return self.answer(202, {'storage': owner.templates[path.split('/')[1]]})
                if method == 'DELETE' and path.startswith('server/'):
                    uuid = path.split('/')[1].split('?')[0]
                    if owner.servers[uuid]['state'] != 'stopped':
                        owner.servers[uuid]['state'] = 'stopped'
                        return self.answer(409, {'error': {'error_code': 'SERVER_STATE_ILLEGAL'}})
                    del owner.servers[uuid]
                    return self.answer(204, {})
                if method == 'DELETE' and path.startswith('storage/'):
                    del owner.templates[path.split('/')[1]]
                    return self.answer(204, {})
                return self.answer(404, {})

            def do_GET(self):
                self.handle_any('GET')

            def do_POST(self):
                self.handle_any('POST')

            def do_PUT(self):
                self.handle_any('PUT')

            def do_DELETE(self):
                self.handle_any('DELETE')

        server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(thread.join)
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        tools = self.repo / 'tools'
        tools.mkdir()
        curl = tools / 'curl'
        curl.write_text('#!' + sys.executable + f'''
import os,sys
args=sys.argv[1:]
# Vault values reach curl only from its environment, through --variable.
assert args[args.index("--variable")+1]=="%ORBIT_UPCLOUD_TOKEN",args
for name in ("ORBIT_UPCLOUD_TOKEN","ORBIT_HOST_PRIVATE_KEY"):
    if os.environ.get(name):assert os.environ[name] not in " ".join(args),name
i=args.index("--proto");assert args[i+1]=="=https",args;del args[i:i+2]
assert args[-1].startswith("https://api.upcloud.com/1.3/"),args
args[-1]=args[-1].replace("https://api.upcloud.com/1.3","http://127.0.0.1:{server.server_port}/1.3")
os.execv({shutil.which("curl")!r},["curl",*args])
''')
        curl.chmod(0o755)
        self.native_env.update(PATH=str(tools) + ':' + os.environ['PATH'])

    def run_build(self, success=True):
        run = json.loads(self.cli('workflow', 'run', WORKFLOWS[0], '--input', json.dumps({
            'operation_id': 7001, 'node_id': 14, 'kind': 'task_vm.upcloud_image_build', 'intent': self.intent,
            'execution_secrets': {'upcloud': {'vault': 'orbit-control-inputs', 'key': 'task-vm-upcloud-token'},
                                  'bootstrap': {'vault': 'orbit-control-inputs', 'key': 'task-vm-image-bootstrap-9'}}}),
            success=success).stdout)
        history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        steps = {step['name']: step for job in history['jobs'] for step in job['steps']}
        calls = json.loads(self.state.read_text())['calls']
        self.assert_private_absent(json.dumps(history))
        return run, steps, calls

    def agent_calls(self, calls):
        return [(call['argv'][call['argv'].index('command') + 1], call) for call in calls if 'command' in call['argv']]

    def test_build_launches_boots_templates_smokes_promotes_and_cleans_from_209(self):
        for name in WORKFLOWS:
            self.cli('workflow', 'validate', name)
        self.stop_lag = True
        run, steps, calls = self.run_build()
        self.assertEqual(run['status'], 'succeeded')
        self.assertEqual(steps['Check the template']['status'], 'succeeded')
        self.assertEqual(steps['Stage the Pi build']['status'], 'skipped')
        self.assertEqual(steps['Read the template']['outputs']['template_uuid'], self.uuid(70))
        # Creates carry the vault host key through curl's own expansion; the run history never holds it.
        # The smoke create is refused once while UpCloud still prepares the template; curl sends it again.
        creates = [body['server'] for body in self.bodies if body and 'server' in body and 'zone' in body['server']]
        self.assertEqual(len(creates), 3)
        builder, smoke = creates[0], creates[-1]
        self.assertEqual((builder['title'], smoke['title']), (NAME, NAME + '-smoke'))
        self.assertIn('fixture-secret-host-key', builder['user_data'])
        self.assertEqual(builder['storage_devices']['storage_device'][0]['storage'], SOURCE)
        self.assertEqual(smoke['storage_devices']['storage_device'][0]['storage'], self.uuid(70))
        # The guest waits on OpenSSH's connection retries; every connection pins the admitted host key.
        boot = [call for call in calls if '/usr/bin/cloud-init' in call['argv']]
        self.assertEqual(len(boot), 2)
        self.assertEqual(boot[0]['argv'], ['timeout', '900', '/usr/bin/sudo', '-n', '--', '/usr/bin/cloud-init', 'status', '--wait'])
        self.assertIn('ConnectionAttempts=90', boot[0]['ssh'])
        names = [name for name, _call in self.agent_calls(calls)]
        self.assertEqual(names, [
            'agent.install', 'guest.cloud-init', 'image.owner', 'image.inspect-phase',
            *['image.start-phase', 'image.inspect-phase'] * 5,
            'image.recipe-marker', 'image.verify', 'image.boot', 'image.firewall', 'image.clean',
            'agent.install', 'guest.cloud-init', 'image.owner', 'image.inspect-phase', 'image.verify', 'image.check'])
        for name, call in self.agent_calls(calls):
            self.assertEqual(call['argv'][0], 'timeout', name)
            self.assertEqual(call['stdin']['agent_sha256'], PIN, name)
        owner = next(call for name, call in self.agent_calls(calls) if name == 'image.owner')
        self.assertEqual(owner['argv'], ['timeout', '150', '/usr/local/bin/orbit-agent', 'command', 'image.owner'])
        starts = [call['stdin']['input'] for name, call in self.agent_calls(calls) if name == 'image.start-phase']
        self.assertEqual([start['key']['phase'] for start in starts], PHASES[:5])
        self.assertTrue(all(start['boot_id'] == BOOT and start['key']['execution'] == 'upcloud-9' for start in starts))
        tails = [call['argv'] for call in calls if '/usr/bin/tail' in call['argv']]
        self.assertEqual(tails[0], ['timeout', '1500', '/usr/bin/tail', '--pid=4242', '-f', '/dev/null'])
        self.assertEqual(steps['Run the phases']['status'], 'succeeded')
        # Stop, then templatize retried by curl until the server stopped; the promoted template carries both labels.
        order = [(method, path.split('?')[0].removeprefix('/1.3/')) for method, path in self.requests if method != 'GET']
        builder_uuid, smoke_uuid = self.uuid(60), self.uuid(61)
        self.assertEqual(order[:6], [
            ('POST', 'server'), ('POST', f'server/{builder_uuid}/stop'), ('POST', f'storage/{self.uuid(160)}/templatize'),
            ('POST', f'storage/{self.uuid(160)}/templatize'), ('POST', 'server'), ('POST', 'server')])
        self.assertEqual(order[6], ('PUT', f'storage/{self.uuid(70)}'))
        self.assertEqual(self.templates[self.uuid(70)]['title'], 'orbit-task-base-' + STAMP)
        # Cleanup: both servers stop and go; template 51 is pruned, 50 is among the two newest, 52 is pinned.
        self.assertEqual(sorted(self.templates), [self.uuid(50), self.uuid(52), self.uuid(70)])
        self.assertEqual(self.servers, {})
        self.assertIn(('POST', f'server/{smoke_uuid}/stop'), order)
        self.assertEqual(steps['Delete servers, templates-0']['status'], 'succeeded')
        # Only the fixed provider origin is reached: no Gateway request from any step.
        self.assertTrue(all(path.startswith('/1.3/') for _method, path in self.requests))

    def test_pi_build_reaches_the_builder_cache_through_209(self):
        digest = hashlib.sha256(self.build.encode()).hexdigest()
        self.intent['recipe'] = {**self.intent['recipe'], 'pi_sha256': digest}
        self.intent['pi_artifact'] = {'path': '/srv/orbit/sandbox-artifacts/pi', 'sha256': digest}
        run, _steps, calls = self.run_build()
        self.assertEqual(run['status'], 'succeeded')
        copies = [json.loads(line) for line in self.copies.read_text().splitlines()]
        fetched = [copy for copy in copies if copy[-2].endswith(':/srv/orbit/sandbox-artifacts/pi')]
        self.assertEqual(len(fetched), 1)
        self.assertIn('192.0.2.14', fetched[0][-2])
        install = next(call['argv'] for call in calls if '/usr/bin/install' in call['argv'])
        self.assertEqual(install[-2:], [f'/tmp/orbit-image-pi-{digest}.bin', f'/var/cache/orbit/pi/{digest}.bin'])
        self.assertEqual(install[:13], ['timeout', '60', '/usr/bin/sudo', '-n', '--', '/usr/bin/install', '-D', '-m', '0600', '-o', 'root', '-g', 'root'])
        starts = [call['stdin']['input']['key']['phase'] for name, call in self.agent_calls(calls) if name == 'image.start-phase']
        self.assertEqual(starts, PHASES)

    def test_a_tampered_pi_build_never_reaches_the_builder(self):
        digest = hashlib.sha256(self.build.encode()).hexdigest()
        self.intent['recipe'] = {**self.intent['recipe'], 'pi_sha256': digest}
        self.intent['pi_artifact'] = {'path': '/srv/orbit/sandbox-artifacts/pi', 'sha256': digest}
        self.native_env['FIXTURE_BUILD'] = 'tampered build'
        run, steps, calls = self.run_build(success=False)
        self.assertEqual(run['status'], 'failed')
        self.assertEqual(steps['Stage the Pi build']['status'], 'failed')
        self.assertEqual(steps['Run the phases']['status'], 'skipped')
        self.assertFalse(any('/usr/bin/install' in call['argv'] for call in calls))
        self.assertEqual(self.servers, {})

    def test_a_refused_smoke_check_keeps_promotion_out_and_removes_servers_and_template(self):
        self.native_env['FIXTURE_REFUSE'] = 'image.check'
        run, steps, _calls = self.run_build(success=False)
        self.assertEqual(run['status'], 'failed')
        self.assertEqual(steps['Smoke the template']['status'], 'failed')
        # The parent step carries the child's public refusal code, which the Gateway maps to the error code.
        self.assertEqual(steps['Smoke the template']['error'], 'agent.version_mismatch')
        self.assertEqual(steps['Promote the template']['status'], 'skipped')
        self.assertEqual(steps['Check the template']['status'], 'skipped')
        self.assertEqual(steps['Plan the cleanup']['outputs']['promoted'], False)
        self.assertNotIn(('PUT', f'/1.3/storage/{self.uuid(70)}'), self.requests)
        # The unverified template and both servers go; every verified template stays.
        self.assertEqual(sorted(self.templates), [self.uuid(50), self.uuid(51), self.uuid(52)])
        self.assertEqual(self.servers, {})

    def test_an_agent_refusal_on_the_builder_makes_no_template(self):
        self.native_env['FIXTURE_REFUSE'] = 'image.clean'
        run, steps, _calls = self.run_build(success=False)
        self.assertEqual(run['status'], 'failed')
        self.assertEqual(steps['Prepare and clean']['status'], 'failed')
        self.assertEqual(steps['Make the template']['status'], 'skipped')
        self.assertFalse(any(path.endswith('/templatize') for _method, path in self.requests))
        self.assertEqual(self.servers, {})


if __name__ == '__main__':
    unittest.main()
