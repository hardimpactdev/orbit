"""Pinned native Metrics DAG fixture. Host effects are faked; this is not fleet proof."""
import hashlib
import json
from pathlib import Path
import shutil
import sys
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

class MetricsFleetNative(NativeTestCase):
    native_timeout = 90
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operations/_lib', 'operations/routes')
        failure_source = (ROOT / 'extensions/behaviors/operations/failure.ts').read_text()
        (self.repo / 'extensions/behaviors/operations/failure.ts').write_text('import { http } from \"./integrations/metrics_fleet_native_fixture.ts\";\n'.replace('\\\"', '\"') + failure_source.replace('export const behavior = nativeFailure();', 'export const behavior = nativeFailure(() => http);'))
        (self.repo / '.swamp.yaml').write_text((self.repo / '.swamp.yaml').read_text() + '\ntrustedCollectives: [nckrtl]\n')
        target = self.repo / 'extensions/behaviors/operations/integrations'
        target.mkdir()
        shutil.copytree(ROOT / 'extensions/behaviors/operations/php', self.repo / 'extensions/behaviors/operations/php')
        node = self.repo / 'extensions/behaviors/operations/node'
        node.mkdir()
        shutil.copy(ROOT / 'extensions/behaviors/operations/node/system_command.ts', node)
        for name in ['metrics_fleet_direct.ts','metrics_fleet_local.ts','metrics_fleet_native_fixture.ts','metrics_runtime_actions.ts','metrics_system.ts','metrics_exporter_actions.ts','metrics_service_actions.ts']:
            shutil.copy(ROOT / 'extensions/behaviors/operations/integrations' / name, target)
        publisher = self.repo / 'extensions/behaviors/operations/routes/publication.ts'
        publisher.write_text('import { http, invoke } from "../integrations/metrics_fleet_native_fixture.ts";\n' + publisher.read_text().replace('export const behavior = publication();','export const behavior = publication(() => http, invoke);'))
        module = target / 'metrics_fleet_direct.ts'
        module.write_text('import { local, runtime, http } from "./metrics_fleet_native_fixture.ts";\n' + module.read_text().replace('export const behavior = metricsFleetDirect();', 'export const behavior = metricsFleetDirect(() => http, runtime, local);'))
        import yaml
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-metrics-fleet.yaml').read_text())
        for job in workflow['jobs']:

            for step in job['steps']:
                if 'target' in step:
                    expected = 'self.id' if 'forEach' in step and step['task']['type'] != 'assert' else 'inputs.node_id'
                    self.assertEqual(step.pop('target'), '${{ "orbit-node-" + string(' + expected + ') }}')
                    self.assertEqual(step.pop('labels'), {'node':'${{ string(' + expected + ') }}'})
        (self.repo / 'workflows/workflow-operation-metrics-fleet.yaml').write_text(yaml.safe_dump(workflow,sort_keys=False))
        empty = {'config':None,'unit':None,'rules':[],'active':False,'enabled':False,'binary':False}
        plan = {'schema_version':1,'assignment_id':3,'metrics_node_id':9,'metrics_address':'10.44.0.9','fleet':[
            {'node_id':i,'name':'node-'+str(i),'address':'10.44.0.'+str(i),'agent_sha256':'a'*64,'worker':True,'selected':True,'selection_reason':'role_default','caddy':False,'caddy_managed':False,'fpm':False,'hosts':[],'instance_ids':[],'service':empty,'pools':[]} for i in [9,13,14,17,209]],
            'runtime_files':{'/etc/orbit/metrics/prometheus.yml':'public-fixture'}}
        self.state = {'bound':True,'calls':[],'effects':[],'registryReads':0,'operation':{'id':71,'kind':'metrics.reconcile','node_id':9,'status':'queued','cancel_requested':False,'result':None,'input':{'metrics_fleet':plan}}}
        self.native_env['METRICS_FIXTURE_PASSWORD'] = 'fleet-private-whole-scratch-sentinel'
        self.secrets = (self.native_env['METRICS_FIXTURE_PASSWORD'],)
        self.save()
    def save(self):
        plan=self.state['operation']['input']['metrics_fleet']
        plan.pop('digest',None)
        plan['digest']=hashlib.sha256(json.dumps(plan,sort_keys=True,separators=(',',':')).encode()).hexdigest()
        (self.repo / 'native-websocket.json').write_text(json.dumps(self.state))
    def read(self):
        state = json.loads((self.repo / 'native-websocket.json').read_text())
        effects = self.repo / 'metrics-effects.jsonl'
        state['effects'] = [json.loads(line) for line in effects.read_text().splitlines()] if effects.exists() else []
        return state
    def run_workflow(self,success=True):
        self.cli('workflow','validate','operation-metrics-fleet')
        return self.cli('workflow','run','operation-metrics-fleet','--input','operation_id=71','--input','node_id=9','--input','kind=metrics.reconcile',success=success)
    def test_actual_dag_verifies_five_nodes_and_commits_runtime_once(self):
        self.run_workflow()
        state=self.read()
        self.assertEqual(state['operation']['status'],'succeeded')
        self.assertEqual(state['operation']['result']['verified_node_ids'],[9,13,14,17,209])
        self.assertEqual(state['runtimeCalls'],['metrics.credentials','metrics.runtime'])
        self.assertFalse(any(row['phase']=='restore' for row in state['effects']))
        phases=[row['phase'] for row in state['effects']]
        self.assertLess(max(i for i,p in enumerate(phases) if p=='snapshot'),min(i for i,p in enumerate(phases) if p=='apply'))
        self.assertFalse(any('claim' in p or 'children' in p or 'receipt' in p for p in state['calls']))
    def test_cancelled_admission_projects_terminal_without_effects(self):
        self.state['operation']['cancel_requested'] = True
        self.save(); self.run_workflow(success=False)
        state = self.read()
        self.assertEqual(state['operation']['status'], 'cancelled')
        self.assertEqual(state['operation']['result'], {'cancelled': True})
        self.assertEqual(state['effects'], [])
        self.assertNotIn('runtimeCalls', state)
    def test_workerless_nodes_fail_before_any_snapshot_or_host_effect(self):
        plan=self.state['operation']['input']['metrics_fleet']
        for i in [1,18]: plan['fleet'].append(dict(plan['fleet'][0],node_id=i,name='node-'+str(i),address='10.44.0.'+str(i),worker=False))
        plan['fleet'].sort(key=lambda node:node['node_id']); self.save()
        self.run_workflow(success=False)
        state=self.read()
        self.assertEqual(state['operation']['status'],'failed')
        self.assertEqual(state['operation']['result']['error_code'],'metrics.no_worker')
        self.assertEqual(state['operation']['result']['missing_worker_ids'],[1,18])
        self.assertEqual(state['operation']['result']['restored_node_ids'],[])
        self.assertEqual(state['effects'],[])
    def test_apply_failure_restores_original_snapshots_and_projects_failure(self):
        self.state['fail']='apply';self.save();self.run_workflow(success=False)
        state=self.read()
        self.assertEqual(state['operation']['status'],'failed')
        self.assertEqual(state['operation']['result']['restored_node_ids'],[9,13,14,17,209])
    def test_known_runtime_validation_failure_restores_the_complete_prior_fleet(self):
        self.state['fail']='runtime_validation';self.save();self.run_workflow(success=False)
        state=self.read()
        self.assertEqual(state['operation']['result']['error_code'],'metrics.prometheus_configuration_invalid')
        self.assertEqual(state['operation']['result']['unknown_node_ids'],[])
        self.assertEqual(state['operation']['result']['restored_node_ids'],[9,13,14,17,209])
        self.assertFalse(state['operation']['result']['runtime_committed'])
        self.assertEqual(len([row for row in state['effects'] if row['phase']=='restore']),5)
    def test_lost_runtime_is_unknown_and_does_not_restore_after_possible_commit(self):
        self.state['lost']='runtime';self.save();self.run_workflow(success=False)
        state=self.read()
        self.assertEqual(state['operation']['result']['error_code'],'metrics.outcome_unknown')
        self.assertEqual(state['operation']['result']['unknown_node_ids'],[9])
        self.assertFalse(any(row['phase']=='restore' for row in state['effects']))
    def test_lost_apply_is_unknown_and_never_reported_as_restored(self):
        self.state['lost']='apply';self.save();self.run_workflow(success=False)
        state=self.read()
        self.assertEqual(state['operation']['result']['error_code'],'metrics.outcome_unknown')
        self.assertEqual(state['operation']['result']['restored_node_ids'],[])
        self.assertFalse(any(row['phase']=='restore' for row in state['effects']))
    def test_credentials_refusal_restores_before_runtime_without_uncertainty(self):
        self.state['fail']='credentials';self.save();self.run_workflow(success=False)
        state=self.read()
        self.assertEqual(state['runtimeCalls'],['metrics.credentials'])
        self.assertEqual(state['operation']['result']['error_code'],'metrics.credentials_unverified')
        self.assertEqual(state['operation']['result']['unknown_node_ids'],[])
        self.assertEqual(state['operation']['result']['restored_node_ids'],[9,13,14,17,209])
    def test_postcommit_cleanup_failure_preserves_the_new_runtime_and_exporters(self):
        self.state['fail']='cleanup';self.save();self.run_workflow(success=False)
        state=self.read()
        self.assertEqual(state['operation']['result']['error_code'],'metrics.container_cleanup_failed')
        self.assertTrue(state['operation']['result']['runtime_committed'])
        self.assertFalse(any(row['phase']=='restore' for row in state['effects']))
    def prepare_caddy_cleanup(self):
        self.state['operation']['input']['metrics_fleet']['fleet'][0]['caddy_managed']=True
        def publication(content):
            return {'node_id':9,'user':'orbit','agent_sha256':'a'*64,'caddy':{'content':content,'version':hashlib.sha256(content.encode()).hexdigest()[:32],'listen_addresses':['10.44.0.9'],'unserved':False}}
        self.state['operation']['input']['caddy_publication']=publication('unrelated-site\nold-scrape-site\n')
        self.state['freshCaddy']=publication('unrelated-site\n')
        self.save()
    def test_disabled_caddy_monitoring_still_publishes_fresh_cleanup_on_managed_ingress(self):
        self.prepare_caddy_cleanup();self.run_workflow()
        applied=[json.loads(line) for line in (self.repo/'metrics-caddy.jsonl').read_text().splitlines()]
        self.assertEqual([item['content'] for item in applied],['unrelated-site\n'])
        self.assertEqual(self.read()['operation']['status'],'succeeded')
    def test_recovery_restores_local_state_without_republishing_caddy(self):
        self.prepare_caddy_cleanup();self.state['fail']='verify';self.save()
        self.run_workflow(success=False)
        state=self.read()
        self.assertEqual(state['operation']['result']['restored_node_ids'],[9,13,14,17,209])
        applied=[json.loads(line) for line in (self.repo/'metrics-caddy.jsonl').read_text().splitlines()]
        self.assertEqual([item['content'] for item in applied],['unrelated-site\n'])
    def test_actual_local_actions_enable_and_verify_the_owned_fpm_pool(self):
        node=self.state['operation']['input']['metrics_fleet']['fleet'][1]
        pool='pm.status_path = /orbit-fpm-status\npm.status_listen = /run/php/orbit-app-1.sock.status\n'
        node.update(fpm=True,instance_ids=[31],service=dict(node['service'],config='public-pool-config\n',active=True,enabled=True,binary=True),pools=[{'instance_id':31,'inspect':{'user':'orbit-app-1'},'enabled':{'configuration':{'pool':pool}},'disabled':{'configuration':{'pool':''}}}])
        self.save();self.run_workflow()
        host=json.loads((self.repo/'host-10.44.0.13.json').read_text())
        self.assertEqual(host['pool'],pool)
        self.assertIn('# runtime 31: '+'b'*64,host['metrics.service-runtime']['config'])
    def test_unbound_run_starts_no_local_action(self):
        self.state['bound']=False;self.save();self.run_workflow(success=False)
        self.assertEqual(self.read()['effects'],[])

    def test_exporter_enable_uses_one_complete_native_fleet_root(self):
        self.state['operation']['kind']='metrics.exporter_enable'
        self.state['operation']['input']['exporter_node_id']=13
        self.state['operation']['input']['exporter_preference']='enabled'
        self.state['operation']['input']['metrics_fleet']['fleet'][1]['selected']=True
        self.save()
        self.cli('workflow','validate','operation-metrics-fleet')
        self.cli('workflow','run','operation-metrics-fleet','--input','operation_id=71','--input','node_id=9','--input','kind=metrics.exporter_enable')
        state=self.read()
        self.assertEqual(state['operation']['status'],'succeeded')
        self.assertEqual(state['operation']['result']['verified_node_ids'],[9,13,14,17,209])
        self.assertEqual(state['runtimeCalls'],['metrics.credentials','metrics.runtime'])
        self.assertFalse(any('children' in path or 'claim' in path for path in state['calls']))
        host=json.loads((self.repo/'host-10.44.0.13.json').read_text())
        self.assertEqual(host['metrics.node-exporter']['service_active'],True)
        self.assertEqual(host['metrics.cadvisor']['service_active'],True)

    def test_exporter_disable_uses_one_complete_native_fleet_root(self):
        self.state['operation']['kind']='metrics.exporter_disable'
        self.state['operation']['input']['exporter_node_id']=13
        self.state['operation']['input']['exporter_preference']='disabled'
        self.state['operation']['input']['metrics_fleet']['fleet'][1]['selected']=False
        self.save()
        self.cli('workflow','validate','operation-metrics-fleet')
        self.cli('workflow','run','operation-metrics-fleet','--input','operation_id=71','--input','node_id=9','--input','kind=metrics.exporter_disable')
        state=self.read()
        self.assertEqual(state['operation']['status'],'succeeded')
        self.assertEqual(state['operation']['result']['verified_node_ids'],[9,13,14,17,209])
        self.assertEqual(state['runtimeCalls'],['metrics.credentials','metrics.runtime'])
        self.assertFalse(any('children' in path or 'claim' in path for path in state['calls']))
        host=json.loads((self.repo/'host-10.44.0.13.json').read_text())
        self.assertEqual(host['metrics.node-exporter']['service_active'],False)
        self.assertEqual(host['metrics.cadvisor']['service_active'],False)

if __name__ == '__main__': unittest.main()

