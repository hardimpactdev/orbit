"""One real native destroy DAG; replace host effects and Gateway I/O only."""
import json
from pathlib import Path
import sys
import unittest
import yaml
ROOT=Path(__file__).resolve().parents[4]
sys.path.insert(0,str(ROOT/'tests'))
from native_harness import NativeTestCase

class DestroyNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy','operation-runner','operations')
        root=self.repo/'extensions/behaviors/operations/instance-lifecycle/destroy_root.ts'
        root.write_text('''import {fixtureHttp, fixtureStep, fixtureDatabase, fixtureCleanup, fixtureAccount, fixtureEngineRefused, fixtureSetParticipant} from "./fixtures/destroy_native.ts";
'''+root.read_text().replace('export const behavior = destroyRoot();','''const root = destroyRoot(() => fixtureHttp, fixtureStep, undefined, undefined, fixtureDatabase, fixtureCleanup, fixtureAccount);
export const behavior = {...root, methods: {...root.methods,
database: {...root.methods.database, execute: async (...args: Parameters<typeof root.methods.database.execute>) => {
 fixtureSetParticipant(args[0].target_node_id);
 try {return await root.methods.database.execute(...args);} finally {fixtureSetParticipant(null);}
}},
captureSources: {...root.methods.captureSources, execute: (...args: Parameters<typeof root.methods.captureSources.execute>) => {
 if (fixtureEngineRefused()) throw new Error("Family bundle refused before first callback");
 return root.methods.captureSources.execute(...args);
}},
fail: {...root.methods.fail, execute: (...args: Parameters<typeof root.methods.fail.execute>) => {
 if (fixtureEngineRefused()) throw new Error("Family bundle refused before first callback");
 return root.methods.fail.execute(...args);
}},
}};'''))
        publication=self.repo/'extensions/behaviors/operations/routes/publication.ts'
        publication.write_text('import {fixtureHttp, fixtureInvoke, fixturePhp} from "../instance-lifecycle/fixtures/destroy_native.ts";\n'+publication.read_text().replace('export const behavior = publication();','export const behavior = publication(() => fixtureHttp, fixtureInvoke, undefined, undefined, undefined, undefined, fixturePhp);'))
        failure=self.repo/'extensions/behaviors/operations/failure.ts'
        failure.write_text('import {fixtureHttp} from "./instance-lifecycle/fixtures/destroy_native.ts";\n'+failure.read_text().replace('nativeFailure();','nativeFailure(() => fixtureHttp);'))
        source=ROOT/'workflows/workflow-operation-instance-destroy.yaml'
        workflow=yaml.safe_load(source.read_text())
        for job in workflow['jobs']:
            self.assertEqual(job.pop('target'),'${{ "orbit-node-" + string(inputs.node_id) }}')
            self.assertEqual(job.pop('labels'),{'node':'${{ string(inputs.node_id) }}'})
            for step in job['steps']:
                if 'forEach' in step:
                    target=step.pop('target');labels=step.pop('labels')
                    self.assertIn('orbit-node-',target);self.assertIn('string(self.',labels['node'])
        (self.repo/'workflows'/source.name).write_text(yaml.safe_dump(workflow,sort_keys=False))
        self.save({'status':'queued','result':None,'events':[]})
    def save(self,value): (self.repo/'destroy-fixture.json').write_text(json.dumps(value))
    def read(self): return json.loads((self.repo/'destroy-fixture.json').read_text())
    def run_destroy(self,success=True):
        return self.cli('workflow','run','operation-instance-destroy','--input','operation_id=7','--input','node_id=9','--input','kind=instance.destroy',success=success)
    def test_full_root_orders_runtime_publication_database_and_source_finalization(self):
        self.cli('workflow','validate','operation-instance-destroy')
        self.run_destroy()
        state=self.read();self.assertEqual(state['status'],'succeeded');self.assertEqual(state['result'],{'removed':True})
        events=state['events'];stages=[r['stage'] for r in events if 'stage' in r]
        self.assertEqual(stages,['prepare_sources','runtime','teardown','verify_sources','databases','finalize_sources'])
        index=lambda key,value:next(i for i,row in enumerate(events) if row.get(key)==value)
        self.assertLess(index('stage','runtime'),index('stage','teardown'))
        self.assertLess(index('effect','caddy.publish'),index('stage','databases'))
        self.assertLess(index('stage','databases'),index('effect','certificate.remove-leaf'))
        self.assertLess(index('effect','lan.remove'),index('stage','finalize_sources'))
        self.assertFalse(any('/children' in r.get('path','') or '/executor' in r.get('path','') for r in events))
    def test_failed_database_retains_source_and_records_failed_step(self):
        state=self.read();state['fail']='databases';self.save(state);self.run_destroy(success=False)
        state=self.read();self.assertEqual(state['status'],'failed')
        self.assertEqual(state['result'],{'error_code':'instance.destroy_failed','failed_step':'databases'})
        self.assertFalse(any(r.get('stage')=='finalize_sources' or r.get('effect')=='certificate.remove-leaf' for r in state['events']))
    def test_failed_publication_never_starts_database_removal(self):
        state=self.read();state['fail']='caddy';self.save(state);self.run_destroy(success=False)
        state=self.read();self.assertEqual(state['status'],'failed')
        self.assertEqual(state['result']['failed_step'],'caddy')
        self.assertFalse(any(r.get('stage') in ['databases','finalize_sources'] for r in state['events']))
    def test_engine_failure_before_first_callback_still_closes_the_operation(self):
        state=self.read();state['engineRefused']=True;self.save(state);self.run_destroy(success=False)
        state=self.read();self.assertEqual(state['status'],'failed');self.assertEqual(state['error_code'],'operation.native_failed')
        self.assertEqual([r['path'] for r in state['events'] if 'path'in r],['/api/v1/direct-operations/7/failure'])

    def test_foreign_database_uses_the_exact_selected_http_view_and_private_field(self):
        state=self.read();state['foreignDatabase']=True;self.save(state);self.run_destroy()
        state=self.read();self.assertEqual(state['status'],'succeeded')
        self.assertEqual(sum(r.get('effect')=='database-owned-private-read' for r in state['events']),1)
        self.assertNotIn('synthetic-private-password',json.dumps(state['events']))
    def test_new_root_continues_after_a_peer_completed_source_finalization(self):
        state=self.read();state['retryTest']=True;self.save(state);self.run_destroy(success=False)
        state=self.read();self.assertEqual(state['status'],'failed');self.assertTrue(state['completedPeer'])
        state.update(status='queued',result=None,secondRun=True,events=[]);self.save(state);self.run_destroy()
        state=self.read();self.assertEqual(state['status'],'succeeded')
        verified=[r for r in state['events'] if r.get('effect')=='verified-source']
        self.assertEqual(len(verified),1)
        import base64
        self.assertNotIn('/srv/apps/proof/work',base64.b64decode(verified[0]['worktrees']).decode())
        self.assertTrue(any(r.get('stage')=='finalize_sources' for r in state['events']))

if __name__=='__main__': unittest.main()
