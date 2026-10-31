"""Both destruction parents run through the shared pinned-native harness."""
import json
import yaml
import secrets
from pathlib import Path
import sys
ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

class DatabaseDestroyOwnedNative(NativeTestCase):
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
    def run_parent(self, owned=False, success=True, engine_failure=None, wrong_selected_node=False):
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
        if engine_failure:
            job = next(job for job in fixture_workflow['jobs'] if job['name'] == engine_failure)
            job['steps'][0]['task']['methodName'] = 'missing-method'
        if wrong_selected_node:
            job = next(job for job in fixture_workflow['jobs'] if job['name'] == 'effects')
            job['steps'][0]['task']['inputs']['selected_node_id'] = 10
        (self.repo / 'workflows' / source.name).write_text(yaml.safe_dump(fixture_workflow, sort_keys=False))
        self.save()
        if not engine_failure:
            self.cli('workflow','validate',self.workflow)
        result = self.cli('workflow','run',self.workflow,'--input','operation_id=17','--input','node_id=209','--input','kind='+self.state['operation']['kind'],success=success)
        self.run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
    def test_owned_two_nodes_are_steps_in_one_parent_run(self):
        self.state['operation'].update(kind='database.destroy_owned',input={'servers':[{'server_id':42,'node_id':9,'intent':self.intent},{'server_id':43,'node_id':10,'intent':self.intent}],'sqlite':[]})
        self.run_parent(owned=True)
        state=self.read()
        self.assertEqual(state['operation']['status'],'succeeded')
        self.assertEqual(state['effects'],6)
        self.assertTrue(all('/direct-operations/17' in p for p in state['paths']))
    def test_empty_owned_set_projects_success(self):
        self.state['operation'].update(kind='database.destroy_owned',input={'servers':[],'sqlite':[]})
        self.run_parent(owned=True)
        self.assertEqual(self.read()['operation']['status'],'succeeded')
        self.assertEqual(self.read()['effects'],0)
    def test_owned_refusal_projects_failed_without_gateway_children(self):
        self.state['refuse']=True
        self.state['operation'].update(kind='database.destroy_owned',input={'servers':[{'server_id':42,'node_id':9,'intent':self.intent}],'sqlite':[]})
        self.run_parent(owned=True,success=False)
        self.assertEqual(self.read()['operation']['status'],'failed')
        self.assertEqual(self.read()['operation']['result'],{'error_code':'database.ownership_unproven'})

    def owned(self):
        self.state['operation'].update(kind='database.destroy_owned', input={'servers':[{'server_id':42,'node_id':9,'intent':self.intent}], 'sqlite':[]})

    def test_cancel_before_plan_skips_all_placed_effects(self):
        self.owned()
        self.state['operation']['cancel_requested'] = True
        self.run_parent(owned=True)
        state = self.read()
        self.assertEqual(state['operation']['status'], 'cancelled')
        self.assertEqual(state['effects'], 0)
        self.assertFalse(any('private-material' in path for path in state['paths']))

    def test_cancel_between_plan_and_placed_effect_skips_sql(self):
        self.owned()
        self.state['cancelOnRead'] = 2
        self.run_parent(owned=True)
        state = self.read()
        self.assertEqual(state['operation']['status'], 'cancelled')
        self.assertEqual(state['effects'], 0)
        self.assertFalse(any('private-material' in path for path in state['paths']))

    def test_wrong_selected_node_refuses_before_private_or_effect(self):
        self.owned()
        self.run_parent(owned=True, success=False, wrong_selected_node=True)
        state = self.read()
        self.assertEqual(state['operation']['status'], 'failed')
        self.assertEqual(state['effects'], 0)
        self.assertFalse(any('private-material' in path for path in state['paths']))
        self.assertEqual(sum(path.endswith('/failure') for path in state['paths']), 1)

    def test_engine_failure_before_plan_or_effect_reports_parent(self):
        for job in ['admission', 'effects']:
            with self.subTest(job=job):
                self.owned()
                self.state['operation']['status'] = 'queued'
                self.state['paths'] = []
                self.state['effects'] = 0
                self.run_parent(owned=True, success=False, engine_failure=job)
                state = self.read()
                self.assertEqual(state['operation']['status'], 'failed')
                self.assertEqual(state['operation']['error_code'], 'operation.native_failed')
                self.assertEqual(state['effects'], 0)
                self.assertFalse(any('private-material' in path for path in state['paths']))
                self.assertEqual(sum(path.endswith('/failure') for path in state['paths']), 1)

    def test_owned_lost_projection_resumes_same_root_without_repeating_sql(self):
        self.owned()
        self.state['loseProject'] = True
        self.run_parent(owned=True, success=False)
        before = self.read()
        self.assertEqual(before['operation']['status'], 'running')
        self.assertEqual(before['effects'], 3)
        self.cli('workflow', 'resume', self.workflow, '--run', self.run_id)
        state = self.read()
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['effects'], before['effects'])
