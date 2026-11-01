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
        NativeTestCase.setUp(self)
        self.copy_behaviors('operations/fleet-recipe','operations/node-role-converge-command','operations/node-role-command','operations/node-bootstrap','operations/fleet','operations/node','operations/_lib')
        shutil.copy(ROOT/'workflows/workflow-operation-node-role-add-direct.yaml',self.repo/'workflows')
        routes=self.repo/'extensions/behaviors/operations/routes'
        routes.mkdir()
        for name in ['caddy-input.ts']:
            shutil.copy(ROOT/'extensions/behaviors/operations/routes'/name,routes)
        # Unselected children must still be registered with their real public schemas.
        for name in ['node-role-websocket','node-role-publication','node-role-certificate-publish','operation-fleet-peer-dns']:
            flow=yaml.safe_load((ROOT/('workflows/workflow-'+name+'.yaml')).read_text())
            flow.pop('target',None)
            flow['jobs']=[{'name':'fixture','steps':[{'name':'result','task':{'type':'assert','expr':'true','severity':'high','message':'Fixture boundary'}}]}]
            (self.repo/('workflows/workflow-'+name+'.yaml')).write_text(yaml.safe_dump(flow,sort_keys=False))
        self.cli('extension','pull','@swamp/ssh@2026.10.23.1')
        self.audit=self.repo/'calls.jsonl'
        self.failure=self.repo/'fail'
        binary=self.repo/'fixture-ssh'
        binary.write_text('#!'+sys.executable+'\n'+'''import ast,json,sys,shlex,os
from pathlib import Path
if '-M' in sys.argv or '-O' in sys.argv:raise SystemExit(0)
def finish(action,result):
 with Path(AUDIT).open('a') as stream:stream.write(json.dumps({'action':action})+'\\n')
 if Path(FAILURE).exists() and Path(FAILURE).read_text()==action:raise SystemExit(1)
 print(json.dumps(result));raise SystemExit(0)
body=sys.stdin.read()
command=shlex.split(sys.argv[-1])
if '/usr/bin/env' in command:
 index=command.index('/usr/bin/env')+1
 while index<len(command) and '=' in command[index]:
  key,value=command[index].split('=',1);os.environ[key]=value;index+=1
if '/usr/bin/mkdir' in command:
 Path(command[-1]).mkdir(parents=True,exist_ok=True);raise SystemExit(0)
if '/usr/bin/python3' in command and '-B' in command:
 phase=Path(command[-1]).name
 data=json.loads(os.environ.get('ORBIT_ROLE_INPUT','{}'));intent=data.get('intent',{})
 def record(action):
  with Path(AUDIT).open('a') as stream:stream.write(json.dumps({'action':action})+'\\n')
  if Path(FAILURE).exists() and Path(FAILURE).read_text()==action:raise SystemExit(1)
 if phase=='account.py':finish('account',{'user':'orbit','group':'orbit','home':'/home/orbit'})
 if phase=='observer_install.py':finish('install',{'installed':True,'changed':False,'sha256':intent['agent_sha256']})
 if phase=='phase_prerequisites.py':
  if intent['role'] in ['ingress','websocket','app-prod']:record('caddy_package')
  if intent['role']=='app-prod':
   record('app_prerequisites');record('php_build')
   if not data['prepared_tools'] and intent['javascript'] is not None:record('javascript')
   record('caddy_ordering')
  elif not data['prepared_tools']:record('packages')
  print('{"ready":true}');raise SystemExit(0)
 if phase=='phase_caddy_service.py':finish('service',{})
 if phase=='phase_firewall.py':record('firewall');finish('firewall_prune',{})
 if phase=='phase_observer.py':
  if body!=os.environ['ORBIT_TEST_OBSERVER_DIGEST']:raise SystemExit(1)
  finish('file',{'ready':True,'changed':False})
 if phase=='phase_php_runtime.py':
  record('php_runtime')
  if Path(FAILURE).exists() and Path(FAILURE).read_text()=='php_runtime_refusal':raise SystemExit(1)
  print('{"ready":true}');raise SystemExit(0)
 if phase=='phase_cli.py':finish('cli',{'installed':None,'configured':True})
 raise SystemExit('Unknown role phase '+phase)
if 'resolvectl' in sys.argv[-1]:finish('dns',{})
if 'caddy validate' in sys.argv[-1]:finish('caddy_validate',{})
if 'systemctl' in sys.argv[-1]:finish('service',{})
if 'ufw' in sys.argv[-1]:finish('firewall_prune' if '--force' in sys.argv[-1] else 'firewall',{})
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
        scp=self.repo/'fixture-scp'
        scp.write_text('#!'+sys.executable+'\nimport shutil,sys\nfrom pathlib import Path\nsrc=Path(sys.argv[-2]);dst=Path(sys.argv[-1].split(":",1)[1]);shutil.copytree(src,dst,dirs_exist_ok=True)\n')
        scp.chmod(0o700)
        identity=self.repo/'identity';identity.write_text('synthetic fixture only')
        source=(ROOT/'extensions/behaviors/operations/node-role-converge-command/workflows.ts').as_uri()
        program='import {roleWorkflow,dispatcherWorkflow} from '+json.dumps(source)+';console.log(JSON.stringify([roleWorkflow(),dispatcherWorkflow()]));'
        result=subprocess.run([os.environ.get('DENO_BIN','/tmp/lane4-deno-2.9.8/deno'),'eval',program],check=True,capture_output=True,text=True)
        for flow in json.loads(result.stdout):
            flow.pop('target',None)
            for job in flow['jobs']:
                for step in job['steps']:
                    self.assertEqual(step.pop('target'),'orbit-node-209')
                    task=step['task']
                    if task.get('behaviorType')=='@swamp/ssh':task['globalArgs'].update(sshBinary=str(binary),scpBinary=str(scp))
            (self.repo/'workflows'/('workflow-'+flow['name']+'.yaml')).write_text(yaml.safe_dump(flow,sort_keys=False))
        caddy=yaml.safe_load((ROOT/'workflows/workflow-operation-route-caddy.yaml').read_text())
        for job in caddy['jobs']:
            job.pop('target');self.assertNotIn('labels',job)
            for step in job['steps']:
                self.assertEqual(step.pop('target'),'orbit-node-209');self.assertNotIn('labels',step)
                task=step['task']
                if task.get('behaviorType')=='@swamp/ssh':task['globalArgs'].update(sshBinary=str(binary),scpBinary=str(scp))
        (self.repo/'workflows/workflow-operation-route-caddy.yaml').write_text(yaml.safe_dump(caddy,sort_keys=False))
        dns_path=self.repo/'workflows/workflow-operation-fleet-peer-dns.yaml'
        dns=yaml.safe_load(dns_path.read_text())
        globals_={'name':'dns-fixture','sshBinary':str(binary),'transport':{'kind':'ssh','user':'${{ inputs.intent.ssh.target.user }}','port':22,'identityFile':'${{ inputs.intent.ssh.identity_file }}'},'hosts':[{'name':'target','address':'${{ inputs.intent.ssh.target.host }}','transport':{'hostKeys':'${{ inputs.intent.ssh.target.host_keys }}'}}]}
        dns['jobs']=[{'name':'dns','steps':[{'name':'result','task':{'type':'behavior','behaviorType':'@swamp/ssh','behaviorName':'dns-fixture','methodName':'exec','globalArgs':globals_,'inputs':{'hosts':'name:target','argv':['/usr/bin/resolvectl','status','orbit']}}}]}]
        dns_path.write_text(yaml.safe_dump(dns,sort_keys=False))
        self.input={'operation_id':7,'node_id':5,'kind':'node.role_converge','execution_secrets':{},'intent':{
          'role':'database','assignment_id':17,'node_id':5,'created':False,'user':'orbit','agent_sha256':'a'*64,'prerequisites':{'packages':['docker.io'],'releases':['resolute']},
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

    def test_new_assignment_identity_survives_success_and_failure(self):
        self.input['intent']['created']=True
        _,result=self.run_role()
        self.assertEqual((result['node_id'],result['assignment_id'],result['role'],result['created']),(5,17,'database',True))
        self.failure.write_text('packages')
        _,result=self.run_role(False)
        self.assertFalse(result['role_proven'])
        self.assertIs(result['created'],True)
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
        for failure in ['caddy_package','packages','caddy_validate','firewall','firewall_prune']:
            with self.subTest(failure=failure):
                self.audit.unlink(missing_ok=True);self.failure.write_text(failure)
                _,result=self.run_role(False)
                self.assertFalse(result['role_proven'])
                actions=[json.loads(line)['action'] for line in self.audit.read_text().splitlines()]
                if failure=='caddy_package':
                    self.assertNotIn('packages',actions);self.assertEqual(result['failed_step'],'prerequisites')
                if failure not in ['firewall','firewall_prune']:self.assertNotIn('firewall',actions)
                if failure=='firewall':self.assertNotIn('firewall_prune',actions)

    def test_prefixed_same_parent_factory_consumes_prepared_tools_without_apt(self):
        source=(ROOT/'extensions/behaviors/operations/node-role-converge-command/root_steps.ts').as_uri()
        globals_={'name':'prefixed-role','sshBinary':str(self.repo/'fixture-ssh'),'scpBinary':str(self.repo/'fixture-scp'),'transport':{'kind':'ssh','user':'${{ inputs.intent.candidate.user }}','identityFile':self.input['intent']['ssh']['identity_file'],'port':22},'hosts':[{'name':'target','address':'dev.invalid','transport':{'hostKeys':self.input['intent']['ssh']['target']['host_keys']}}]}
        program='import {roleConvergeSteps} from '+json.dumps(source)+';console.log(JSON.stringify(roleConvergeSteps("enrolled","inputs.intent.roles[0]",'+json.dumps(globals_)+',undefined,undefined,{preparedToolsExpr:"inputs.prepared",observerDigestExpr:"vault.get(inputs.execution_secrets.observer.vault,inputs.execution_secrets.observer.key)"})));'
        steps=json.loads(subprocess.run([os.environ.get('DENO_BIN','/tmp/lane4-deno-2.9.8/deno'),'eval',program],check=True,capture_output=True,text=True).stdout)
        for step in steps:self.assertEqual(step.pop('target'),'orbit-node-209')
        flow={'id':'5bf93ad5-c08a-4ce5-8856-7ccc992bda2f','name':'same-parent-role','vaults':['orbit-control-inputs'],'inputs':{'type':'object'},'jobs':[{'name':'effects','steps':steps}]}
        (self.repo/'workflows/workflow-same-parent-role.yaml').write_text(yaml.safe_dump(flow,sort_keys=False))
        payload={**self.input,'intent':{'roles':[self.input['intent']],'candidate':{'user':'orbit'}},'prepared':{'ready':True,'node_id':5,'packages':['docker.io']}}
        self.cli('workflow','validate','same-parent-role')
        run=json.loads(self.cli('workflow','run','same-parent-role','--input',json.dumps(payload)).stdout)
        history=json.loads(self.cli('workflow','history','get',run['id']).stdout)
        result=next(s for j in history['jobs'] for s in j['steps'] if s['name']=='enrolled_role_result')['outputs']
        self.assertTrue(result['role_proven']);self.assertTrue(result['footprint']['footprint_proven'])
        self.assertNotIn('packages',[json.loads(row)['action'] for row in self.audit.read_text().splitlines()])
        self.audit.unlink(missing_ok=True)
        payload['prepared']['node_id']=6
        refused=json.loads(self.cli('workflow','run','same-parent-role','--input',json.dumps(payload),success=False).stdout)
        self.assertEqual(refused['status'],'failed')
        self.assertFalse(self.audit.exists(), 'A wrong-Node prepared receipt must stop every SSH action')

    def websocket(self):
        self.ingress()
        intent=self.input['intent'];intent['role']='websocket'
        intent['runtime']={'node_id':5,'assignment_id':17,'user':'orbit','install_path':'/opt/orbit/reverb','repository':'https://example.invalid/reverb.git','ref':'b'*40,'port':8080}
        intent['certificates']={'leaves':[{'node_id':5,'scope':'role-websocket','domain':'reverb.orbit','version':'c'*16,'root_hash':'d'*64}],'withdrawals':[]}
        intent['publications']=[]
        self.input['execution_secrets']['runtime_material']={'vault':'orbit-control-inputs','key':'node-role-7-runtime_material'}

    def test_websocket_prerequisite_and_publication_failure_preserve_firewall(self):
        self.websocket()
        path=self.repo/'workflows/workflow-node-role-publication.yaml'
        flow=yaml.safe_load(path.read_text())
        flow['jobs']=[{'name':'fixture','steps':[{'name':'refused','task':{'type':'assert','expr':'false','severity':'high','message':'Admitted publication fixture fails'}}]}]
        path.write_text(yaml.safe_dump(flow,sort_keys=False))
        for failure in ['caddy_package','packages','publication']:
            with self.subTest(failure=failure):
                self.audit.unlink(missing_ok=True)
                self.failure.write_text(failure)
                _,result=self.run_role(False)
                self.assertFalse(result['role_proven'])
                actions=[json.loads(row)['action'] for row in self.audit.read_text().splitlines()]
                self.assertNotIn('firewall',actions)
                self.assertNotIn('firewall_prune',actions)

    def test_websocket_runtime_certificate_publication_and_original_root_identity(self):
        self.websocket()
        module=self.repo/'extensions/behaviors/operations/node-role-converge-command/supplier_fixture.ts'
        module.write_text('''import {z} from "npm:zod@4.4.3";
export const behavior={type:"@nckrtl/role-supplier-fixture",version:"2026.11.01.1",resources:{result:{schema:z.record(z.string(),z.unknown()),lifetime:"1d",garbageCollection:10}},methods:{complete:{description:"Return a typed supplier boundary fixture",arguments:z.object({action:z.string(),parent_run_id:z.string().optional()}),execute:async(args:{action:string;parent_run_id?:string},ctx:{writeResource(s:string,n:string,v:unknown):Promise<unknown>})=>{await Deno.writeTextFile(AUDIT,JSON.stringify(args)+"\\n",{append:true});const result={completed:true,ready:true,node_id:5,scope:"role-websocket",version:"cccccccccccccccc"};await ctx.writeResource("result","result",result);return result;}}}};
'''.replace('AUDIT',json.dumps(str(self.audit))))
        for name,action in [('node-role-websocket','runtime'),('node-role-certificate-publish','certificate'),('node-role-publication','publication')]:
            path=self.repo/('workflows/workflow-'+name+'.yaml')
            flow=yaml.safe_load(path.read_text())
            args={'action':action}
            if action=='certificate':args['parent_run_id']='${{ inputs.parent_run_id }}'
            flow.pop('target',None)
            flow['jobs']=[{'name':'fixture','steps':[{'name':'result','task':{'type':'behavior','behaviorType':'@nckrtl/role-supplier-fixture','behaviorName':action+'-fixture','methodName':'complete','inputs':args}}]}]
            path.write_text(yaml.safe_dump(flow,sort_keys=False))
        _,result=self.run_role()
        self.assertTrue(result['role_proven']);self.assertTrue(result['runtime_proven']);self.assertTrue(result['publication_proven'])
        rows=[json.loads(row) for row in self.audit.read_text().splitlines()]
        calls=[row['action'] for row in rows]
        self.assertLess(calls.index('runtime'),calls.index('certificate'))
        self.assertLess(calls.index('certificate'),calls.index('publication'))
        self.assertLess(calls.index('publication'),calls.index('firewall'))
        self.assertEqual(next(r for r in rows if r['action']=='certificate')['parent_run_id'],self.last_result['id'])
