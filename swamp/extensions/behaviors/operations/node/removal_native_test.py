"""Native removal DAG, real HTTP and synthetic fixed local effects; no fleet mutation."""
import json
import base64
import hashlib
from pathlib import Path
import shutil
import sys
import threading
import uuid
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

class NodeRemovalNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operations', 'node-worker')
        module = self.repo / 'extensions/behaviors/operations/node/removal_root.ts'
        module.write_text('import {fixtureHttp,fixtureLocal,fixtureRetire,fixtureCertificates} from "./removal_native_fixture.ts";\n' +
                          module.read_text().replace('export const behavior = nodeRemovalRoot();',
                                                   'export const behavior = nodeRemovalRoot(() => fixtureHttp, fixtureLocal, fixtureRetire, undefined, fixtureCertificates as never);'))
        metrics = self.repo / 'extensions/behaviors/operations/node/role_metrics.ts'
        metrics.write_text('import {fixtureHttp,fixtureMetrics,fixtureMetricsRuntime} from "./removal_metrics_native_fixture.ts";\n' + metrics.read_text().replace('export const behavior = roleMetrics();', 'export const behavior = roleMetrics(() => fixtureHttp,fixtureMetrics as never,fixtureMetricsRuntime);'))
        failure = self.repo / 'extensions/behaviors/operations/failure.ts'
        failure.write_text('import {fixtureHttp} from "./node/removal_native_fixture.ts";\n' + failure.read_text().replace('export const behavior = nativeFailure();', 'export const behavior = nativeFailure(() => fixtureHttp);'))
        hub = self.repo / 'extensions/behaviors/operations/node/wireguard_direct.ts'
        hub.write_text('import {fixtureHttp,fixtureHubSystem,fixtureHubSsh} from "./removal_native_fixture.ts";\n' + hub.read_text().replace(
            'export const behavior = nodeWireGuard();',
            'export const behavior = nodeWireGuard(undefined, () => fixtureHttp, fixtureHubSystem, fixtureHubSsh);'))
        self.workflow = json.loads((ROOT / 'workflows/workflow-operation-node-remove.yaml').read_text())
        def unplace(value):
            if isinstance(value, dict):
                return {key: unplace(item) for key, item in value.items() if key not in ('target', 'labels')}
            if isinstance(value, list):
                return [unplace(item) for item in value]
            return value
        (self.repo / 'workflows/workflow-operation-node-remove.yaml').write_text(json.dumps(unplace(self.workflow)))
        self.snapshot = {'schema_version': 1, 'node_id': 212, 'name': 'discarded', 'prior_status': 'active',
                         'task_vm_id': 184, 'machine_deleted': True, 'offline': True, 'hub_id': None,
                         'roles': [], 'participants': [{'node_id': 13, 'peer': True, 'routes': False, 'residue': True, 'dns': False}],
                         'metrics': None, 'route_ids': []}
        self.operation = {'id': 7, 'kind': 'node.remove', 'node_id': 14, 'status': 'queued', 'cancel_requested': False,
                          'input': {'proxy_node_ids': [], 'snapshot': self.snapshot, 'placements': [{'effect_node_id': 13, 'execution_node_id': 13, 'transport': 'local'}], 'hub': None, 'retirement': None, 'host_retained': False, 'routes': {'routes': [], 'nodes': [], 'dns_node_ids': []}, 'metrics_fleet': None}}
        self.route_workload = None
        self.effects = []
        self.results = []
        self.mode = 'success'
        self.private = '/synthetic-private/' + uuid.uuid4().hex
        self.hub_private = base64.b64encode(uuid.uuid4().bytes + uuid.uuid4().bytes).decode()
        self.secrets = (self.private, self.hub_private)
        self.native_env['ORBIT_REMOVAL_PRIVATE_FIXTURE'] = self.private
        owner = self
        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_): pass
            def respond(self, value):
                encoded = json.dumps(value).encode()
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Content-Length', str(len(encoded)))
                self.end_headers()
                self.wfile.write(encoded)
            def do_GET(self):
                if '/private-material?' in self.path:
                    hub = owner.operation['input'].get('restore_hub' if owner.operation.get('result', {}).get('restoring') else 'hub')
                    configuration = None if hub is None else {'snapshot': hub, 'address': '10.44.0.1/24', 'port': 51820,
                        'private_key': owner.hub_private, 'peers': [], 'connection': {'target': {'node_id': 1,
                        'host': 'vpn.example.test', 'port': 22, 'user': 'orbit', 'fingerprint': 'SHA256:' + 'A' * 43},
                        'identity': '/synthetic/existing-gateway-key'}}
                    return self.respond({'data': {'configuration': configuration, 'bootstrap_identity': owner.private, 'metrics_runtime': {'runtime': {}, 'verify': {}}, 'removal_environment_0': [{'instance_id': 51, 'name': 'instance.write-directory-url', 'input': {'private': owner.private}}]}})
                if self.path.startswith('/_retire?node='):
                    owner.effects.append('retire:' + self.path.split('=')[-1])
                    return self.respond({'ready': True})
                if self.path in ('/_runtime', '/_url'):
                    owner.effects.append(self.path[2:])
                    return self.respond({'ready': True})
                if self.path.startswith('/_metrics?') or self.path.startswith('/_cert?'):
                    owner.effects.append(self.path[2:])
                    return self.respond({})
                if self.path == '/_agent':
                    owner.effects.append('wireguard.repair-peer-dns')
                    return self.respond({'error_code': 'wireguard.peer_dns_busy'} if owner.mode == 'refusal' or (owner.mode == 'fail_once' and owner.effects.count('wireguard.repair-peer-dns') == 1) else {})
                if self.path == '/_residue':
                    owner.effects.append('residue')
                    return self.respond({'changed': False})
                data = dict(owner.operation)
                if owner.operation.get('result',{}).get('restoring'):
                    data['input'] = dict(data['input'],metrics_fleet=data['input'].get('restore_metrics_fleet'))
                if self.path == '/_participant':
                    data['input'] = {'schema_version': 1, 'removal_node_id': 212, 'node_id': 13,
                                     'agent_sha256': 'a' * 64, 'network': {'schema_version': 1, 'node_id': 13,
                                     'agent_sha256': 'a' * 64, 'router_lan': None, 'peer_dns': {'dns_server': '10.44.0.1',
                                     'public_key': 'A' * 43 + '=', 'domains': ['orbit']}}, 'residue': {'rows': []}, 'dns': None}
                if self.path == '/_participant' and owner.route_workload is not None:
                    data['input']['route_removal'] = owner.route_workload
                self.respond({'data': data})
            def do_POST(self):
                data = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                if self.path == '/_hub':
                    owner.effects.append('hub:' + ('restore' if owner.operation.get('result', {}).get('restoring') else 'withdraw'))
                    owner.assertEqual(data['target']['node_id'], 1)
                    owner.assertEqual(data['input']['name'], 'wireguard.project-hub')
                    return self.respond({})
                owner.results.append(data)
                if self.path.endswith('/failure'):
                    data = dict(data,status='failed',result={})
                owner.operation['status'] = data['status']
                owner.operation['result'] = data.get('result',{})
                if owner.mode == 'cancel_after_begin' and owner.operation['result'].get('removing'):
                    owner.operation['cancel_requested'] = True
                self.respond({'data': owner.operation})
        server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        self.native_env['ORBIT_REMOVAL_HTTP_FIXTURE'] = f'http://127.0.0.1:{server.server_port}'

    def run_native(self, success=True):
        self.cli('workflow', 'validate', 'operation-node-remove')
        return self.cli('workflow', 'run', 'operation-node-remove', '--input', 'operation_id=7',
                        '--input', 'node_id=14', '--input', 'kind=node.remove', success=success)

    def test_native_cleanup_finishes_after_exact_participant_actions_without_deleted_guest_call(self):
        self.run_native()
        self.assertEqual(self.effects, ['wireguard.repair-peer-dns', 'residue'])
        self.assertEqual(self.operation['status'], 'succeeded')
        self.assertTrue(self.results[-1]['result']['removed'])
        self.assertEqual(self.results[-1]['result']['node_id'], 212)

    def test_native_refusal_preserves_the_node_and_does_not_run_later_cleanup(self):
        self.mode = 'refusal'
        self.run_native(success=False)
        self.assertEqual(self.effects, ['wireguard.repair-peer-dns', 'wireguard.repair-peer-dns'])
        self.assertEqual(self.operation['status'], 'failed')
        self.assertFalse(self.results[-1]['result']['removed'])
        self.assertFalse(self.results[-1]['result']['restored'])

    def test_online_root_reads_callback_private_identity_and_retires_off_worker_before_deletion(self):
        self.snapshot.update(task_vm_id=None, machine_deleted=False, offline=False)
        self.operation['input']['retirement'] = {'executor_node_id': 14, 'target': {
            'node_id': 212, 'host': '203.0.113.12', 'port': 22, 'user': 'managed',
            'fingerprint': 'SHA256:' + 'A' * 43}}
        self.run_native()
        self.assertEqual(self.effects, ['wireguard.repair-peer-dns', 'residue', 'retire:212'])
        self.assertEqual(self.operation['status'], 'succeeded')
        self.assertNotIn(self.private, json.dumps(self.results))


    def prepare_route_workload(self):
        content = 'owned caddy content\n'
        digest = hashlib.sha256(content.encode()).hexdigest()
        member = {'node_id': 13, 'user': 'managed', 'home': '/srv/managed', 'agent_sha256': 'a' * 64,
                  'certificate_scopes': [], 'lan_route_ids': [], 'shared_php': False, 'production_instance_ids': [51],
                  'url_instance_ids': [51], 'ingress_firewall': False}
        self.snapshot['participants'][0]['routes'] = True
        self.snapshot['route_ids'] = [3]
        self.operation['input']['routes'] = {'routes': [{'route_id': 3, 'domain': 'removed.orbit', 'replacement_of': None}], 'nodes': [member], 'dns_node_ids': []}
        self.route_workload = {'member': member, 'workloads': [{'instance_id': 51, 'node_id': 13, 'production': True,
            'runtime': {}, 'profile': {}, 'packages': {}}], 'environment_field': 'removal_environment_0',
            'publication': {'caddy_publication': {'node_id': 13, 'user': 'managed', 'agent_sha256': 'a' * 64,
                'caddy': {'content': content, 'version': digest[:32], 'listen_addresses': [], 'unserved': False}},
                'php_publication': {'node_id': 13, 'configurations': {}}}, 'ingress_firewall': None}
    def test_dedicated_route_workload_consumes_real_private_http_material_without_native_resource_leak(self):
        self.prepare_route_workload()
        self.run_native()
        self.assertEqual(self.effects, ['wireguard.repair-peer-dns', 'runtime', 'url', 'residue'])
        self.assertEqual(self.operation['status'], 'succeeded')
        self.assertNotIn(self.private, json.dumps(self.results))

    def test_private_route_callback_error_is_bounded_and_retains_the_node_without_later_effects(self):
        self.prepare_route_workload()
        self.native_env['ORBIT_REMOVAL_PRIVATE_FAILURE'] = '1'
        self.run_native(success=False)
        self.assertEqual(self.effects, ['wireguard.repair-peer-dns', 'runtime', 'wireguard.repair-peer-dns', 'runtime'])
        self.assertEqual(self.operation['status'], 'failed')
        self.assertFalse(self.results[-1]['result']['removed'])
        self.assertNotIn(self.private, json.dumps(self.results))

    def test_confirmed_failure_restores_membership_and_serving_state_in_the_same_run(self):
        self.mode = 'fail_once'
        self.run_native(success=False)
        self.assertEqual([r['result'] for r in self.results if r['status']=='running'], [{'removing': True}, {'restoring': True}])
        self.assertTrue(self.results[-1]['result']['restored'])
        self.assertFalse(self.results[-1]['result']['removed'])
        self.assertEqual(self.effects, ['wireguard.repair-peer-dns', 'wireguard.repair-peer-dns'])

    def prepare_metrics(self):
        fleet = [{'node_id': i,'address': f'10.44.0.{i}','agent_sha256': 'a'*64,'worker': True,'selected': False,'caddy_managed': False,'fpm': False,'service': {'config': None},'pools': []} for i in [13,14]]
        plan = {'metrics_node_id': 13,'metrics_address': '10.44.0.13','fleet': fleet}
        self.snapshot['metrics'] = {'node_id':13,'gateway_id':14}
        self.operation['input']['metrics_fleet'] = plan
        self.operation['input']['restore_metrics_fleet'] = dict(plan,fleet=[dict(n,selected=True) for n in fleet])

    def test_metrics_commit_precedes_network_withdrawal_and_retirement(self):
        self.prepare_metrics()
        self.run_native()
        self.assertLess(self.effects.index('metrics?phase=runtime'), self.effects.index('wireguard.repair-peer-dns'))
        self.assertTrue(self.results[-1]['result']['removed'])

    def test_restoration_reissues_required_certificates_before_restored_route_publication(self):
        self.mode = 'fail_once'
        self.operation['input']['restore_certificates'] = [{'node_id':13,'scope':'route-3','home':'/srv/managed','user':'managed','agent_sha256':'a'*64,'domain':'restored.orbit','version':'a'*16,'root_hash':'a'*64}]
        self.run_native(success=False)
        self.assertTrue(self.results[-1]['result']['restored'])
        self.assertLess(self.effects.index('cert?phase=publish'), len(self.effects)-1)
        self.assertEqual(self.effects[-1], 'wireguard.repair-peer-dns')

    def test_unsupported_route_contribution_refuses_before_any_effect(self):
        self.snapshot['route_ids'] = [3]
        self.run_native()
        self.assertEqual(self.effects, [])
        self.assertEqual(self.operation['status'], 'failed')
        self.assertEqual(self.results[-1]['result']['error_code'], 'node.removal_unavailable')

    def test_cancelled_parent_restores_hub_after_membership_checkpoint_in_the_same_run(self):
        self.mode = 'cancel_after_begin'
        self.snapshot.update(hub_id=1, participants=[])
        hub = {'schema_version': 1, 'executor_node_id': 14, 'hub_id': 1,
               'context_node_id': 212, 'excluded_node_id': 212, 'agent_sha256': 'a' * 64}
        self.operation['input'].update(hub=hub, restore_hub=dict(hub, excluded_node_id=None),
            placements=[{'effect_node_id': 1, 'execution_node_id': 1, 'transport': 'local'}])
        self.run_native(success=False)
        self.assertEqual(self.effects, ['hub:restore'])
        self.assertEqual([r['result'] for r in self.results if r['status'] == 'running'],
                         [{'removing': True}, {'restoring': True}])
        self.assertTrue(self.operation['cancel_requested'])
        self.assertTrue(self.results[-1]['result']['restored'])
        self.assertFalse(self.results[-1]['result']['removed'])

if __name__ == '__main__': unittest.main()
