"""Run the real Route create, converge and update DAGs with local effect boundaries."""
import json
from pathlib import Path
import shutil
import sys
import unittest
import uuid
import copy
import yaml
ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

class ConvergenceNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/routes', 'operations/certificates', 'operations/php', 'operations/analytics', 'operations/firewall', 'observation', 'operations/tools')
        folder = self.repo / 'extensions/behaviors/operations/routes'
        module = folder / 'convergence.ts'
        module.write_text('import {fixtureHttp,fixtureWorkload,fixtureNetwork,fixtureAnalytics,fixtureGrace,fixtureFirewall} from "./convergence_native_fixture.ts";\n'+module.read_text().replace('export const behavior = convergence();','export const behavior = convergence((_ctx,args)=>(method,path,body)=>fixtureHttp(method,path,body,args), fixtureWorkload(),fixtureNetwork,fixtureAnalytics,fixtureGrace,fixtureFirewall);'))
        # The fixture keeps native dependency, guard, fan-out and output semantics.
        # Only worker transport is local in this disposable repository.
        (folder / 'publication.ts').write_text('import {publisher} from "./convergence_native_fixture.ts"; export const behavior=publisher();')
        certificate_module = self.repo / 'extensions/behaviors/operations/certificates/mod.ts'
        certificate_module.write_text('import {fixtureHttp,fixtureInvoke} from "../routes/convergence_native_fixture.ts";\n'+certificate_module.read_text().replace('...certificates()', '...certificates(certificateActions(fixtureInvoke))'))
        failure=self.repo/'extensions/behaviors/operations/failure.ts'
        failure.write_text('import {fixtureHttp} from "./routes/convergence_native_fixture.ts";\n'+(ROOT/'extensions/behaviors/operations/failure.ts').read_text().replace('export const behavior = nativeFailure();','export const behavior = nativeFailure(() => fixtureHttp);'))
        for verb in ['create','converge','update']:
            source=yaml.safe_load((ROOT / f'workflows/workflow-operation-route-{verb}.yaml').read_text())
            for job in source['jobs']:
                for step in job['steps']:
                    step.pop('target',None);step.pop('labels',None)
            (self.repo / f'workflows/workflow-operation-route-{verb}.yaml').write_text(yaml.safe_dump(source,sort_keys=False))
        intent=dict(route_id=4,status='pending',mode='converge',domain='native.test',node_ids=[9],workloads=[dict(instance_id=21,node_id=9,production=False,certificate_scope='app-instance-21')],certificates=[],dns_node_ids=[9],router_node_id=None,ingress_node_id=None,production=False,public=False,web_root=None,workload_node_ids=[9],workload_caddy_node_ids=[9],public_node_ids=[],caddy_node_ids=[9])
        self.intent=intent
        self.state=dict(events=[],operation=dict(id=17,kind='route.converge',node_id=9,status='queued',cancel_requested=False,input=dict(route=intent,publication=dict(node_id=9,agent_sha256='a'*64),workloads=[dict(instance_id=21,node_id=9,production=False,certificate_scope='app-instance-21')],probes=[],firewall=None)),private={})
    def run_flow(self,verb='converge',success=True):
        self.state['operation']['kind']='route.'+verb
        (self.repo/'native-convergence.json').write_text(json.dumps(self.state))
        self.cli('workflow','validate','operation-route-'+verb)
        self.cli('workflow','run','operation-route-'+verb,'--input','operation_id=17','--input','node_id=9','--input','kind=route.'+verb,success=success)
        return json.loads((self.repo/'native-convergence.json').read_text())
    def test_development_convergence_publishes_certificate_before_php_caddy_and_dns(self):
        state=self.run_flow()
        self.assertEqual(state['events'],['sites-published','publishPhp','publishCaddy','publishDns','finished:succeeded'])
    def test_create_has_its_own_parent_workflow(self):
        state=self.run_flow('create')
        self.assertEqual(state['operation']['status'],'succeeded')
    def test_failed_caddy_stops_dns_and_projects_native_failure(self):
        self.state['fail']='publishCaddy'
        state=self.run_flow(success=False)
        self.assertNotIn('publishDns',state['events'][:state['events'].index('rollback-initial')])
        self.assertEqual(state['operation']['status'],'failed')
    def production_web_root(self):
        self.intent.update(production=True,web_root='docs/public')
        self.intent['workloads'][0]['production']=True
        self.state['operation']['input']['environment_fields']={'9':'environment_sync_node_0'}
        self.state['operation']['input']['workloads']=[dict(instance_id=21,node_id=9,production=True,certificate_scope='route-4',packages={},profile={},runtime={},served_web_roots={})]
        self.state['private']={'environment_sync_node_0':[dict(instance_id=21,name='instance.write-directory-url',input=dict(content='__secret__'))]}
        self.native_env['ROUTE_NATIVE_SECRET']='private-native-'+str(uuid.uuid4())
        self.secrets=(self.native_env['ROUTE_NATIVE_SECRET'],)
    def test_production_web_root_prepares_urls_before_runtime_and_serving(self):
        self.production_web_root()
        state=self.run_flow()
        events=state['events']
        self.assertLess(events.index('served-web-roots'),events.index('environment-written'))
        self.assertLess(events.index('environment-written'),events.index('runtime-published'))
        self.assertLess(events.index('runtime-published'),events.index('publishCaddy'))
        self.assertEqual(state['operation']['status'],'succeeded')
    def test_initial_web_root_failure_withdraws_dns_and_caddy_before_runtime_cleanup(self):
        self.production_web_root();self.state['fail_once']='publishCaddy'
        state=self.run_flow(success=False)
        rollback=state['events'][state['events'].index('rollback-initial'):]
        self.assertLess(rollback.index('publishDns'),rollback.index('publishCaddy'))
        self.assertLess(rollback.index('publishCaddy'),rollback.index('runtime-published'))
        self.assertEqual(state['operation']['status'],'failed')
    def update_intent(self,mode):
        self.intent['mode']=mode
        self.intent['status']='active'
        self.state['operation']['input']['update']=dict(mode=mode,route_id=3 if mode=='replace_domain' else 4,result_route_id=4,previous=dict(self.intent),desired=self.intent,cleanup=dict(self.intent),cleanup_removals=[],node_ids=[9],pending=False,changed=True)
        self.state['operation']['input']['environment_fields']={'9':'environment_sync_node_0'}
        self.state['private']={'environment_sync_node_0':[dict(instance_id=21,name='instance.write-environment',input=dict(content='__secret__'))]}
        self.native_env['ROUTE_NATIVE_SECRET']='private-native-'+str(uuid.uuid4())
        self.secrets=(self.native_env['ROUTE_NATIVE_SECRET'],)
    def test_domain_change_synchronizes_environment_and_dns_before_cutover_and_cleanup(self):
        self.update_intent('replace_domain')
        state=self.run_flow('update');events=state['events']
        self.assertLess(events.index('environment-written'),events.index('publishDns'))
        self.assertLess(events.index('publishDns'),events.index('database-cutover'))
        self.assertLess(events.index('database-cutover'),events.index('cleanup'))
        self.assertEqual(state['operation']['status'],'succeeded')
    def test_web_root_update_records_root_and_restores_it_after_a_refusal(self):
        self.update_intent('web_root');self.intent['web_root']='docs/public'
        self.state['fail_once']='publishCaddy'
        state=self.run_flow('update',success=False);events=state['events']
        self.assertLess(events.index('web-root-stored'),events.index('sites-published'))
        self.assertIn('rollback-web-root-stored',events)
        self.assertEqual(state['operation']['status'],'failed')
    def test_pending_domain_replacement_changes_only_records(self):
        self.intent['mode']='replace_pending'
        self.state['operation']['input']['update']=dict(mode='replace_pending',route_id=3,result_route_id=4,previous=self.intent,desired=self.intent,node_ids=[9],pending=True,changed=True)
        state=self.run_flow('update')
        self.assertEqual(state['events'],['finished:succeeded'])

    def analytics_update(self, moved=True):
        self.update_intent('publication')
        self.intent.update(public=True,ingress_node_id=14,public_node_ids=[14],node_ids=[1,9,13,14,17,209])
        update=self.state['operation']['input']['update']
        update['previous']=copy.deepcopy(self.intent)
        update['previous'].update(public=False,ingress_node_id=None,public_node_ids=[])
        def leaf(node):
            return dict(node_id=node,route_id=77,user='orbit',agent_sha256='a'*64,domain='tracking.native.test',scope='route-77-router',version='b'*16,root_hash='c'*64)
        def plan(node, cleanup, public):
            return dict(route_id=77,certificates=[leaf(node)],cleanup=cleanup,caddy_node_ids=[13,14,17],dns_node_ids=[1],router_node_ids=[node],ingress_node_ids=[14],public_verifications=[dict(node_id=14,verification=dict(protocol='tls',agent_sha256='a'*64,probe=dict(address='10.0.0.'+str(node),port=443,domain='tracking.native.test')))] if public else [])
        after_node=17 if moved else 13
        publish=plan(after_node,[dict(leaf(13),in_use=False)] if moved else [],True)
        rollback=plan(13,[dict(leaf(after_node),in_use=False)] if moved else [],False)
        change=dict(route_id=77,before=dict(node_id=13,publication='private',transition_node_id=None),after=dict(node_id=after_node,publication='public',transition_node_id=13 if moved else None),publish=publish,rollback=rollback,node_ids=[1,13,14,17])
        update.update(analytics_changes=[change],analytics_caddy_node_ids=[13,14,17],analytics_dns_node_ids=[1],analytics_router_node_ids=[13,17],analytics_ingress_node_ids=[14],node_ids=[1,9,13,14,17,209],certificates=[*publish['certificates'],*rollback['certificates']])
        return change

    def test_tracking_publish_and_tls_precede_activation_and_retirement_waits_for_dns_grace(self):
        self.analytics_update()
        state=self.run_flow('update');events=state['events']
        self.assertLess(events.index('analytics-certificate'),events.index('analytics-certificates-ready'))
        self.assertLess(events.index('analytics-certificates-ready'),events.index('analytics-verified'))
        self.assertLess(events.index('analytics-verified'),events.index('analytics-public-verified'))
        self.assertLess(events.index('dns-grace31'),events.index('analytics-placement-cleanup'))
        self.assertLess(events.index('analytics-placement-cleanup'),events.index('analytics-withdraw'))
        self.assertLess(events.index('analytics-withdraw'),events.index('analytics-complete'))
        self.assertEqual([row['node'] for row in state['placements'] if row['name']=='analytics-request'],[17])
        self.assertEqual([row['node'] for row in state['placements'] if row['name']=='analytics-withdraw'],[13])
        self.assertNotIn('analytics-rollback',events)

    def test_failed_analytics_request_blocks_later_forward_effects_and_restores_owner_side(self):
        self.analytics_update();self.state['fail_once']='analytics-request'
        state=self.run_flow('update',success=False);events=state['events']
        before_rollback=events[:events.index('analytics-rollback')]
        self.assertNotIn('analytics-certificates-ready',before_rollback)
        self.assertNotIn('analytics-public-verified',before_rollback)
        self.assertNotIn('public-activated',before_rollback)
        self.assertTrue(state['analytics_rollback'])
        self.assertEqual(state['operation']['status'],'failed')
        self.assertEqual([row['node'] for row in state['placements'] if row['name']=='analytics-withdraw'],[17])

    def test_cancellation_between_tracking_tls_and_activation_restores_both_domains(self):
        self.analytics_update();self.state['cancel_after']='analytics-verified'
        state=self.run_flow('update',success=False);events=state['events']
        self.assertNotIn('analytics-public-verified',events)
        self.assertNotIn('public-activated',events)
        self.assertIn('rollback-publication-stored',events)
        self.assertIn('analytics-rollback',events)
        self.assertEqual(state['operation']['status'],'cancelled')
        self.assertEqual([row['node'] for row in state['placements'] if row['name']=='analytics-withdraw'],[17])

    def test_cancel_after_primary_activation_keeps_both_desired_sides_for_recovery(self):
        self.analytics_update();self.state['cancel_after']='public-activated'
        state=self.run_flow('update',success=False);events=state['events']
        self.assertIn('analytics-public-verified',events)
        self.assertNotIn('analytics-rollback',events)
        self.assertNotIn('rollback-publication-stored',events)
        self.assertEqual(state['operation']['status'],'cancelled')

    def test_domain_only_update_has_no_tracking_effect(self):
        self.update_intent('replace_domain')
        state=self.run_flow('update')
        self.assertFalse(any(event.startswith('analytics-') or event=='dns-grace31' for event in state['events']))

    def test_public_process_has_no_private_certificate_work_but_checks_http_and_firewall(self):
        self.intent.update(public=True,workloads=[],certificates=[],router_node_id=13,ingress_node_id=14,public_node_ids=[13,14],caddy_node_ids=[9,13,14],node_ids=[9,13,14,19,209],workload_node_ids=[],workload_caddy_node_ids=[9],upstream_firewall_node_ids=[19])
        shape=dict(comment='orbit:firewall:process',action='allow',direction='in',source='10.0.0.14',destination='10.0.0.19',port='8080',protocol='tcp',in_interface=None,out_interface=None,family='v4')
        self.state['upstream_live']=[shape]
        self.state['operation']['input'].update(workloads=[],upstream_firewall=dict(node_id=19,expected=[shape]),probes=[dict(protocol='http',agent_sha256='a'*64,probe=dict(address='10.0.0.19',port=8080,domain='native.test'))])
        state=self.run_flow('create');events=state['events']
        self.assertNotIn('certificate.request-leaf',events)
        self.assertNotIn('certificate.publish-leaf',events)
        self.assertLess(events.index('upstream-firewall-read'),events.index('public-activated'))
        self.assertLess(events.index('route-verified'),events.index('public-activated'))
        self.assertEqual(state['operation']['status'],'succeeded')

    def test_process_firewall_mismatch_prevents_activation(self):
        self.intent.update(public=True,workloads=[],certificates=[],router_node_id=13,ingress_node_id=14,public_node_ids=[13,14],caddy_node_ids=[9,13,14],node_ids=[9,13,14,19],workload_node_ids=[],workload_caddy_node_ids=[9],upstream_firewall_node_ids=[19])
        self.state['operation']['input'].update(workloads=[],upstream_firewall=dict(node_id=19,expected=[]))
        state=self.run_flow('create',success=False)
        self.assertNotIn('public-activated',state['events'])
        self.assertNotIn('upstream-firewall-read',state['events'])
        self.assertEqual(state['operation']['status'],'failed')

if __name__=='__main__':unittest.main()
