"""Direct SQLite cleanup runs and recovery through the pinned native Swamp workflow."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]
SWAMP = os.environ.get('SWAMP_BIN', shutil.which('swamp') or 'swamp')


class DatabaseCleanupNative(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='orbit-database-direct-')
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / '.swamp.yaml', self.repo)
        behavior = self.repo / 'extensions/behaviors'
        for family in ['automatic-deploy', 'operation-runner', 'operations/database']:
            shutil.copytree(ROOT / 'extensions/behaviors' / family, behavior / family)
        module = behavior / 'operations/database/cleanup.ts'
        module.write_text('import {fixtureHttp} from "./cleanup_native_fixture.ts";\n' + module.read_text().replace('export const behavior = cleanup();', 'export const behavior = cleanup(() => fixtureHttp);'))
        (self.repo / 'workflows').mkdir()
        source = ROOT / 'workflows/workflow-operation-database-cleanup.yaml'
        (self.repo / 'workflows' / source.name).write_text('\n'.join(line for line in source.read_text().splitlines() if not line.startswith('labels:'))+'\n')
        self.state = {'operation':{'id':17,'kind':'database.sqlite_cleanup','node_id':9,'status':'queued','result':None,'cancel_requested':False},'calls':[],'cleanups':0}
        self.save()

    def save(self):
        (self.repo/'native-database-cleanup.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo/'native-database-cleanup.json').read_text())

    def cli(self, *args, success=True):
        env = dict(os.environ, SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1')
        for key in ('SWAMP_SERVER_URL','SWAMP_SERVE_URL','SWAMP_SERVER_TOKEN','SWAMP_SERVER_TOKEN_FILE','SWAMP_REPO_DIR','SWAMP_CA_CERT'):
            env.pop(key,None)
        result = subprocess.run([SWAMP,*args,'--json'],cwd=self.repo,env=env,text=True,capture_output=True,timeout=60)
        if success:
            self.assertEqual(result.returncode,0,result.stdout+result.stderr)
        else:
            self.assertNotEqual(result.returncode,0,result.stdout+result.stderr)
        return result

    def run_workflow(self, success=True):
        result = self.cli('workflow','run','operation-database-cleanup','--input','operation_id=17','--input','node_id=9','--input','kind='+self.state['operation']['kind'],success=success)
        self.run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
        return result

    def test_direct_cleanup_has_no_discovery_claim_heartbeat_or_receipt(self):
        self.run_workflow()
        state=self.read()
        self.assertEqual(state['cleanups'],1)
        self.assertEqual(state['operation']['status'],'succeeded')
        self.assertTrue(all('/direct-operations/' in call for call in state['calls']))
        self.assertFalse(any('claim' in call or 'heartbeat' in call or 'receipts' in call for call in state['calls']))

    def test_native_retry_after_lost_terminal_projection_does_not_repeat_effect(self):
        self.state['loseCompletion']=True
        self.save()
        self.run_workflow(success=False)
        self.cli('workflow','resume','operation-database-cleanup','--run',self.run_id,'--from','cleanup')
        self.assertEqual(self.read()['cleanups'],1)

    def test_native_retry_can_repeat_convergent_cleanup_without_a_gateway_receipt(self):
        self.state['loseCleanup']=True
        self.save()
        self.run_workflow(success=False)
        self.cli('workflow','resume','operation-database-cleanup','--run',self.run_id,'--from','cleanup')
        state=self.read()
        self.assertEqual(state['cleanups'],2)
        self.assertEqual(state['operation']['status'],'succeeded')

    def test_native_behavior_lock_serializes_two_cleanups_on_the_same_node(self):
        first=self.state.pop('operation')
        self.state['operations']={'17':first,'18':dict(first,id=18)}
        self.state['events']=[]
        self.save()
        env=dict(os.environ,SWAMP_NO_UPDATE_CHECK='1',SWAMP_NO_TELEMETRY='1')
        processes=[]
        try:
            for operation in (17,18):
                processes.append(subprocess.Popen([SWAMP,'workflow','run','operation-database-cleanup','--input',f'operation_id={operation}','--input','node_id=9','--input','kind=database.sqlite_cleanup','--json'],cwd=self.repo,env=env,text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE))
            for process in processes:
                stdout,stderr=process.communicate(timeout=60)
                self.assertEqual(process.returncode,0,stdout+stderr)
        finally:
            for process in processes:
                if process.poll() is None:
                    process.kill()
                    process.communicate()
        events=self.read()['events']
        self.assertIn(events,[['start-17','end-17','start-18','end-18'],['start-18','end-18','start-17','end-17']])


if __name__ == '__main__':
    unittest.main()
