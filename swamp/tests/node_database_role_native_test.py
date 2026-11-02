"""Database and Ingress native roles, using the official SSH extension's binary seam."""
import hashlib
import json
import os
import shutil
import subprocess
import sys

import yaml
from native_harness import ROOT, NativeTestCase


class DatabaseRoleNative(NativeTestCase):
    native_timeout=120
    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/node-role-converge-command','operations/node-role-command','operations/node-bootstrap','operations/fleet','operations/node','operations/_lib')
        shutil.copy(ROOT/'workflows/workflow-operation-node-role-add-direct.yaml',self.repo/'workflows')
        routes=self.repo/'extensions/behaviors/operations/routes'
        routes.mkdir()
        for name in ['caddy-input.ts']:
            shutil.copy(ROOT/'extensions/behaviors/operations/routes'/name,routes)
        self.cli('extension','pull','@swamp/ssh@2026.10.23.1')
        self.audit=self.repo/'calls.jsonl'
        self.failure=self.repo/'fail'
        binary=self.repo/'fixture-ssh'
        binary.write_text('#!'+sys.executable+'\n'+'''import ast,json,sys
from pathlib import Path
if '-M' in sys.argv or '-O' in sys.argv:raise SystemExit(0)
def finish(action,result):
 with Path(AUDIT).open('a') as stream:stream.write(json.dumps({'action':action})+'\\n')
 if Path(FAILURE).exists() and Path(FAILURE).read_text()==action:raise SystemExit(1)
 print(json.dumps(result));raise SystemExit(0)
if 'caddy validate' in sys.argv[-1]:finish('caddy_validate',{})
if 'systemctl' in sys.argv[-1]:finish('service',{})
if 'ufw' in sys.argv[-1]:finish('firewall_prune' if '--force' in sys.argv[-1] else 'firewall',{})
body=sys.stdin.read()
if '/etc/caddy/Caddyfile' in sys.argv[-1]:finish('caddy_write',{})
if 'Verify the existing observer identity' in sys.argv[-1]:
 import os
 if body!=os.environ['ORBIT_TEST_OBSERVER_DIGEST']:raise SystemExit(1)
 print(json.dumps({'ready':True,'changed':False}));raise SystemExit(0)
if not body:
 print('{}');raise SystemExit(0)
if "observer secret" in body or "Observer identity" in body:action='identity';result={'ready':True,'changed':False}
elif "Download" in body or "BOOTSTRAP_INPUT" in body:
 tree=ast.parse(body);data={}
 for node in tree.body:
  if isinstance(node,ast.Assign) and isinstance(node.targets[0],ast.Name) and node.targets[0].id=='BOOTSTRAP_INPUT':data=json.loads(ast.literal_eval(node.value.args[0]))
 if 'sha256' in data:action='install';result={'installed':True,'changed':False,'sha256':data['sha256']}
 else:action='packages';result={'packages_installed':True}
elif 'ROLE_INPUT' in body:
 tree=ast.parse(body);data={}
 for node in tree.body:
  if isinstance(node,ast.Assign) and isinstance(node.targets[0],ast.Name) and node.targets[0].id=='ROLE_INPUT' and isinstance(node.value,ast.Call):data=json.loads(ast.literal_eval(node.value.args[0]))
 if 'user' in data and len(data)==1:action='account';result={'user':'orbit','group':'orbit','home':'/home/orbit'}
 elif 'release' in data:action='caddy_package';result={'caddy_installed':True}
 else:action='file';result={'ready':True,'changed':False}
elif 'Typed UFW ownership' in body:action='firewall';result={'matches':True}
elif 'def repair_dns' in body:action='dns';result={}
elif 'def install' in body:action='cli';result={'installed':None,'configured':True}
else:raise SystemExit('Unrecognized fixture action')
with Path(AUDIT).open('a') as stream:stream.write(json.dumps({'action':action})+'\\n')
if Path(FAILURE).exists() and Path(FAILURE).read_text()==action:raise SystemExit(1)
print(json.dumps(result))
'''.replace('AUDIT',repr(str(self.audit))).replace('FAILURE',repr(str(self.failure))))
        binary.chmod(0o700)
        identity=self.repo/'identity';identity.write_text('synthetic fixture only')
        source=(ROOT/'extensions/behaviors/operations/node-role-converge-command/workflows.ts').as_uri()
        program='import {roleWorkflow,dispatcherWorkflow} from '+json.dumps(source)+';console.log(JSON.stringify([roleWorkflow(),dispatcherWorkflow()]));'
        result=subprocess.run([os.environ.get('DENO_BIN','/tmp/lane4-deno-2.9.8/deno'),'eval',program],check=True,capture_output=True,text=True)
        for flow in json.loads(result.stdout):
            flow.pop('target',None)
            for job in flow['jobs']:
                for step in job['steps']:
                    self.assertEqual(step.pop('labels'),{'role': 'swamp'})
                    task=step['task']
                    if task.get('behaviorType')=='@swamp/ssh':task['globalArgs']['sshBinary']=str(binary)
            (self.repo/'workflows'/('workflow-'+flow['name']+'.yaml')).write_text(yaml.safe_dump(flow,sort_keys=False))
        caddy=yaml.safe_load((ROOT/'workflows/workflow-operation-route-caddy.yaml').read_text())
        for job in caddy['jobs']:
            job.pop('target');self.assertNotIn('labels',job)
            for step in job['steps']:
                self.assertEqual(step.pop('labels'),{'role': 'swamp'});self.assertNotIn('labels',step)
                task=step['task']
                if task.get('behaviorType')=='@swamp/ssh':task['globalArgs']['sshBinary']=str(binary)
        (self.repo/'workflows/workflow-operation-route-caddy.yaml').write_text(yaml.safe_dump(caddy,sort_keys=False))
        self.input={'operation_id':7,'node_id':5,'kind':'node.role_converge','execution_secrets':{},'intent':{
          'role':'database','assignment_id':17,'node_id':5,'user':'orbit','agent_sha256':'a'*64,'prerequisites':{'packages':['docker.io'],'releases':['resolute']},
          'ssh':{'identity_file':str(identity),'target':{'host':'dev.invalid','user':'orbit','port':22,'host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}},
          'footprint':{'artifacts':[{'name':'agent','required':True}],'payloads':{'agent':{'url':'https://example.invalid/agent','configuration':'gateway_url="https://gateway.orbit"','certificate':'fixture','unit':'# Managed by Orbit: agent\nProtectHome=yes','apps_root':None,'checkouts':[]}},'network':None},
          'cli':{'user':'orbit','release':None,'profile':'','skip_reason':'release_unavailable','plan_error':None}}}
        # Resolve a synthetic whole vault value using the native fixture vault.
        digest=hashlib.sha256(os.urandom(32)).hexdigest()
        self.native_env['ORBIT_TEST_OBSERVER_DIGEST']=digest
        self.secrets=(digest,)
        self.cli('vault','create','local_encryption','orbit-control-inputs')
        self.cli('vault','put','orbit-control-inputs','node-role-7-observer',input=digest)
        self.input['execution_secrets']={'observer':{'vault':'orbit-control-inputs','key':'node-role-7-observer'}}
    def run_role(self,success=True):
        self.cli('workflow','validate','operation-node-role-converge-209')
        result=json.loads(self.cli('workflow','run','operation-node-role-converge-209','--input',json.dumps(self.input),success=success).stdout)
        self.last_result=result
        history=json.loads(self.cli('workflow','history','get',result['id']).stdout)
        steps={s['name']:s for j in history['jobs'] for s in j['steps']}
        return history,json.loads(steps['result']['outputs']['stdout'])
    def test_success_and_empty_dns_branch(self):
        history,result=self.run_role()
        self.assertEqual(history['status'],'succeeded');self.assertTrue(result['role_proven']);self.assertTrue(result['footprint']['footprint_proven'])
    def test_package_failure_is_not_role_success(self):
        self.failure.write_text('packages')
        _,result=self.run_role(False)
        self.assertFalse(result['role_proven'])
    def test_observer_failure_is_an_explicit_follow_up(self):
        self.failure.write_text('file')
        _,result=self.run_role()
        self.assertTrue(result['role_proven']);self.assertFalse(result['footprint']['footprint_proven']);self.assertIn('role-footprint:',result['follow_up'])

    def test_dns_and_cli_are_real_actions_with_typed_receipts(self):
        self.input['intent']['footprint']['network']={'peer_dns':{'dns_server':'10.44.0.1','public_key':'A'*43+'=','domains':['orbit']}}
        self.input['intent']['footprint']['artifacts'].append({'name':'peer-dns','required':True})
        self.input['intent']['cli'].update(skip_reason=None,release={'version':'0.321.0','url':'https://example.invalid/cli','sha256':'b'*64})
        _,result=self.run_role()
        self.assertTrue(result['footprint']['footprint_proven'])
        self.assertEqual(result['cli']['outcome'],'cli_present')
        calls=[json.loads(row)['action'] for row in self.audit.read_text().splitlines()]
        self.assertIn('dns',calls);self.assertIn('cli',calls)

    def test_retained_role_receives_the_original_root_identity_without_native_effects(self):
        path=self.repo/'workflows/workflow-operation-node-role-add-direct.yaml'
        flow=yaml.safe_load(path.read_text())
        forwarded=flow['jobs'][0]['steps'][0]['task']['inputs']['run_id']
        flow.pop('target',None);flow.pop('labels',None)
        flow['jobs']=[{'name':'capture','steps':[{'name':'result','task':{'type':'behavior','behaviorType':'@nckrtl/role-retained-fixture','behaviorName':'retained-fixture','methodName':'capture','inputs':{'run_id':forwarded}}}]}]
        path.write_text(yaml.safe_dump(flow,sort_keys=False))
        module=self.repo/'extensions/behaviors/operations/node-role-converge-command/retained_fixture.ts'
        module.write_text('''import {z} from "npm:zod@4.4.3";
export const behavior={type:"@nckrtl/role-retained-fixture",version:"2026.11.01.1",resources:{result:{schema:z.object({run_id:z.string()}),lifetime:"1d",garbageCollection:10}},methods:{capture:{description:"Record the forwarded root identity",arguments:z.object({run_id:z.string()}),execute:async(args:{run_id:string},ctx:{writeResource(s:string,n:string,v:unknown):Promise<void>})=>{await Deno.writeTextFile("retained-root-id",args.run_id);await ctx.writeResource("result","result",args);return args;}}}};''')
        self.input.pop('intent');self.input['execution_secrets']={}
        history,result=self.run_role()
        self.assertEqual(result,{})
        self.assertEqual((self.repo/'retained-root-id').read_text(),history['id'])
        self.assertFalse(self.audit.exists())

    def test_nonempty_checkouts_keep_distinct_native_identities_and_finish_observer(self):
        self.input['intent']['footprint']['payloads']['agent']['checkouts']=['/srv/first','/srv/second']
        _,result=self.run_role()
        self.assertTrue(result['footprint']['footprint_proven'])

    def ingress(self):
        intent=self.input['intent'];intent['role']='ingress'
        content='# Managed by Orbit: Node Caddy build\n{ admin off }\n'
        intent.update(caddy_package={'release':'2.11.1','minimum_version':'2.11.1','amd64_sha512':'c'*128},firewall={'desired':[['ufw','allow','in','on','orbit','to','10.44.0.5','comment','orbit:wireguard-members']], 'remove':[['ufw','--force','delete','allow','22/tcp','comment','orbit:public-ssh-recovery']]},caddy={
            'node_id':5,'user':'orbit','control_node_id':209,'host_keys':intent['ssh']['target']['host_keys'],
            'connection':{'nodeHost':'dev.invalid','nodeUser':'orbit','nodePort':22,'nodeIdentityFile':intent['ssh']['identity_file'],'become':True},
            'caddy':{'content':content,'version':hashlib.sha256(content.encode()).hexdigest()[:32],'listen_addresses':['10.44.0.5'],'unserved':False}})
        intent['footprint']['artifacts'] += [{'name':name,'required':True} for name in ['caddy-package','caddy']]

    def test_ingress_publication_precedes_firewall_and_proves_footprint(self):
        self.ingress()
        _,result=self.run_role()
        self.assertTrue(result['role_proven']);self.assertTrue(result['footprint']['footprint_proven'])
        actions=[json.loads(line)['action'] for line in self.audit.read_text().splitlines()]
        self.assertLess(actions.index('caddy_package'),actions.index('packages'))
        self.assertLess(actions.index('caddy_validate'),actions.index('firewall'))

    def test_ingress_mandatory_failures_do_not_prove_role_or_run_later_effects(self):
        self.ingress()
        for failure in ['caddy_package','caddy_validate','firewall','firewall_prune']:
            with self.subTest(failure=failure):
                self.audit.unlink(missing_ok=True);self.failure.write_text(failure)
                _,result=self.run_role(False)
                self.assertFalse(result['role_proven'])
                actions=[json.loads(line)['action'] for line in self.audit.read_text().splitlines()]
                if failure=='caddy_package':
                    self.assertNotIn('packages',actions);self.assertEqual(result['failed_step'],'caddy-package')
                if failure not in ['firewall','firewall_prune']:self.assertNotIn('firewall',actions)
                if failure=='firewall':self.assertNotIn('firewall_prune',actions)
