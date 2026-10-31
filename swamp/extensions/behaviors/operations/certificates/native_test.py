"""Real native certificate DAG checkpoints; local fake boundary, no live secrets."""
import json
import yaml
import shutil
from pathlib import Path
import sys
import unittest
ROOT=Path(__file__).resolve().parents[4]
sys.path.insert(0,str(ROOT/'tests'))
from native_harness import NativeTestCase

class CertificateNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy','operations/_lib','operations/certificates')
        failure=self.repo/'extensions/behaviors/operations/failure.ts'
        failure.write_text('import {fixtureHttp} from "./certificates/native_fixture.ts";\n'+(ROOT/'extensions/behaviors/operations/failure.ts').read_text().replace('export const behavior = nativeFailure();','export const behavior = nativeFailure(() => fixtureHttp);'))
        local=self.repo/'extensions/behaviors/operations/routes';local.mkdir(parents=True)
        for filename in ('local-program.ts','caddy-program.ts'): shutil.copy(ROOT/'extensions/behaviors/operations/routes'/filename,local)
        module=self.repo/'extensions/behaviors/operations/certificates/mod.ts'
        module.write_text('import {fixtureHttp,fixtureAgent,fixtureSign,fixtureRole} from "./native_fixture.ts";\n'+module.read_text().replace('z.string().min(1).optional()', 'z.string().min(1).default("disposable-fixture")').replace('...certificates()', '...certificates(() => fixtureHttp, certificateActions(fixtureAgent), fixtureSign, roleCertificateActions(fixtureRole))'))
        for kind in ('converge','remove'):
            source=ROOT/f'workflows/workflow-operation-certificate-{kind}.yaml'
            workflow=yaml.safe_load(source.read_text())
            for job in workflow['jobs']:
                for step in job['steps']:step.pop('target',None);step.pop('labels',None)
            (self.repo/'workflows'/source.name).write_text(yaml.safe_dump(workflow,sort_keys=False))
        self.state={'operation':{'id':19,'kind':'certificate.converge','node_id':2,'status':'queued','cancel_requested':False,'input':{'node_id':2,'scope':'route-8-router','home':'/home/orbit','agent_sha256':'a'*64,'domain':'private.test','version':'0123456789abcdef','root_hash':'b'*64,'user':'orbit'}},'present':False,'commands':[],'calls':[],'signs':0}
        self.save()
    def save(self): (self.repo/'native-certificates.json').write_text(json.dumps(self.state))
    def read(self): return json.loads((self.repo/'native-certificates.json').read_text())
    def run_workflow(self,kind='converge',success=True):
        name='operation-certificate-'+kind
        self.cli('workflow','validate',name)
        result=self.cli('workflow','run',name,'--input','operation_id=19','--input','node_id=2','--input','kind=certificate.'+kind,success=success)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
    def test_convergence_has_separate_native_steps_and_only_public_signing(self):
        self.run_workflow()
        state=self.read()
        self.assertEqual(state['commands'],['certificate.request-leaf','certificate.publish-leaf','certificate.inspect-leaf'])
        self.assertEqual(state['operation']['result'],{'present':True})
        self.assertEqual(state['signs'],1)
        self.assertTrue(all('/direct-operations/'in path for path in state['calls']))
    def test_current_leaf_skips_signing_and_publication(self):
        self.state.update(current=True,present=True);self.save();self.run_workflow()
        self.assertEqual(self.read()['commands'],['certificate.request-leaf','certificate.inspect-leaf'])
        self.assertEqual(self.read()['signs'],0)
    def test_lost_projection_resumes_without_new_node_effect(self):
        self.state['loseProjection']=True;self.save()
        run=self.run_workflow(success=False)
        before=self.read()['commands']
        self.cli('workflow','resume','operation-certificate-converge','--run',run,'--from','project')
        self.assertEqual(self.read()['commands'],before)
    def test_remove_has_local_effect_and_absence_inspection(self):
        self.state['operation'].update(kind='certificate.remove',input={k:v for k,v in self.state['operation']['input'].items()if k in ('node_id','scope','home','user','agent_sha256')})
        self.state['operation']['input']['in_use']=False
        self.state['present']=True;self.save();self.run_workflow('remove')
        self.assertEqual(self.read()['commands'],['certificate.remove-leaf','certificate.inspect-leaf'])
        self.assertEqual(self.read()['operation']['result'],{'present':False})
    def test_cancelled_before_effect_has_no_agent_command(self):
        self.state['operation']['cancel_requested']=True;self.save();self.run_workflow()
        self.assertEqual(self.read()['commands'],[])
        self.assertEqual(self.read()['operation']['status'],'cancelled')
    def test_home_less_admission_keeps_user_through_convergence_and_removal(self):
        self.state['operation']['input'].pop('home');self.save();self.run_workflow()
        self.assertEqual('succeeded',self.read()['operation']['status'])
        self.state=self.read();self.state['operation'].update(kind='certificate.remove',status='queued',input={key:value for key,value in self.state['operation']['input'].items() if key in ('node_id','scope','user','agent_sha256')})
        self.state['operation']['input']['in_use']=False;self.save();self.run_workflow('remove')
        self.assertEqual('succeeded',self.read()['operation']['status']);self.assertFalse(self.read()['present'])
    def test_role_csr_sign_and_publication_are_steps_in_the_same_native_root(self):
        leaf=dict(node_id=9,scope='role-analytics',domain='analytics.orbit',version='0123456789abcdef',root_hash='a'*64)
        steps=[]
        for name,method in [('request','roleRequest'),('sign','sign'),('publish','rolePublish')]:
            inputs=dict(operation_id=19,node_id=2,target_node_id=9,kind='node.role_add',run_id='${{ run.id }}',leaf=leaf)
            if name=='sign': inputs['native_stdout']='{"certificates":[]}'
            if name in ('sign','publish'): inputs['request']='${{ steps.request.outputs.request }}'
            if name=='publish': inputs['signed']='${{ steps.sign.outputs.signed }}'
            step=dict(name=name,task=dict(type='behavior',behaviorType='@nckrtl/certificates',behaviorName='orbit-certificate-signing' if name=='sign' else 'role-node-9',methodName=method,inputs=inputs))
            if steps: step['dependsOn']=[dict(step=steps[-1]['name'],condition=dict(type='succeeded'))]
            steps.append(step)
        workflow=dict(id='7752c33d-f803-4e90-8baf-a2d08923c460',name='role-certificate-fixture',jobs=[dict(name='role',steps=steps)])
        (self.repo/'workflows/workflow-role-certificate-fixture.yaml').write_text(yaml.safe_dump(workflow,sort_keys=False))
        self.cli('workflow','validate','role-certificate-fixture')
        self.cli('workflow','run','role-certificate-fixture')
        self.assertEqual(['role.request','role.publish'],self.read()['commands'])
        self.assertEqual(1,self.read()['signs']);self.assertEqual([],self.read()['calls'])
    def test_shipped_placement_uses_exact_target_for_every_step(self):
        source=ROOT/'workflows/workflow-operation-certificate-converge.yaml'
        (self.repo/'workflows'/source.name).write_text(source.read_text())
        for node in (2,209):
            result=self.cli('workflow','evaluate','operation-certificate-converge','--input',json.dumps({'operation_id':19,'node_id':node,'kind':'certificate.converge'}))
            workflow=json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
            self.assertTrue(all(step['target']==('orbit-node-209' if step['name'] in ('sign','sign-http','sign-http-input') else 'orbit-node-'+str(node)) for step in workflow['jobs'][0]['steps']))

if __name__=='__main__':unittest.main()
