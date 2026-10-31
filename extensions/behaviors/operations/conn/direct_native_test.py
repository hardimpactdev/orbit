"""Pinned native DAG regression proof. Live credential review/canaries are separate."""
import json
from pathlib import Path
import shutil
import unittest
import sys
import uuid

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

class DirectConnPairingNative(NativeTestCase):
    native_timeout = 50

    def setUp(self):
        super().setUp()
        self.secret = 'private-sentinel-' + uuid.uuid4().hex
        self.secrets = (self.secret,)
        self.native_env['ORBIT_PRIVATE_TEST_SENTINEL'] = self.secret
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/conn')
        p = self.repo / 'extensions/behaviors/operations/conn/mod.ts'
        p.write_text('import { fixtureHttp, fixtureT3 } from "./direct_native_fixture.ts";\n'+p.read_text().replace('...connDirect()', '...connDirect(() => fixtureHttp, fixtureT3)'))
        for family in ['conn']:
            p = ROOT/f'workflows/workflow-operation-{family}-direct.yaml'
            # Local action regressions omit placement; the separate test below exercises it unchanged.
            (self.repo/'workflows'/p.name).write_text('\n'.join(line for line in p.read_text().splitlines() if not line.lstrip().startswith(('labels:', 'target:')))+'\n')
        self.state = {'operation': {'id':17,'kind':'conn.pairing_create','node_id':9,'status':'queued','input':{},'result':None,'cancel_requested':False},
            'private': {'environment':{'id':1,'url':'http://t3.test'},'labels':['phone'],'links':['link-1'],'pairing_id':7,'issue_started':False,'issued':False,'pairing_link_id':None,'expires_at':None},'calls':[],'effects':0}
        self.save()
    def save(self): (self.repo/'native-direct.json').write_text(json.dumps(self.state))
    def read(self): return json.loads((self.repo/'native-direct.json').read_text())
    def run_workflow(self,kind,success=True):
        self.state['operation']['kind']=kind
        self.state['operation']['status']='queued'
        self.save()
        name='operation-'+kind.split('.')[0]+'-direct'
        self.cli('workflow','validate',name)
        result=self.cli('workflow','run',name,'--input','operation_id='+str(self.state['operation']['id']),'--input','node_id=9','--input','kind='+kind,success=success)
        self.run_id=json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
    def test_pairing_uses_native_dag_without_executor_or_credential_data(self):
        for kind in ['conn.pairing_create', 'conn.pairing_revoke', 'conn.gateway_sessions_revoke']:
            self.state['effects']=0
            self.run_workflow(kind)
            state=self.read()
            self.assertEqual(state['operation']['status'],'succeeded')
            self.assertGreater(state['effects'],0)
            self.assertFalse(any('operation-executor' in p for p in state['calls']))
    def test_lost_completion_does_not_issue_twice(self):
        self.state['loseProjection']=True
        self.run_workflow('conn.pairing_create',success=False)
        self.cli('workflow','resume','operation-conn-direct','--run',self.run_id,'--from','apply')
        self.assertEqual(self.read()['effects'],1)
        self.assertEqual(self.read()['operation']['status'],'succeeded')
    def test_lost_native_checkpoint_cannot_issue_a_second_link(self):
        self.state['private']['issue_started'] = True
        self.run_workflow('conn.pairing_create',success=False)
        self.assertEqual(self.read()['effects'],0)
        self.assertEqual(self.read()['operation']['result'],{'error_code':'conn.pairing_outcome_unknown'})
    def test_exact_worker_placement_cannot_fall_back_to_local(self):
        shutil.copy(ROOT/'workflows/workflow-operation-conn-direct.yaml', self.repo/'workflows/workflow-operation-conn-direct.yaml')
        self.run_workflow('conn.pairing_create',success=False)
        self.assertEqual(self.read()['effects'],0)
        self.assertEqual(self.read()['calls'],[])
    def test_issue_checkpoint_is_isolated_between_operations(self):
        self.state['loseStore'] = True
        self.run_workflow('conn.pairing_create',success=False)
        self.assertEqual(self.read()['effects'],1)
        self.state=self.read();self.state['operation']['id']=18
        self.state['private']['issued']=False
        self.state['private']['issue_started']=False
        self.run_workflow('conn.pairing_create')
        self.assertEqual(self.read()['effects'],2)
        self.state=self.read();self.state['operation']['id']=17
        self.state['private']['issued']=False
        self.run_workflow('conn.pairing_create',success=False)
        self.assertEqual(self.read()['effects'],2)
        self.assertEqual(self.read()['operation']['result']['error_code'],'conn.pairing_outcome_unknown')
    def test_unbound_first_read_never_reaches_private_credentials(self):
        self.state['unbound']=True
        self.run_workflow('conn.pairing_create',success=False)
        self.assertEqual(self.read()['effects'],0)

if __name__=='__main__': unittest.main()
