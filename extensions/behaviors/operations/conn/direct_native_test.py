"""Pinned native DAG regression proof. Live credential review/canaries are separate."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import uuid

ROOT = Path(__file__).resolve().parents[4]
SWAMP = os.environ.get('SWAMP_BIN', 'swamp')

class DirectConnPairingNative(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='conn-vault-native-')
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        self.secret = 'private-sentinel-' + uuid.uuid4().hex
        shutil.copy(ROOT / '.swamp.yaml', self.repo)
        for family in ['automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/conn']:
            shutil.copytree(ROOT / 'extensions/behaviors' / family, self.repo / 'extensions/behaviors' / family)
        p = self.repo / 'extensions/behaviors/operations/conn/mod.ts'
        p.write_text('import { fixtureHttp, fixtureT3 } from "./direct_native_fixture.ts";\n'+p.read_text().replace('...connDirect()', '...connDirect(() => fixtureHttp, fixtureT3)'))
        (self.repo/'workflows').mkdir()
        for family in ['conn']:
            p = ROOT/f'workflows/workflow-operation-{family}-direct.yaml'
            # Local action regressions omit placement; the separate test below exercises it unchanged.
            (self.repo/'workflows'/p.name).write_text('\n'.join(line for line in p.read_text().splitlines() if not line.lstrip().startswith(('labels:', 'target:')))+'\n')
        self.state = {'operation': {'id':17,'kind':'conn.pairing_create','node_id':9,'status':'queued','input':{},'result':None,'cancel_requested':False},
            'private': {'environment':{'id':1,'url':'http://t3.test'},'labels':['phone'],'links':['link-1'],'pairing_id':7,'issue_started':False,'issued':False,'pairing_link_id':None,'expires_at':None},'calls':[],'effects':0}
        self.save()
    def save(self): (self.repo/'native-direct.json').write_text(json.dumps(self.state))
    def read(self): return json.loads((self.repo/'native-direct.json').read_text())
    def cli(self,*args,success=True):
        env=dict(os.environ,SWAMP_NO_UPDATE_CHECK='1',SWAMP_NO_TELEMETRY='1',ORBIT_PRIVATE_TEST_SENTINEL=self.secret)
        for key in ['SWAMP_SERVER_URL','SWAMP_SERVE_URL','SWAMP_SERVER_TOKEN','SWAMP_SERVER_TOKEN_FILE','SWAMP_REPO_DIR','SWAMP_CA_CERT']:
            env.pop(key,None)
        result=subprocess.run([SWAMP,*args,'--json'],cwd=self.repo,env=env,text=True,capture_output=True,timeout=50)
        self.assertEqual(result.returncode==0,success,result.stdout+result.stderr)
        self.assertNotIn(self.secret,result.stdout+result.stderr)
        for path in self.repo.rglob('*'):
            if path.is_file(): self.assertNotIn(self.secret.encode(),path.read_bytes(),str(path))
        return result
    def run_workflow(self,kind,success=True):
        self.state['operation']['kind']=kind
        self.state['operation']['status']='queued'
        self.save()
        name='operation-'+kind.split('.')[0]+'-direct'
        self.cli('workflow','validate',name)
        result=self.cli('workflow','run',name,'--input','operation_id='+str(self.state['operation']['id']),'--input','node_id=9','--input','kind='+kind,success=success)
        self.run_id=json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
    def test_pairing_uses_native_dag_without_executor_or_credential_data(self):
        for kind in ['conn.pairing_create']:
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
