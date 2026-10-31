"""Real native same-root DAG and canonical Caddy model, with synthetic Node-local effects."""
import hashlib
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

class ProcessStartNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/processes-schedules', 'operations/routes')
        routes = self.repo/'extensions/behaviors/operations/routes'
        process = self.repo/'extensions/behaviors/operations/processes-schedules/process-control.ts'
        process.write_text('import {http, invoke} from "./start_native_fixture.ts";\n'+process.read_text().replace('export const behavior = processControl();', 'export const behavior = processControl(() => http, undefined, startActions(invoke, () => Promise.resolve()));'))
        publication = routes/'publication.ts'
        publication.write_text('import {http,caddy} from "../processes-schedules/start_native_fixture.ts";\n'+publication.read_text().replace('export const behavior = publication();', 'export const behavior = publication(() => http, caddy);'))
        source = ROOT/'workflows/workflow-operation-process-control.yaml'
        (self.repo/'workflows'/source.name).write_text('\n'.join(line for line in source.read_text().splitlines() if not line.lstrip().startswith(('target:', 'labels:')))+'\n')
        marker='/dev/shm/orbit/hibernation/app-instance-8.awake'
        self.state={'calls': [], 'commands': [], 'caddy': [], 'events': [], 'operation': {'id':17,'node_id':9,'kind':'process.start','status':'queued','cancel_requested':False,'result':None,'input':{
            'process_id':7,'runtime':'systemd','name':'orbit-process-7-vite.service','agent_sha256':'a'*64,'on_demand':False,
            'vite': {'instance_id':8,'path':'/etc/orbit/vite/app-instance-8.env'},
            'vite_plan': {'suspend':{'operation':'suspend','marker':marker},'environment':{'operation':'environment','marker':marker,'path':'/etc/orbit/vite/app-instance-8.env','instance_id':8,'application':'/home/orbit/apps/proof','content':'# Orbit Instance 8\nORBIT_DEV_SERVER_PORT=4000\n'},'awake':{'operation':'awake','marker':marker},'listener':{'unit':'orbit-process-7-vite.service','port':4000,'process_id':7,'probe':True}},
            'caddy_publication':{'node_id':9,'user':'orbit','agent_sha256':'a'*64,'caddy':{'content':'# current fixture\n','version':hashlib.sha256(b'# current fixture\n').hexdigest()[:32],'listen_addresses':['10.44.0.9'],'unserved':False}}
        }}}
        self.save()

    def save(self):
        (self.repo/'start-native.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo/'start-native.json').read_text())

    def run_start(self, success=True):
        result=self.cli('workflow','run','operation-process-control','--input','operation_id=17','--input','node_id=9','--input','kind='+self.state['operation']['kind'],success=success)
        self.run=json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
        return self.read()

    def test_vite_start_has_one_root_and_publication_between_preparation_and_activation(self):
        state=self.run_start()
        self.assertEqual(state['operation']['status'],'succeeded')
        self.assertEqual(state['operation']['result'],{'ready':True,'state':'active'})
        events=state['events']
        self.assertLess(events.index('vite.set-traffic:environment'),events.index('caddy.publish'))
        self.assertLess(events.index('caddy.publish'),events.index('process.control:start'))
        self.assertLess(events.index('vite.check-listener:'),events.index('vite.set-traffic:awake'))
        self.assertEqual(len(state['caddy']),1)
        self.assertTrue(all('/direct-operations/17' in call['url'] for call in state['calls']))
        run_ids={call['url'].split('run_id=')[1] for call in state['calls'] if call['method']=='GET'}
        self.assertEqual(run_ids,{self.run['id']})
        self.assertEqual([c['body']['status'] for c in state['calls'] if c['method']=='POST'],['running','succeeded'])

    def test_non_vite_start_skips_canonical_publication(self):
        self.state['operation']['input']['vite']=None
        self.state['operation']['input'].pop('vite_plan')
        self.state['operation']['input'].pop('caddy_publication')
        self.save()
        state=self.run_start()
        self.assertEqual(state['operation']['status'],'succeeded')
        self.assertEqual(state['caddy'],[])
        self.assertEqual([c['name'] for c in state['commands']],['process.control','process.status'])

    def test_caddy_refusal_projects_parent_failure_without_start_or_awake(self):
        self.state['refuseCaddy']=True
        self.save()
        state=self.run_start(success=False)
        self.assertEqual(state['operation']['status'],'failed')
        self.assertEqual(state['operation']['result'],{'error_code':'process.caddy_failed'})
        self.assertFalse(any(c['name']=='process.control' for c in state['commands']))
        self.assertFalse(any(c['input'].get('operation')=='awake' for c in state['commands']))

    def test_cancelled_intent_has_no_runtime_or_publication_effect(self):
        self.state['operation']['cancel_requested']=True
        self.save()
        state=self.run_start()
        self.assertEqual(state['operation']['status'],'cancelled')
        self.assertEqual(state['commands'],[])
        self.assertEqual(state['caddy'],[])


    def test_vite_restart_stops_prepares_publishes_and_starts_in_one_root_without_awake(self):
        self.state['operation']['kind']='process.restart'
        self.save()
        state=self.run_start()
        self.assertEqual(state['operation']['status'],'succeeded')
        events=state['events']
        self.assertLess(events.index('process.control:stop'),events.index('vite.set-traffic:environment'))
        self.assertLess(events.index('vite.set-traffic:environment'),events.index('caddy.publish'))
        self.assertLess(events.index('caddy.publish'),events.index('process.control:start'))
        self.assertNotIn('vite.set-traffic:awake',events)
        self.assertEqual({call['url'].split('run_id=')[1] for call in state['calls'] if call['method']=='GET'},{self.run['id']})
