"""Same-root creation with private material confined to the callback."""
import hashlib
import json
from pathlib import Path
import sys
import uuid

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class ProcessCreationNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/processes-schedules', 'operations/routes')
        self.secret = uuid.uuid4().hex
        self.secrets = (self.secret,)
        self.native_env = {'PROCESS_INSTALLATION_SECRET': self.secret}
        base = self.repo/'extensions/behaviors/operations'
        control = base/'processes-schedules/process-control.ts'
        control.write_text('import {http, invoke} from "./create_native_fixture.ts";\n'+control.read_text().replace('export const behavior = processControl();', 'export const behavior = processControl(() => http, undefined, startActions(invoke, () => Promise.resolve()), invoke);'))
        routes = base/'routes/publication.ts'
        routes.write_text('import {http,caddy} from "../processes-schedules/create_native_fixture.ts";\n'+routes.read_text().replace('export const behavior = publication();', 'export const behavior = publication(() => http, caddy);'))
        source=ROOT/'workflows/workflow-operation-process-control.yaml'
        (self.repo/'workflows'/source.name).write_text('\n'.join(line for line in source.read_text().splitlines() if not line.lstrip().startswith(('target:', 'labels:')))+'\n')
        installation={'process_id':7,'agent_sha256':'a'*64,'runtime':'systemd','name':'orbit-process-7-proof.service','on_demand':False,'retired':None,'desired':'stopped','activation_pending':False,'content_hash':hashlib.sha256(f'[Service]\nEnvironment=PASSWORD={self.secret}\n'.encode()).hexdigest()}
        control_input={key:installation[key] for key in ('process_id','agent_sha256','runtime','name','on_demand')}
        control_input['vite']=None
        self.state={'calls':[], 'commands':[], 'caddy':[], 'events':[], 'operation':{'id':17,'node_id':9,'kind':'process.create','status':'queued','cancel_requested':False,'result':None,'input':{'installation':installation,'control':control_input}}}
        self.save()

    def save(self):
        (self.repo/'start-native.json').write_text(json.dumps(self.state))

    def execute(self, success=True):
        reply=self.cli('workflow','run','operation-process-control','--input','operation_id=17','--input','node_id=9','--input','kind=process.create',success=success)
        self.run=json.JSONDecoder().raw_decode(reply.stdout.lstrip())[0]
        return json.loads((self.repo/'start-native.json').read_text())

    def test_private_stopped_installation_has_one_root_and_no_gateway_execution(self):
        state=self.execute()
        self.assertEqual(state['operation']['status'],'succeeded')
        self.assertEqual(state['operation']['result'],{'ready':True,'state':'inactive'})
        self.assertEqual([c['name'] for c in state['commands']],['process.stage-unit','process.activate-unit','process.status'])
        self.assertEqual(state['caddy'],[])
        self.assertTrue(all('/direct-operations/17' in c['url'] for c in state['calls']))
        self.assertEqual({c['url'].split('run_id=')[1] for c in state['calls'] if c['method']=='GET'},{self.run['id']})
        self.assertEqual(sum('/private-material?' in c['url'] for c in state['calls']),1)

    def test_vite_creation_prepares_then_publishes_then_installs_and_proves_listener(self):
        a=self.state['operation']['input']['installation']
        a['desired']='running'
        c=self.state['operation']['input']['control']
        c['vite']={'instance_id':8,'path':'/etc/orbit/vite/app-instance-8.env'}
        marker='/dev/shm/orbit/hibernation/app-instance-8.awake'
        c['vite_plan']={'suspend':{'operation':'suspend','marker':marker},'environment':{'operation':'environment','marker':marker,'application':'/home/orbit/apps/proof','path':c['vite']['path'],'instance_id':8,'content':'# Orbit Instance 8\nORBIT_DEV_SERVER_PORT=4000\n'},'awake':{'operation':'awake','marker':marker},'listener':{'unit':a['name'],'process_id':7,'port':4000,'probe':True}}
        publication={'node_id':9,'user':'orbit','agent_sha256':'a'*64,'caddy':{'content':'# public\n','version':hashlib.sha256(b'# public\n').hexdigest()[:32],'listen_addresses':['10.44.0.9'],'unserved':False}}
        self.state['operation']['input']['caddy_publication']=publication
        self.save()
        state=self.execute()
        events=state['events']
        self.assertLess(events.index('vite.set-traffic:environment'),events.index('caddy.publish'))
        self.assertLess(events.index('caddy.publish'),events.index('process.stage-unit'))
        self.assertLess(events.index('process.activate-unit'),events.index('vite.check-listener:'))
        self.assertNotIn('vite.set-traffic:awake',events)
        self.assertEqual(state['operation']['status'],'succeeded')

    def test_cancelled_creation_does_not_fetch_private_material_or_run_actions(self):
        self.state['operation']['cancel_requested']=True
        self.save()
        state=self.execute()
        self.assertEqual(state['operation']['status'],'cancelled')
        self.assertEqual(state['commands'],[])
        self.assertFalse(any('/private-material?' in c['url'] for c in state['calls']))

    def test_changed_private_definition_refuses_before_effect_without_leaking_bytes(self):
        self.state['operation']['input']['installation']['content_hash']='b'*64
        self.save()
        state=self.execute(success=False)
        self.assertEqual(state['commands'],[])
        self.assertNotEqual(state['operation']['status'],'succeeded')
