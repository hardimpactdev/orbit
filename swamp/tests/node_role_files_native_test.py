"""Execute the retained role's native footprint graph with harmless child boundaries."""
import json
import yaml
from native_harness import ROOT, NativeTestCase


class RoleFilesNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations', 'node-worker', 'automatic-deploy')
        (self.repo/'extensions/behaviors/native-role-fixture.ts').write_text('''import {z} from "npm:zod@4.4.3";
export const behavior={type:"@fixture/native-role-files",version:"2026.11.02.1",resources:{data:{schema:z.record(z.string(),z.unknown()),lifetime:"1d",garbageCollection:10}},methods:{
plan:{description:"Public admission fixture",arguments:z.object({snapshot:z.record(z.string(),z.unknown())}),execute:async(args,ctx)=>{const data={snapshot:args.snapshot,ready:true,started_at:"2026-11-02T00:00:00Z",refusal:null};await ctx.writeResource("data","data",data);return data;}},
apply:{description:"Harmless child transport boundary",arguments:z.object({fail:z.boolean()}),execute:async(args)=>{if(args.fail)throw new Error("Native child failed");return {};}}
}};''')
        source=yaml.safe_load((ROOT/'workflows/workflow-operation-node-role-relocate-direct.yaml').read_text())
        jobs=[]
        for job in source['jobs']:
            if job['name'] not in ('private_dns_job','php_runtime_job','tmpfiles_job','route_residue_job'):
                continue
            job['dependsOn']=[{'job':'admission','condition':{'type':'succeeded'}}]
            for step in job['steps']:
                step.pop('labels',None)
                task=step['task']
                if task.get('type')=='workflow':
                    task['inputs']['intent']['fixture_fail']='${{ inputs.fail }}'
                elif task.get('behaviorType')=='@swamp/cfgmgmt/file':
                    task.clear()
                    task.update(type='behavior',behaviorType='@fixture/native-role-files',behaviorName='tmpfiles-fixture',methodName='apply',inputs={'fail':'${{ inputs.fail }}'})
            jobs.append(job)
        self.assertEqual(len(jobs),4)
        fixture={'id':source['id'],'name':'role-files-native','inputs':{'type':'object','properties':{'operation_id':{'type':'integer'},'node_id':{'type':'integer'},'kind':{'type':'string'},'snapshot':{'type':'object'},'fail':{'type':'boolean'}},'required':['operation_id','node_id','kind','snapshot','fail']},'jobs':[{'name':'admission','steps':[{'name':name,'task':{'type':'behavior','behaviorType':'@fixture/native-role-files','behaviorName':name+'-fixture','methodName':'plan','inputs':{'snapshot':'${{ inputs.snapshot }}'}}} for name in ('admit','prepare_footprint')]},*jobs]}
        (self.repo/'workflows/workflow-role-files-native.yaml').write_text(yaml.safe_dump(fixture,sort_keys=False))
        for number,name in enumerate(('node-private-dns','node-php-runtime','node-route-residue')):
            child={'id':f'ddbefad5-f3c2-44d1-a53f-{number:012d}','name':name,'inputs':yaml.safe_load((ROOT/f'workflows/workflow-{name}.yaml').read_text())['inputs'],'jobs':[{'name':'native','steps':[{'name':'apply','task':{'type':'behavior','behaviorType':'@fixture/native-role-files','behaviorName':name+'-fixture','methodName':'apply','inputs':{'fail':'${{ inputs.intent.fixture_fail }}'}}}]}]}
            (self.repo/f'workflows/workflow-{name}.yaml').write_text(yaml.safe_dump(child))
        self.snapshot={'schema_version':1,'node_id':9,'agent_sha256':'a'*64,'assignment_id':11,'role':'app-dev','created':False,'user':'orbit','publication':{'caddy_node_ids':[],'php_node_ids':[],'dns_node_ids':[]},'prerequisites':{'packages':[],'releases':['resolute'],'apps_directory':False,'composer_home':False},'firewall':None,'cli':{'user':'orbit','release':None,'profile':'','skip_reason':'release_unavailable','plan_error':None},'ssh':{'target':{'host':'fixture','user':'orbit','port':22},'identity_file':'/synthetic'},'role_files':{'dns':{},'residue':{},'home':'/home/orbit','php':{'8.5':'public ini'},'php_versions':['8.5'],'tmpfiles':'e /tmp/orbit-* - - - 1d'},'footprint':{'schema_version':1,'node_id':9,'agent_sha256':'a'*64,'force_artifacts':False,'first_visit':False,'network':None,'refusal':None,'payloads':{},'artifacts':[{'name':name,'digest':'c'*64,'required':True,'repair':False} for name in ('private-dns','php-runtime','route-residue','tmpfiles')]}}

    def run_parts(self, fail=False, success=True):
        self.cli('workflow','validate','role-files-native')
        result=self.cli('workflow','run','role-files-native','--input',json.dumps({'operation_id':7,'node_id':9,'kind':'node.role_add','snapshot':self.snapshot,'fail':fail}),success=success)
        run=json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
        return json.loads(self.cli('workflow','history','get',run).stdout)

    def test_native_resources_prove_every_required_part(self):
        history=self.run_parts()
        self.assertEqual(history['status'],'succeeded')
        names={step['name']:step['status'] for job in history['jobs'] for step in job['steps']}
        self.assertEqual(names['native_php_runtime-8.5'],'succeeded')
        for name in ('private_dns','php_runtime','route_residue','tmpfiles'):
            self.assertEqual(names[name+'_proven'],'succeeded')

    def test_failed_children_fail_required_parts_and_keep_residue_best_effort(self):
        history=self.run_parts(fail=True,success=False)
        names={step['name']:step['status'] for job in history['jobs'] for step in job['steps']}
        for name in ('private_dns','php_runtime','tmpfiles'):
            self.assertEqual(names[name+'_proven'],'failed')
        self.assertEqual(names['route_residue_proven'],'succeeded')
