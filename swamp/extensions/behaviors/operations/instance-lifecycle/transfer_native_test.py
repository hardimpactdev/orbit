"""Exercise the real native transfer DAG with fixed local/Gateway boundaries."""
import json
from pathlib import Path
import sys
import unittest
import yaml
ROOT=Path(__file__).resolve().parents[4]
sys.path.insert(0,str(ROOT/'tests'))
from native_harness import NativeTestCase
class TransferNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy','operation-runner','operations')
        source=ROOT/'workflows/workflow-operation-instance-transfer.yaml'
        workflow=yaml.safe_load(source.read_text())
        workflow['vaults']=[]
        for job in workflow['jobs']:
            job.pop('target',None);job.pop('labels',None)
            for step in job['steps']:
                step.pop('target',None);step.pop('labels',None)
        (self.repo/'workflows'/source.name).write_text(yaml.safe_dump(workflow,sort_keys=False))
        module=self.repo/'extensions/behaviors/operations/instance-lifecycle/transfer_root.ts'
        module.write_text('import {transferHttp,transferEffects,fixtureTargetActions,fixtureRemovalActions,fixtureRuntime,fixtureSqlite} from "./transfer_native_fixture.ts";\n'+module.read_text().replace('transferRoot();','transferRoot((_ctx,args)=>transferHttp(args),transferEffects,fixtureTargetActions,fixtureRemovalActions,fixtureRuntime,fixtureSqlite);'))
        for file,kind in [('routes/publication.ts','@nckrtl/route-publication'),('certificates/mod.ts','@nckrtl/certificates')]:
            (self.repo/'extensions/behaviors/operations'/file).write_text('import {fixtureSharedBehavior} from "../instance-lifecycle/transfer_native_fixture.ts";\nexport const behavior = { ...fixtureSharedBehavior("'+kind+'"), type: "'+kind+'" };\n')
        failure=self.repo/'extensions/behaviors/operations/failure.ts'
        failure.write_text('import {transferHttp} from "./instance-lifecycle/transfer_native_fixture.ts";\n'+failure.read_text().replace('nativeFailure();','nativeFailure(()=>transferHttp({operation_id:7,kind:"instance.transfer",node_id:9,run_id:""}));'))
        target={'route_id' :1,'mode':'targets','binding_only':False,'noop':False,'recover_forward':False,
                'prepared':[],'workloads':[],'staging_certificates':[],'live_certificates':[],
                'certificates':[],'staging_removals':[],'node_ids':[9,17,209],
                'workload_node_ids':[17],'router_node_ids':[],'dns_node_ids':[9],
                'caddy_node_ids':[9,17],'vacated_route_ids':[],'removals':[],'proposal':{}}
        self.state={'operation':{'id':7,'kind':'instance.transfer','node_id':9,'status':'queued','cancel_requested':False,'input':{
            'direct_transfer':{'instance_id':11,'transfer_id':'43a17cb2-f652-48ad-91a4-3ba2d921caae','source_node_id':9,'destination_node_id':17,
              'source':{'source':'/srv/source','user':'orbit','layout':'checkout','repository_identity':'github.com/example/transfer','branch':'main','sqlite_source':None},
              'destination':{'destination':'/srv/destination','root':'/srv','environment':'/srv/destination/.env','user':'orbit'},'cutover':False,'completed':False},
            'target':target,'environment_fields':{'17':'destination_route_environment'}}},'effects':[],'requests':[],'callbacks':[]}
        self.state_file=self.repo/'transfer-fixture.json'
    def sqlite(self):
        pin='a'*64
        self.state['operation']['input']['direct_transfer']['source']['sqlite_source']='/srv/source/data.sqlite'
        self.state['operation']['input']['direct_transfer']['sqlite']={
            'owner_instance_id':11,'source_instance_id':11,'participant_node_ids':[9,17],
            'source':{'variant':'seed','node_id':9,'target_instance_id':11,'base':'/srv/source','source_path':'/srv/source/data.sqlite','source_user':'orbit','transport_user':'orbit','operation':pin,'state_dir':'/var/lib/orbit/app-instance-sqlite-seeds/source-11-target-11-'+pin,'snapshot_path':'/tmp/orbit-sqlite-'+pin+'.sqlite'},
            'target':{'node_id':17,'target_instance_id':11,'base':'/srv/destination','target_user':'orbit','transport_user':'orbit','operation':pin,'state_dir':'/var/lib/orbit/app-instance-sqlite-seeds/target-11-'+pin,'environment':'development','relative_path':'data.sqlite'}}
    def run_transfer(self,success=True):
        self.state_file.write_text(json.dumps(self.state))
        self.cli('workflow','run','operation-instance-transfer','--input','operation_id=7','--input','node_id=9','--input','kind=instance.transfer',success=success)
        return json.loads(self.state_file.read_text())
    def test_native_transfer_dag_validates(self):
        self.cli('workflow','validate','operation-instance-transfer')
    def test_transfer_publishes_after_cutover_then_retires_source(self):
        actual=self.run_transfer()
        names=[row['name'] for row in actual['effects']]
        self.assertEqual(actual['operation']['status'],'succeeded')
        self.assertTrue(actual['cutover'])
        self.assertLess(names.index('pause-runtime'),names.index('capture'))
        self.assertLess(names.index('materialize'),names.index('prepare'))
        self.assertLess(names.index('publishCaddy'),names.index('install-runtime'))
        self.assertLess(names.index('verify-runtime'),names.index('retire-runtime'))
        self.assertLess(names.index('publishCaddy'),names.index('remove-source'))
        self.assertTrue(all(row.get('run_id') for row in actual['callbacks']))
        self.assertEqual(actual['operation']['node_id'],9)
    def test_destination_refusal_leaves_source_and_rolls_back_reservation(self):
        self.state['fail_effect']='destination-ready'
        actual=self.run_transfer(success=False)
        self.assertTrue(actual['rolled_back'])
        self.assertFalse(actual.get('cutover',False))
        self.assertNotIn('capture',[row['name'] for row in actual['effects']])
        self.assertEqual(actual['operation']['status'],'failed')
    def test_cancel_before_cutover_discards_destination_and_preserves_source(self):
        self.state['cancel_at']='materialize'
        actual=self.run_transfer(success=False)
        self.assertTrue(actual['rolled_back'])
        self.assertEqual(actual['operation']['status'],'cancelled')
        self.assertNotIn('remove-source',[row['name'] for row in actual['effects']])
        names=[row['name'] for row in actual['effects']]
        self.assertIn('discard-destination',names)
        self.assertLess(names.index('restore-runtime'),names.index('discard-destination'))
    def test_failure_after_cutover_never_rolls_back_or_discards_destination(self):
        self.state['fail_effect']='publishCaddy'
        actual=self.run_transfer(success=False)
        self.assertTrue(actual['cutover'])
        self.assertFalse(actual.get('rolled_back',False))
        self.assertNotIn('discard-destination',[row['name'] for row in actual['effects']])
        self.assertNotIn('remove-source',[row['name'] for row in actual['effects']])
        self.assertNotIn('restore-runtime',[row['name'] for row in actual['effects']])
        self.assertEqual(actual['operation']['status'],'failed')
    def test_partial_source_pause_restores_before_destination_cleanup(self):
        self.state['fail_effect']='pause-runtime'
        actual=self.run_transfer(success=False)
        names=[row['name'] for row in actual['effects']]
        self.assertIn('restore-runtime',names)
        self.assertNotIn('capture',names)
        self.assertFalse(actual.get('cutover',False))
    def test_sqlite_parent_snapshots_after_pause_and_cleans_before_terminal(self):
        self.sqlite()
        actual=self.run_transfer()
        names=[row['name'] for row in actual['effects']]
        self.assertLess(names.index('pause-runtime'),names.index('sqlite-export'))
        self.assertLess(names.index('sqlite-export'),names.index('capture'))
        self.assertLess(names.index('materialize'),names.index('sqlite-install'))
        self.assertLess(names.index('sqlite-environment'),names.index('prepare'))
        self.assertIn('sqlite-cleanup-source',names)
        self.assertIn('sqlite-cleanup-target',names)
        self.assertIn('sqlite-cleanup-source',actual['terminal_effects'])
        self.assertIn('sqlite-cleanup-target',actual['terminal_effects'])
        self.assertEqual(actual['operation']['status'],'succeeded')
    def test_cancel_during_sqlite_receive_cleans_both_and_never_cuts_over(self):
        self.sqlite();self.state['cancel_at']='sqlite-receive'
        actual=self.run_transfer(success=False)
        names=[row['name'] for row in actual['effects']]
        self.assertNotIn('sqlite-install',names)
        self.assertLess(names.index('sqlite-cleanup-target'),names.index('discard-destination'))
        self.assertIn('sqlite-cleanup-source',names)
        self.assertIn('sqlite-cleanup-target',names)
        self.assertFalse(actual.get('cutover',False))
        self.assertEqual(actual['operation']['status'],'cancelled')
    def test_sqlite_export_failure_still_cleans_both_participants(self):
        self.sqlite();self.state['fail_effect']='sqlite-export'
        actual=self.run_transfer(success=False)
        names=[row['name'] for row in actual['effects']]
        self.assertIn('sqlite-cleanup-source',names)
        self.assertIn('sqlite-cleanup-target',names)
        self.assertNotIn('materialize',names)
        self.assertEqual(actual['operation']['status'],'failed')
    def test_sqlite_cleanup_failure_does_not_suppress_other_cleanup_or_report_success(self):
        self.sqlite();self.state['fail_effect']='sqlite-cleanup-source'
        actual=self.run_transfer(success=False)
        names=[row['name'] for row in actual['effects']]
        self.assertIn('sqlite-cleanup-target',names)
        self.assertEqual(actual['operation']['status'],'failed')
        self.assertFalse(actual['operation']['result'].get('recovered',False))
if __name__=='__main__': unittest.main()
