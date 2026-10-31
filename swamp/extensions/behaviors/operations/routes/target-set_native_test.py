"""Run the real target mutation DAG and rollback on the shared native harness."""
import json
from pathlib import Path
import sys
import unittest
import uuid
import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class TargetSetNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib',
                            'operations/routes', 'operations/certificates', 'operations/php')
        route = self.repo / 'extensions/behaviors/operations/routes'
        module = route / 'target-set.ts'
        module.write_text('import {fixtureHttp,fixtureWorkloads,fixtureLan,fixtureVerify,event} from "./target-set_native_fixture.ts";\n' + module.read_text().replace(
            'export const behavior = { ...targetSet(), type: "@nckrtl/route-target-set" };',
            '''export const behavior = {...targetSet((_ctx,args)=>fixtureHttp(args),fixtureWorkloads,fixtureLan,fixtureVerify),type:"@nckrtl/route-target-set"};
            behavior.methods.cleanup.execute = async (args,ctx) => { await event(args.rollback ? "rollback-cleanup" : "cleanup",args.target_node_id); return {ready:true}; };
            behavior.methods.firewall.execute = async (args,ctx) => { await event("firewall",args.target_node_id); return {ready:true}; };'''))
        (route / 'publication.ts').write_text('import {publicationFixture} from "./target-set_native_fixture.ts"; export const behavior={...publicationFixture,type:"@nckrtl/route-publication"};')
        cert = self.repo / 'extensions/behaviors/operations/certificates/mod.ts'
        cert.write_text('import {certificateFixture} from "../routes/target-set_native_fixture.ts"; export const behavior={...certificateFixture,type:"@nckrtl/certificates"};')
        failure = self.repo / 'extensions/behaviors/operations/failure.ts'
        failure.write_text('import {fixtureHttp} from "./routes/target-set_native_fixture.ts";\n' + (ROOT / 'extensions/behaviors/operations/failure.ts').read_text().replace(
            'export const behavior = nativeFailure();', 'export const behavior = nativeFailure(() => fixtureHttp({node_id:9}));'))
        source = yaml.safe_load((ROOT / 'workflows/workflow-operation-route-target-set.yaml').read_text())
        signer_steps = [s for j in source['jobs'] for s in j['steps'] if s['task']['methodName'] == 'signBatch']
        self.assertEqual(len(signer_steps), 2)
        for step in signer_steps:
            self.assertEqual(step['target'], 'orbit-node-209')
            self.assertEqual(step['labels'], {'node': '209'})
            self.assertEqual(step['task']['behaviorName'], 'orbit-certificate-signing')
            self.assertIn('leaves', step['task']['inputs'])
        for job in source['jobs']:
            for step in job['steps']:
                step.pop('target', None)
                step.pop('labels', None)
        (self.repo / 'workflows/workflow-operation-route-target-set.yaml').write_text(yaml.safe_dump(source, sort_keys=False))
        def leaf(node, instance, staging):
            return dict(node_id=node, scope=f'app-instance-{instance}' + ('-hostname-change' if staging else ''),
                        home='/home/orbit',agent_sha256='a'*64,domain='native.test',version='b'*16,
                        root_hash='c'*64,user='orbit')
        members = [dict(instance_id=8,node_id=2,route_id=7,production=True,certificate_scope='app-instance-8'),
                   dict(instance_id=9,node_id=3,route_id=7,production=True,certificate_scope='app-instance-9')]
        target = dict(route_id=7,mode='targets',binding_only=False,noop=False,recover_forward=False,
                      prepared=members,workloads=members,staging_certificates=[leaf(2,8,True),leaf(3,9,True)],
                      live_certificates=[leaf(2,8,False),leaf(3,9,False)],certificates=[],staging_removals=[],
                      node_ids=[2,3,9,209],workload_node_ids=[2,3],router_node_ids=[9],dns_node_ids=[1],
                      caddy_node_ids=[2,3,9],vacated_route_ids=[],removals=[],proposal={})
        self.state = dict(events=[],associated=False,operation=dict(id=17,kind='route.target_set',node_id=9,
                          status='queued',cancel_requested=False,input=dict(target=target)))
        private = 'target-native-' + str(uuid.uuid4())
        self.native_env['ORBIT_TARGET_TEST_PRIVATE'] = private
        self.secrets = (private,)

    def run_workflow(self, success=True):
        (self.repo / 'native-target.json').write_text(json.dumps(self.state))
        self.cli('workflow','validate','operation-route-target-set')
        self.cli('workflow','run','operation-route-target-set','--input','operation_id=17',
                 '--input','node_id=9','--input','kind=route.target_set',success=success)
        return json.loads((self.repo / 'native-target.json').read_text())

    def test_target_cutover_signs_on_control_and_commits_only_after_readiness(self):
        state = self.run_workflow()
        events = [v['name'] for v in state['events']]
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertTrue(state['associated'])
        self.assertLess(events.index('verify'),events.index('database-committed'))
        self.assertLess(events.index('database-committed'),events.index('environment'))
        self.assertLess(events.index('router-published'),events.index('cleanup'))
        self.assertEqual([v['node'] for v in state['events'] if v['name']=='sign'], [209,209])
        self.assertNotIn('rollback-associations',events)

    def test_failure_before_commit_withdraws_caddy_before_lan_and_staging_cleanup(self):
        self.state['fail'] = 'verify'
        state = self.run_workflow(success=False)
        events = [v['name'] for v in state['events']]
        self.assertFalse(state['associated'])
        self.assertIn('rollback-preparations',events)
        rollback = events[events.index('rollback-preparations'):]
        self.assertLess(rollback.index('caddy'),rollback.index('rollback-cleanup'))
        self.assertEqual(state['operation']['status'],'failed')

    def test_failure_after_commit_restores_original_associations_before_publication(self):
        self.state.update(fail='caddy',failAfterCommit=True)
        state = self.run_workflow(success=False)
        events = [v['name'] for v in state['events']]
        self.assertFalse(state['associated'])
        rollback = events[events.index('rollback-associations'):]
        self.assertLess(rollback.index('environment'),rollback.index('caddy'))
        self.assertLess(rollback.index('caddy'),rollback.index('rollback-cleanup'))
        self.assertEqual(state['operation']['status'],'failed')

    def test_cancelled_admission_performs_no_effect(self):
        self.state['operation']['cancel_requested'] = True
        state = self.run_workflow()
        self.assertFalse(state['associated'])
        self.assertFalse(any(v['name'] in ('request','runtime','caddy') for v in state['events']))
        self.assertEqual(state['operation']['status'],'cancelled')

    def test_binding_only_changes_no_serving_state(self):
        self.state['operation']['input']['target']['binding_only'] = True
        state = self.run_workflow()
        self.assertFalse(state['associated'])
        self.assertFalse(any(v['name'] in ('request','caddy','dns','cleanup') for v in state['events']))
        self.assertEqual(state['operation']['status'],'succeeded')


if __name__ == '__main__':
    unittest.main()
