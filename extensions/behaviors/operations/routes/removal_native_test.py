"""The real native removal DAG orders full publication before destructive cleanup."""
import json
from pathlib import Path
import shutil
import sys
import unittest
import yaml
ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

class RemovalNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib')
        route = self.repo / 'extensions/behaviors/operations/routes'
        route.mkdir(parents=True)
        for name in ['removal.ts', 'removal-local.ts', 'lan.ts', 'removal_native_fixture.ts', 'route-workload-local.ts', 'firewall-local.ts', 'firewall-program.ts', 'local-program.ts']:
            shutil.copy(ROOT / 'extensions/behaviors/operations/routes' / name, route)
        cert = self.repo / 'extensions/behaviors/operations/certificates'
        cert.mkdir()
        for name in ['input.ts', 'actions.ts', 'system.ts']:
            shutil.copy(ROOT / 'extensions/behaviors/operations/certificates' / name, cert)
        module = route / 'removal.ts'
        module.write_text('import {fixtureHttp, actions, fixtureReady, event} from "./removal_native_fixture.ts";\n' + module.read_text().replace('export const behavior = removal();', 'export const behavior = removal(() => fixtureHttp, actions); behavior.methods.ready.execute = fixtureReady; behavior.methods.prepareRuntime.execute = () => event("prepareRuntime"); behavior.methods.synchronizeEnvironment.execute = () => event("synchronizeEnvironment");'))
        (route / 'publication.ts').write_text('import { fixtureBehavior } from "./removal_native_fixture.ts"; export const behavior = fixtureBehavior("@nckrtl/route-publication", ["publishDns", "publishCaddy", "publishPhp"]);')
        (route / 'convergence.ts').write_text('import { fixtureBehavior } from "./removal_native_fixture.ts"; export const behavior = fixtureBehavior("@nckrtl/route-convergence", ["prepareRuntime"]);')
        failure = self.repo / 'extensions/behaviors/operations/failure.ts'
        failure.write_text('import {fixtureHttp} from "./routes/removal_native_fixture.ts";\n' + (ROOT / 'extensions/behaviors/operations/failure.ts').read_text().replace('export const behavior = nativeFailure();', 'export const behavior = nativeFailure(() => fixtureHttp);'))
        source = yaml.safe_load((ROOT / 'workflows/workflow-operation-route-removal.yaml').read_text())
        source.pop('target'); source.pop('labels')
        for job in source['jobs']:
            for step in job['steps']:
                step.pop('target', None); step.pop('labels', None)
        (self.repo / 'workflows/workflow-operation-route-removal.yaml').write_text(yaml.safe_dump(source, sort_keys=False))
        node = dict(node_id=18, user='orbit', home='/home/orbit', agent_sha256='a'*64, certificate_scopes=['route-7'], lan_route_ids=[7], shared_php=True, production_instance_ids=[], url_instance_ids=[], ingress_firewall=False)
        self.state = dict(events=[], operation=dict(id=17, kind='route.destroy', node_id=18, status='queued', cancel_requested=False, result=None, input=dict(php_publication=dict(node_id=18,agent_sha256='a'*64),route_removal=dict(routes=[dict(route_id=7,domain='demo.orbit',replacement_of=None)],nodes=[node],dns_node_ids=[1]))))
        self.save()
    def save(self):
        (self.repo / 'native-removal.json').write_text(json.dumps(self.state))
    def run_workflow(self, success=True):
        self.cli('workflow', 'validate', 'operation-route-removal')
        self.cli('workflow', 'run', 'operation-route-removal', '--input', 'operation_id=17', '--input', 'node_id=18', '--input', 'kind=route.destroy', success=success)
        return json.loads((self.repo / 'native-removal.json').read_text())
    def test_dns_caddy_and_php_finish_before_cleanup_and_record(self):
        state = self.run_workflow()
        self.assertEqual(state['events'], ['publishDns', 'publishCaddy', 'publishPhp', 'certificates', 'lan', 'record'])
        self.assertEqual(state['operation']['status'], 'succeeded')
    def test_offline_keeps_absent_node_cleanup_and_records_residue(self):
        self.state['offlineNodes'] = [18]
        self.state['operation']['input']['route_removal']['offline'] = True
        self.save()
        state = self.run_workflow()
        self.assertEqual(state['events'], ['publishDns', 'record'])
    def test_online_refuses_absent_worker_before_any_publication(self):
        self.state['offlineNodes'] = [18]; self.save()
        state = self.run_workflow(success=False)
        self.assertEqual(state['events'], [])
    def test_cancellation_prevents_every_effect(self):
        self.state['operation']['cancel_requested'] = True; self.save()
        state = self.run_workflow(success=False)
        self.assertEqual(state['events'], [])
    def test_production_runtime_and_authoritative_environment_finish_before_certificates(self):
        node=self.state['operation']['input']['route_removal']['nodes'][0]
        node['shared_php']=False;node['production_instance_ids']=[4];node['url_instance_ids']=[4];self.save()
        state=self.run_workflow()
        self.assertEqual(state['events'],['publishDns','publishCaddy','prepareRuntime','synchronizeEnvironment','certificates','lan','record'])
    def test_failed_caddy_keeps_certificates_lan_and_route_record(self):
        self.state['fail'] = 'publishCaddy'; self.save()
        state = self.run_workflow(success=False)
        self.assertEqual(state['events'], ['publishDns', 'publishCaddy'])
        self.assertNotEqual(state['operation']['status'], 'succeeded')

if __name__ == '__main__': unittest.main()
