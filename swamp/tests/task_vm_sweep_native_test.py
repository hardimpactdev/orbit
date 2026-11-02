"""Official native209 sweep graph against disposable provider and SSH boundaries.

The fixture tools stand in for the hosts: `ssh` runs the remote argv locally
without `sudo -n --`, `incus` answers from a JSON state file, and `curl`
sends UpCloud requests to a local HTTP server. Every step argv stays as written.
"""
import json
import os
import sys

import yaml

from native_harness import ROOT, NativeTestCase

FLOWS = ('operation-task-vm-sweep', 'task-vm-sweep-incus', 'task-vm-sweep-cloud')


def tool(path, source):
    path.write_text('#!' + sys.executable + '\n' + source)
    path.chmod(0o755)


class TaskVmSweepNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/task-vm-sweep')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.state = self.repo / 'state.json'
        self.calls = self.repo / 'calls.jsonl'
        self.block = self.repo / 'block'
        self.state.write_text(json.dumps({
            'projects': ['tasks', 'default'],
            'instances': [['tasks', n] for n in ('tvm-6', 'tvm-7', 'tvm-11', 'tvm-image-16', 'tvm-image-18')] + [['default', 'web']],
            'volumes': [['tasks', 'tvm-6-topology-seed'], ['tasks', 'tvm-7-topology-seed']]}))
        tools = self.repo / 'tools'
        tools.mkdir()
        tool(tools / 'incus', '''import json,sys
from pathlib import Path
from urllib.parse import parse_qs, urlsplit
args=sys.argv[1:];state=Path(STATE);data=json.loads(state.read_text())
def url(base,row):return base+row[1]+('' if row[0]=='default' else '?project='+row[0])
if args[0]=='query':
    parsed=urlsplit(args[1]);query=parse_qs(parsed.query);project=query.get('project',['default'])[0]
    every='all-projects' in query
    if not every and project not in data['projects']:
        if parsed.path=='/1.0/instances':print('[]');raise SystemExit(0)
        print('Error: Project not found',file=sys.stderr);raise SystemExit(1)
    if parsed.path=='/1.0/instances':print(json.dumps([url('/1.0/instances/',r) for r in data['instances'] if every or r[0]==project]))
    elif parsed.path=='/1.0/storage-pools/tasks/volumes/custom':print(json.dumps([url(parsed.path+'/',r) for r in data['volumes']]))
    elif parsed.path=='/1.0/images':print('[]')
    else:raise SystemExit(9)
    raise SystemExit(0)
project=args[args.index('--project')+1];name=args[-1]
key='volumes' if args[:3]==['storage','volume','delete'] else 'instances'
if key=='instances' and args[:2]!=['delete','--force']:raise SystemExit(9)
if [project,name] not in data[key]:raise SystemExit(1)
data[key].remove([project,name]);state.write_text(json.dumps(data))
'''.replace('STATE', repr(str(self.state))))
        tool(tools / 'ssh', '''import json,shlex,subprocess,sys
from pathlib import Path
if '-M' in sys.argv or '-O' in sys.argv:raise SystemExit(0)
if Path(BLOCK).exists():raise SystemExit(255)
argv=shlex.split(sys.argv[-1])
with Path(CALLS).open('a') as f:f.write(json.dumps(argv)+'\\n')
assert argv[:3]==['sudo','-n','--'],argv
r=subprocess.run(argv[3:],capture_output=True)
sys.stdout.buffer.write(r.stdout);sys.stderr.buffer.write(r.stderr)
raise SystemExit(r.returncode)
'''.replace('BLOCK', repr(str(self.block))).replace('CALLS', repr(str(self.calls))))
        self.tools = tools
        self.native_env.update(PATH=str(tools) + ':' + os.environ['PATH'])
        for name in FLOWS:
            flow = yaml.safe_load((ROOT / ('workflows/workflow-' + name + '.yaml')).read_text())
            for job in flow['jobs']:
                for step in job['steps']:
                    self.assertEqual(step.pop('labels'), {'role': 'swamp'})
            (self.repo / ('workflows/workflow-' + name + '.yaml')).write_text(yaml.safe_dump(flow, sort_keys=False))
        self.inputs = {'operation_id': 77, 'node_id': 9, 'kind': 'task_vm.sweep_host', 'execution_secrets': {},
                       'intent': {'node_id': 9, 'provider': 'incus', 'ci_runner': False, 'stray_vm_ids': [], 'image_cutoff': 0, 'running_stamps': [],
                                  'scope': {'project': 'tasks', 'pool': 'tasks', 'operator_owned': [], 'live_names': ['tvm-7'], 'max_task_vm_id': 10, 'max_build_id': 20,
                                            'recorded_build_ids': [18], 'protected_build_ids': [18], 'retired_build_ids': []},
                                  'ssh_execution': {'identity_file': '/fixture/identity', 'target': {'host': '192.0.2.9', 'user': 'orbit', 'port': 22, 'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}}}

    def run_sweep(self, success=True):
        run = json.loads(self.cli('workflow', 'run', 'operation-task-vm-sweep', '--input', json.dumps(self.inputs), success=success).stdout)
        history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        print('SWEEP-NATIVE-DURATION ' + json.dumps({'provider': self.inputs['intent']['provider'], 'status': run['status'], 'started_at': run.get('startedAt'), 'completed_at': run.get('completedAt'), 'duration': run.get('duration')}), flush=True)
        return run, history

    def result(self, history):
        return json.loads(next(s for s in history['jobs'][0]['steps'] if s['name'] == 'result')['outputs']['stdout'])

    def remote(self):
        return [json.loads(line) for line in self.calls.read_text().splitlines()] if self.calls.exists() else []

    def test_incus_cleanup_preserves_live_and_future_resources(self):
        run, history = self.run_sweep()
        self.assertEqual(run['status'], 'succeeded')
        result = self.result(history)
        self.assertEqual(set(result['deleted_names']), {'tvm-6', 'tvm-image-16', 'tvm-6-topology-seed'})
        self.assertEqual(set(result['observed_names']), {'tvm-7', 'tvm-11', 'tvm-image-18'})
        state = json.loads(self.state.read_text())
        self.assertEqual(state['instances'], [['tasks', 'tvm-7'], ['tasks', 'tvm-11'], ['tasks', 'tvm-image-18'], ['default', 'web']])
        self.assertEqual(state['volumes'], [['tasks', 'tvm-7-topology-seed']])
        writes = [argv[3:] for argv in self.remote() if 'query' not in argv]
        self.assertEqual(sorted(writes), sorted([
            ['timeout', '180', 'incus', 'delete', '--force', '--project', 'tasks', '--', 'tvm-6'],
            ['timeout', '180', 'incus', 'delete', '--force', '--project', 'tasks', '--', 'tvm-image-16'],
            ['timeout', '180', 'incus', 'storage', 'volume', 'delete', '--project', 'tasks', '--', 'tasks', 'tvm-6-topology-seed']]))
        self.assertEqual(writes[-1][3:5], ['storage', 'volume'])
        _, again = self.run_sweep()
        self.assertEqual(self.result(again)['deleted_names'], [])

    def test_ssh_failure_skips_cleanup_and_keeps_native_failure(self):
        self.block.touch()
        run, history = self.run_sweep(False)
        self.assertEqual(run['status'], 'failed')
        self.assertEqual(self.remote(), [])
        self.assertEqual(history['jobs'][0]['steps'][-1]['status'], 'failed')
        self.assertEqual(self.result(history), {'ready': False, 'error_code': 'task_vm.host_command_failed', 'failed_step': 'incus'})

    def test_missing_project_fails_the_host_without_a_change(self):
        self.inputs['intent']['scope']['project'] = 'gone'
        run, history = self.run_sweep(False)
        self.assertEqual(run['status'], 'failed')
        self.assertFalse([argv for argv in self.remote() if 'query' not in argv])
        self.assertFalse(self.result(history)['ready'])

    def enable_cloud(self):
        import uuid
        from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
        from threading import Thread
        self.cloud_calls = []
        self.cloud_token = 'ucat_native_sweep_fixture_79be715ec'
        self.secrets = (self.cloud_token,)
        self.cloud = {}
        self.templates = {}
        self.pending_stop = False
        for n in (6, 7, 11):
            identity = str(uuid.uuid4())
            self.cloud[identity] = {'uuid': identity, 'title': 'tvm-' + str(n), 'hostname': 'tvm-' + str(n), 'state': 'started',
                                    'labels': {'label': [{'key': 'orbit-task-vm', 'value': str(n)}]}}
        template_id = str(uuid.uuid4())
        self.templates[template_id] = {'uuid': template_id, 'title': 'orbit-task-base-20240101000000-unverified', 'access': 'private'}
        owner = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def reply(self, value, status=200):
                body = json.dumps(value).encode()
                self.send_response(status)
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(body)

            def authorized(self, method):
                owner.cloud_calls.append((method, self.path))
                if self.headers.get('Authorization') != 'Bearer ' + owner.cloud_token:
                    self.reply({}, 403)
                    return False
                return True

            def do_GET(self):
                from urllib.parse import parse_qs, urlsplit
                if not self.authorized('GET'):
                    return
                parsed = urlsplit(self.path)
                if parsed.path == '/server':
                    label = parse_qs(parsed.query)['label'][0]
                    return self.reply({'servers': {'server': [v for v in owner.cloud.values() if any(r['key'] == label for r in v['labels']['label'])]}})
                if parsed.path == '/storage/template':
                    return self.reply({'storages': {'storage': list(owner.templates.values())}})
                return self.reply({}, 404)

            def do_POST(self):
                if not self.authorized('POST'):
                    return
                body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                assert body == {'stop_server': {'stop_type': 'hard'}}, body
                owner.cloud[self.path.split('/')[2]]['state'] = 'maintenance' if owner.pending_stop else 'stopped'
                self.reply({})

            def do_DELETE(self):
                if not self.authorized('DELETE'):
                    return
                path, _, query = self.path.partition('?')
                prefix, key = path.strip('/').split('/')
                if prefix == 'server':
                    assert query == 'storages=1&backups=delete', query
                    if owner.cloud[key]['state'] != 'stopped':
                        return self.reply({'error': {'error_code': 'SERVER_STATE_ILLEGAL'}}, 409)
                (owner.cloud if prefix == 'server' else owner.templates).pop(key)
                self.send_response(204)
                self.end_headers()

        server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        Thread(target=server.serve_forever, daemon=True).start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        origin = 'http://127.0.0.1:' + str(server.server_port)
        tool(self.tools / 'curl', f'''import os,sys,urllib.error,urllib.request
args=sys.argv[1:]
# The token reaches curl from its environment through --variable; argv holds only the name.
assert args[args.index("--variable")+1]=="%ORBIT_UPCLOUD_TOKEN",args
assert os.environ["ORBIT_UPCLOUD_TOKEN"] not in " ".join(args)
header=args[args.index("--expand-header")+1].replace("{{{{ORBIT_UPCLOUD_TOKEN}}}}",os.environ["ORBIT_UPCLOUD_TOKEN"])
method=args[args.index("--request")+1] if "--request" in args else "GET"
data=args[args.index("--data")+1].encode() if "--data" in args else None
url=args[-1].replace("https://api.upcloud.com/1.3",{origin!r})
name,value=header.split(": ",1)
request=urllib.request.Request(url,method=method,data=data,headers={{name:value,"Content-Type":"application/json"}})
try:
    with urllib.request.urlopen(request,timeout=30) as response:sys.stdout.write(response.read().decode())
except urllib.error.HTTPError as error:
    sys.stderr.write("curl: (22) "+str(error.code));raise SystemExit(22)
''')
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        self.cli('vault', 'put', 'orbit-control-inputs', 'task-vm-upcloud-token', input=self.cloud_token)
        self.inputs['execution_secrets'] = {'upcloud': {'vault': 'orbit-control-inputs', 'key': 'task-vm-upcloud-token'}}
        self.inputs['intent'].pop('ssh_execution')
        self.inputs['intent'].update({'provider': 'upcloud', 'scope': {'live_names': ['tvm-7'], 'max_task_vm_id': 10}, 'image_cutoff': 1735689600})

    def test_cloud_cleanup_uses_the_vault_token_and_preserves_live_and_future_ids(self):
        self.enable_cloud()
        _run, history = self.run_sweep()
        result = self.result(history)
        self.assertTrue(result['ready'])
        self.assertEqual(set(result['deleted_names']), {'tvm-6', 'orbit-task-base-20240101000000-unverified'})
        self.assertEqual(set(result['observed_names']), {'tvm-7', 'tvm-11'})
        self.assertEqual({v['title'] for v in self.cloud.values()}, {'tvm-7', 'tvm-11'})
        self.assertFalse(self.templates)
        self.assertFalse(self.calls.exists())
        self.assertEqual(sum(method == 'POST' for method, _ in self.cloud_calls), 1)
        _, again = self.run_sweep()
        self.assertEqual(self.result(again)['deleted_names'], [])

    def test_cloud_pending_stop_fails_without_a_delete_or_custom_wait(self):
        self.enable_cloud()
        self.pending_stop = True
        run, history = self.run_sweep(False)
        self.assertEqual(run['status'], 'failed')
        self.assertFalse(self.result(history)['ready'])
        self.assertEqual(sum(method == 'POST' for method, _ in self.cloud_calls), 1)
        self.assertIn('tvm-6', {v['title'] for v in self.cloud.values()})
        self.pending_stop = False
        for row in self.cloud.values():
            row['state'] = 'stopped' if row['title'] == 'tvm-6' else row['state']
        _run, history = self.run_sweep()
        self.assertIn('tvm-6', self.result(history)['deleted_names'])
        self.assertEqual(sum(method == 'POST' for method, _ in self.cloud_calls), 1)
