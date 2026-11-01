"""Official native209 sweep graph against disposable provider and SSH boundaries."""
import json
import sys

import yaml
from native_harness import ROOT, NativeTestCase


class TaskVmSweepNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/task-vm-sweep', 'operations/task-vm-lifecycle')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.state = self.repo / 'state.json'
        self.calls = self.repo / 'calls.jsonl'
        self.block = self.repo / 'block'
        self.state.write_text(json.dumps({'instances': ['tvm-6', 'tvm-7', 'tvm-11', 'tvm-image-16', 'tvm-image-18'], 'volumes': ['tvm-6-topology-seed', 'tvm-7-topology-seed']}))
        incus = self.repo / 'incus'
        incus.write_text('#!' + sys.executable + '\n' + '''import fcntl,json,sys
from pathlib import Path
args=sys.argv[1:];state=Path(STATE)
lock=state.with_suffix('.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX)
data=json.loads(state.read_text())
with Path(CALLS).open('a') as f:f.write(json.dumps(args)+'\\n')
if 'show' in args:raise SystemExit(0)
if 'query' in args:
 path=args[-1]
 if path=='/1.0/storage-pools':print('["/1.0/storage-pools/tasks"]')
 elif 'volumes' in path:print(json.dumps(['/1.0/storage-pools/tasks/volumes/custom/'+n+'?project=tasks' for n in data['volumes']]))
 else:print(json.dumps(['/1.0/instances/'+n+'?project=tasks' for n in data['instances']]))
 raise SystemExit(0)
if 'image' in args:print('[]');raise SystemExit(0)
if 'list' in args:
 print(json.dumps([{'name':n,'type':'custom'} for n in data['volumes']] if 'volume' in args else [{'name':n} for n in data['instances']]))
elif 'delete' in args:
 key='volumes' if 'volume' in args else 'instances';data[key].remove(args[-1]);state.write_text(json.dumps(data))
else:raise SystemExit(9)
'''.replace('STATE',repr(str(self.state))).replace('CALLS',repr(str(self.calls))))
        incus.chmod(0o755)
        ssh = self.repo / 'ssh'
        ssh.write_text('#!' + sys.executable + '\n' + '''import subprocess,sys
from pathlib import Path
if '-M' in sys.argv or '-O' in sys.argv:raise SystemExit(0)
if Path(BLOCK).exists():raise SystemExit(255)
body=sys.stdin.read().replace('/usr/bin/incus', INCUS)
result=subprocess.run([sys.executable,'-'],input=body,text=True,capture_output=True)
print(result.stdout,end='');print(result.stderr,end='',file=sys.stderr)
raise SystemExit(result.returncode)
'''.replace('BLOCK',repr(str(self.block))).replace('INCUS',repr(str(incus))))
        ssh.chmod(0o755)
        for name in ('operation-task-vm-sweep','task-vm-sweep-incus','task-vm-sweep-cloud','task-vm-sweep-incus-cleanup','task-vm-sweep-cloud-cleanup'):
            flow=yaml.safe_load((ROOT/('workflows/workflow-'+name+'.yaml')).read_text())
            for step in flow['jobs'][0]['steps']:
                self.assertEqual(step.pop('target'),'orbit-node-209')
                if step['task'].get('behaviorType') == '@swamp/ssh':
                    step['task']['globalArgs']['sshBinary'] = str(ssh)
            (self.repo/('workflows/workflow-'+name+'.yaml')).write_text(yaml.safe_dump(flow,sort_keys=False))
        self.inputs = {'operation_id': 77, 'node_id': 9, 'kind': 'task_vm.sweep_host', 'execution_secrets': {},
                       'intent': {'node_id':9,'provider':'incus','ci_runner':False,'stray_vm_ids':[], 'image_cutoff':0,'running_stamps':[],
                         'scope': {'project':'tasks','pool':'tasks','operator_owned':[], 'live_names':['tvm-7'], 'max_task_vm_id':10,'max_build_id':20,
                                   'recorded_build_ids':[18], 'protected_build_ids':[18], 'retired_build_ids':[]},
                         'ssh_execution':{'identity_file':'/fixture/identity','target':{'host':'192.0.2.9','user':'orbit','port':22,'host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}}}

    def run_sweep(self, success=True):
        run=json.loads(self.cli('workflow','run','operation-task-vm-sweep','--input',json.dumps(self.inputs),success=success).stdout)
        history=json.loads(self.cli('workflow','history','get',run['id']).stdout)
        print('SWEEP-NATIVE-DURATION '+json.dumps({'provider':self.inputs['intent']['provider'],'status':run['status'],'started_at':run.get('startedAt'),'completed_at':run.get('completedAt'),'duration':run.get('duration')}),flush=True)
        return run,history

    def test_incus_cleanup_preserves_live_and_future_resources(self):
        run,history=self.run_sweep()
        self.assertEqual(run['status'],'succeeded')
        result=json.loads(next(s for s in history['jobs'][0]['steps'] if s['name']=='result')['outputs']['stdout'])
        self.assertEqual(set(result['deleted_names']),{'tvm-6','tvm-image-16','tvm-6-topology-seed'})
        self.assertEqual(json.loads(self.state.read_text()),{'instances':['tvm-7','tvm-11','tvm-image-18'],'volumes':['tvm-7-topology-seed']})
        _,again=self.run_sweep()
        self.assertEqual(json.loads(next(s for s in again['jobs'][0]['steps'] if s['name']=='result')['outputs']['stdout'])['deleted_names'],[])

    def test_ssh_failure_skips_cleanup_and_keeps_native_failure(self):
        self.block.touch()
        run,history=self.run_sweep(False)
        self.assertEqual(run['status'],'failed')
        self.assertFalse(self.calls.exists())
        self.assertEqual(history['jobs'][0]['steps'][-1]['status'],'failed')
        self.assertEqual(json.loads(next(s for s in history['jobs'][0]['steps'] if s['name']=='result')['outputs']['stdout']),{'ready':False,'error_code':'task_vm.host_command_failed','failed_step':'incus'})

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
        self.foreign_after_stop = False
        for n in (6, 7, 11):
            identity=str(uuid.uuid4())
            self.cloud[identity]={'uuid':identity,'title':'tvm-'+str(n),'hostname':'tvm-'+str(n),'state':'started',
                                  'labels':{'label':[{'key':'orbit-task-vm','value':str(n)}]}}
        template_id=str(uuid.uuid4())
        self.templates[template_id]={'uuid':template_id,'title':'orbit-task-base-20240101000000-unverified','access':'private'}
        owner=self
        class Handler(BaseHTTPRequestHandler):
            def log_message(self,*args):pass
            def reply(self,value,status=200):
                body=json.dumps(value).encode();self.send_response(status);self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(body)
            def do_GET(self):
                from urllib.parse import parse_qs, urlsplit
                owner.cloud_calls.append(('GET',self.path))
                if self.headers.get('Authorization')!='Bearer '+owner.cloud_token:return self.reply({},403)
                parsed=urlsplit(self.path)
                if parsed.path=='/server':
                    label=parse_qs(parsed.query)['label'][0]
                    return self.reply({'servers':{'server':[v for v in owner.cloud.values() if any(r['key']==label for r in v['labels']['label'])]}})
                if parsed.path=='/storage/template':return self.reply({'storages':{'storage':list(owner.templates.values())}})
                prefix,key=parsed.path.strip('/').split('/')
                row=(owner.cloud if prefix=='server' else owner.templates).get(key)
                return self.reply({prefix:row}) if row else self.reply({},404)
            def do_POST(self):
                owner.cloud_calls.append(('POST',self.path))
                row=owner.cloud[self.path.split('/')[2]]
                row['state']='maintenance' if owner.pending_stop else 'stopped'
                if owner.foreign_after_stop:row['labels']['label'][0]['value']='999'
                self.reply({})
            def do_DELETE(self):
                owner.cloud_calls.append(('DELETE',self.path))
                prefix,key=self.path.split('?',1)[0].strip('/').split('/')
                (owner.cloud if prefix=='server' else owner.templates).pop(key,None);self.reply({})
        server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
        thread=Thread(target=server.serve_forever,daemon=True);thread.start()
        self.addCleanup(server.server_close);self.addCleanup(server.shutdown)
        api=self.repo/'extensions/behaviors/operations/task-vm-lifecycle/scripts/upcloud_api.py'
        api.write_text(api.read_text().replace('https://api.upcloud.com/1.3','http://127.0.0.1:'+str(server.server_port)))
        self.cli('vault','create','local_encryption','orbit-control-inputs')
        self.cli('vault','put','orbit-control-inputs','task-vm-upcloud-token',input=self.cloud_token)
        self.inputs['execution_secrets']={'upcloud':{'vault':'orbit-control-inputs','key':'task-vm-upcloud-token'}}
        self.inputs['intent'].pop('ssh_execution')
        self.inputs['intent'].update({'provider':'upcloud','scope':{'live_names':['tvm-7'],'max_task_vm_id':10},'image_cutoff':1735689600})

    def test_cloud_cleanup_uses_canonical_api_vault_and_preserves_live_and_future_ids(self):
        self.enable_cloud()
        _run,history=self.run_sweep()
        result=json.loads(next(s for s in history['jobs'][0]['steps'] if s['name']=='result')['outputs']['stdout'])
        self.assertTrue(result['ready'])
        self.assertEqual(set(result['deleted_names']),{'tvm-6','orbit-task-base-20240101000000-unverified'})
        self.assertEqual({v['title'] for v in self.cloud.values()},{'tvm-7','tvm-11'})
        self.assertFalse(self.templates)
        self.assertFalse(self.calls.exists())
        self.assertEqual(sum(method=='POST' for method,_ in self.cloud_calls),1)
        _,again=self.run_sweep()
        self.assertEqual(json.loads(next(s for s in again['jobs'][0]['steps'] if s['name']=='result')['outputs']['stdout'])['deleted_names'],[])

    def test_cloud_pending_stop_fails_once_without_a_delete_or_custom_wait(self):
        self.enable_cloud();self.pending_stop=True
        run,_=self.run_sweep(False)
        self.assertEqual(run['status'],'failed')
        self.assertEqual(sum(method=='POST' for method,_ in self.cloud_calls),1)
        self.assertFalse(any(method=='DELETE' for method,_ in self.cloud_calls))

    def test_cloud_owner_change_after_stop_refuses_delete(self):
        self.enable_cloud();self.foreign_after_stop=True
        run,_=self.run_sweep(False)
        self.assertEqual(run['status'],'failed')
        self.assertFalse(any(method=='DELETE' for method,_ in self.cloud_calls))
