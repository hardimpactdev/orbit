"""Both destruction parents run through the shared pinned-native harness."""
import json
import yaml
import secrets
from pathlib import Path
import sys
ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

class DatabaseDestroyNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operations/_lib', 'operations/database')
        for module, factory, replace in [('destroy.ts', 'databaseDestroy', '...databaseDestroy()'), ('destroy_owned.ts', 'databaseDestroyOwned', '...databaseDestroyOwned()')]:
            path = self.repo / 'extensions/behaviors/operations/database' / module
            if path.exists():
                path.write_text('import {fixtureHttp,fixtureInvoke} from "./destroy_native_fixture.ts";\n' + path.read_text().replace(replace, '...'+factory+'(() => fixtureHttp, fixtureInvoke)'))
        self.secrets = (secrets.token_hex(24),)
        self.native_env = {'DESTROY_PRIVATE_SENTINEL': self.secrets[0]}
        self.intent = {'driver':'mysql','container':'orbit-process-42-proof','host':'10.44.0.9','port':3306,'default_open':False,'databases':['proof'],'users':['proof'],'test_prefixes':[],'owners':{'proof':'proof'}}
        self.state = {'operation':{'id':17,'kind':'database.destroy','node_id':209,'status':'queued','result':None,'cancel_requested':False,'input':self.intent},'paths':[],'effects':0}
    def save(self):
        (self.repo / 'native-destroy.json').write_text(json.dumps(self.state))
    def read(self):
        return json.loads((self.repo / 'native-destroy.json').read_text())
    def run_parent(self, owned=False, success=True):
        self.workflow = 'operation-database-destroy' + ('-owned' if owned else '')
        source = ROOT / 'workflows' / ('workflow-'+self.workflow+'.yaml')
        # Local fixture retains the DAG while removing only worker placement.
        fixture_workflow = yaml.safe_load(source.read_text())
        fixture_workflow.pop('target', None)
        fixture_workflow.pop('labels', None)
        for job in fixture_workflow['jobs']:
            job.pop('target', None)
            job.pop('labels', None)
            for step in job['steps']:
                step.pop('target', None)
                step.pop('labels', None)
        (self.repo / 'workflows' / source.name).write_text(yaml.safe_dump(fixture_workflow, sort_keys=False))
        self.save()
        self.cli('workflow','validate',self.workflow)
        result = self.cli('workflow','run',self.workflow,'--input','operation_id=17','--input','node_id=209','--input','kind='+self.state['operation']['kind'],success=success)
        self.run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
    def test_single_destroy_has_no_discovery_claim_or_gateway_executor(self):
        self.run_parent()
        state=self.read()
        self.assertEqual(state['operation']['status'],'succeeded')
        self.assertEqual(state['effects'],3)
        self.assertTrue(all('/direct-operations/' in p for p in state['paths']))
    def test_lost_terminal_projection_resumes_without_repeating_effect(self):
        self.state['loseProject']=True
        self.run_parent(success=False)
        effects=self.read()['effects']
        self.cli('workflow','resume',self.workflow,'--run',self.run_id)
        self.assertEqual(self.read()['effects'],effects)
        self.assertEqual(self.read()['operation']['status'],'succeeded')
