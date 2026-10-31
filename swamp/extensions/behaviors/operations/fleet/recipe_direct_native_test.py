"""Real native recipe DAG, isolated fixture repo; no Node effects."""
import json
from pathlib import Path
import re
import sys
import unittest
import threading
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

class RecipeDirectNative(NativeTestCase):
    native_timeout = 60

    def setUp(self):
        super().setUp()
        self.native_env['SWAMP_NO_INBOX_NOTICE'] = '1'
        self.copy_behaviors('automatic-deploy', 'operations/_lib', 'operations/node', 'operations/fleet', 'operations/tools', 'operations/routes', 'operations/certificates', 'node-worker')
        module = self.repo / 'extensions/behaviors/operations/fleet/recipe_direct.ts'
        module.write_text('import {fixtureAction,fixtureHttp,fixtureNow} from "./recipe_direct_native_fixture.ts";\n'+module.read_text().replace('export const behavior = recipeDirect();','export const behavior = recipeDirect(fixtureAction, () => fixtureHttp, fixtureNow);'))
        failure = self.repo / 'extensions/behaviors/operations/failure.ts'
        failure.write_text('import {fixtureHttp} from "./fleet/recipe_direct_native_fixture.ts";\n' + (ROOT / 'extensions/behaviors/operations/failure.ts').read_text().replace('export const behavior = nativeFailure();', 'export const behavior = nativeFailure(() => fixtureHttp);'))
        self.source=ROOT / 'workflows/workflow-operation-fleet-recipe-direct.yaml'
        text='\n'.join(line for line in self.source.read_text().splitlines() if not re.match(r'^\s*(?:target|labels|node):',line))+'\n'
        (self.repo/'workflows'/self.source.name).write_text(text)
        pin='a'*64
        self.state={'operation':{'id':7,'kind':'fleet.recipe','node_id':9,'status':'queued','result':None,'cancel_requested':False,'input':{
            'node_id':9,'identity':'recipe-lock','snapshot':'20261031T000000Z','agent_sha256':pin,'cli_version':'0.12.0','automatic':False,'daytime':False,
            'recipe':{'user':'orbit','snapshot':'20261031T000000Z','tools':{}},'php':None,'incus_hold':[],
            'footprint':{'schema_version':1,'node_id':9,'agent_sha256':pin,'force_artifacts':True,'first_visit':False,'refusal':None,'network':None,'payloads':{},'artifacts':[{'name':name,'digest':'b'*64,'required':True,'repair':False} for name in ['agent','tmpfiles']]},
            'cli':{'user':'orbit','release':{'version':'0.12.0','url':'https://example.test/orbit','sha256':'d'*64},'profile':'safe-profile','skip_reason':None,'plan_error':None},
        }},'effects':[],'calls':[]}
        self.save()

    def save(self):
        (self.repo/'native-recipe.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo/'native-recipe.json').read_text())

    def run_workflow(self,success=True):
        self.cli('workflow','validate','operation-fleet-recipe-direct')
        result=self.cli('workflow','run','operation-fleet-recipe-direct','--input','operation_id=7','--input','node_id=9','--input','kind=fleet.recipe',success=success)
        self.run_id=json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def test_exact_placement_for_two_nodes_has_no_fallback(self):
        (self.repo/'workflows'/self.source.name).write_text(self.source.read_text())
        for node in (9,42):
            result=self.cli('workflow','evaluate','operation-fleet-recipe-direct','--input','operation_id=7','--input',f'node_id={node}','--input','kind=fleet.recipe')
            data=json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
            evaluated=Path(data['outputPath']).read_text()
            self.assertRegex(evaluated,rf'(?m)^target: orbit-node-{node}$')
            self.assertRegex(evaluated,rf"(?m)^labels:\n  node: '{node}'$")

    def test_one_root_contains_recipe_footprint_cli_and_delivery(self):
        self.run_workflow()
        state=self.read()
        self.assertEqual(state['operation']['status'],'succeeded')
        self.assertEqual(len(state['effects']),14)
        self.assertTrue(state['operation']['result']['footprint']['footprint_proven'])
        self.assertEqual(len(state['operation']['result']['footprint']['artifacts']),2)
        self.assertEqual(state['effects'][-2:],['cli','delivery'])
        self.assertTrue(all('/direct-operations/7' in call['path'] for call in state['calls']))

    def test_unported_input_fails_before_mutation_or_running_attempt(self):
        self.state['operation']['input']['footprint']['refusal']='operation.kind_unavailable'
        self.save()
        self.run_workflow(success=False)
        state=self.read()
        self.assertEqual(state['effects'],[])
        self.assertEqual(state['operation']['error_code'],'operation.kind_unavailable')
        self.assertFalse(any(call.get('body',{}).get('status')=='running' for call in state['calls'] if call['method']=='POST'))

    def test_failed_admission_reports_same_root_without_effects(self):
        self.state['operation']['input']['footprint']['schema_version']=2;self.save()
        self.run_workflow(success=False)
        state=self.read()
        self.assertEqual(state['effects'],[])
        self.assertEqual(state['operation']['status'],'failed')
        self.assertEqual(state['operation']['error_code'],'operation.native_failed')

    def test_known_refusal_stops_later_effects(self):
        self.state['refusal']='php-build';self.save()
        self.run_workflow(success=False)
        state=self.read()
        self.assertEqual(state['operation']['status'],'failed')
        self.assertNotIn('cli',state['effects'])
        self.assertEqual(state['operation']['error_code'],'node.recipe_failed')

    def test_uncertain_effect_settles_failed_and_resume_never_reenters_effects(self):
        self.state['uncertain']='php-build';self.save()
        self.run_workflow(success=False)
        self.assertEqual(self.read()['operation']['status'],'failed')
        self.cli('workflow','resume','operation-fleet-recipe-direct','--run',self.run_id,success=False)
        state=self.read()
        self.assertEqual(state['operation']['status'],'failed')
        self.assertEqual(state['effects'].count('recipe-apply'),1)
        self.assertEqual(state['effects'].count('php-build'),1)

    def test_terminal_unknown_effect_stays_failed_after_later_cancellation(self):
        self.state['uncertain']='php-build';self.save()
        self.run_workflow(success=False)
        self.state=self.read();self.state['operation']['cancel_requested']=True;self.save()
        self.cli('workflow','resume','operation-fleet-recipe-direct','--run',self.run_id,success=False)
        state=self.read()
        self.assertEqual(state['effects'].count('php-build'),1)
        self.assertNotIn('caddy',state['effects'])
        self.assertEqual(state['operation']['status'],'failed')

    def test_lost_projection_does_not_repeat_completed_effects(self):
        self.state['loseProjection']=True;self.save()
        self.run_workflow(success=False)
        self.cli('workflow','resume','operation-fleet-recipe-direct','--run',self.run_id)
        self.assertEqual(len(self.read()['effects']),14)
        self.assertEqual(self.read()['operation']['status'],'succeeded')

    def test_cancellation_before_work_dispatches_no_effects(self):
        self.state['operation']['cancel_requested']=True;self.save()
        self.run_workflow()
        self.assertEqual(self.read()['effects'],[])
        self.assertEqual(self.read()['operation']['status'],'cancelled')

    def test_cancellation_after_apply_stops_at_next_checkpoint(self):
        self.state['cancelAfter']='recipe-apply';self.save()
        self.run_workflow(success=False)
        state=self.read()
        self.assertEqual(state['operation']['status'],'cancelled')
        self.assertNotIn('php-build',state['effects'])

    def test_terminal_unknown_write_cannot_repeat_after_the_original_window_closes(self):
        self.state['operation']['input']['automatic']=True
        self.state['now']='2026-10-31T03:00:00Z'
        self.state['uncertain']='php-build';self.save()
        self.run_workflow(success=False)
        self.state=self.read();self.state['now']='2026-10-31T12:00:00Z';self.save()
        self.cli('workflow','resume','operation-fleet-recipe-direct','--run',self.run_id,success=False)
        state=self.read()
        self.assertEqual(state['effects'].count('php-build'),1)
        self.assertNotIn('caddy',state['effects'])
        self.assertEqual(state['operation']['error_code'],'operation.native_failed')

    def test_private_hash_stays_in_the_canonical_footprint_callback(self):
        secret=uuid.uuid4().hex+uuid.uuid4().hex
        self.secrets=(secret,)
        self.native_env['ORBIT_RECIPE_PRIVATE_HASH']=secret
        self.state['operation']['input']['footprint']['payloads']['agent']={'configuration':'observer = true','certificate':'public','unit':'ProtectHome=yes','user':'orbit','apps_root':None,'checkouts':[],'url':None}
        self.save()
        requests=[]
        class Handler(BaseHTTPRequestHandler):
            def log_message(self,*args): pass
            def do_GET(self):
                requests.append(self.path)
                payload=json.dumps({'data':{'secret_hash':secret}}).encode()
                self.send_response(200);self.send_header('Content-Length',str(len(payload)));self.end_headers();self.wfile.write(payload)
        server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
        threading.Thread(target=server.serve_forever,daemon=True).start()
        self.addCleanup(server.server_close);self.addCleanup(server.shutdown)
        self.native_env['ORBIT_RECIPE_HTTP_FIXTURE']=f'http://127.0.0.1:{server.server_port}'
        self.run_workflow()
        self.assertEqual(self.read()['operation']['status'],'succeeded')
        self.assertEqual(len(requests),1)
        self.assertIn('/direct-operations/7/private-material?run_id=',requests[0])
        self.assert_private_absent()

if __name__=='__main__':
    unittest.main()
