"""Real native peer DAG, Gateway HTTP, failure compensation and whole-scratch secret scan."""
import base64
import json
from pathlib import Path
import shutil
import sys
import threading
import unittest
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit
ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class NetworkNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        shared=self.repo/"extensions/behaviors/operations/routes/firewall-command"
        shared.parent.mkdir(parents=True,exist_ok=True)
        shutil.copytree(ROOT/"extensions/behaviors/operations/routes/firewall-command",shared)
        self.secrets = (base64.b64encode(uuid.uuid4().bytes + uuid.uuid4().bytes).decode(),)
        self.copy_behaviors('automatic-deploy', 'operations/_lib')
        self.copy_behaviors('operations/node-bootstrap')
        ops = self.repo / 'extensions/behaviors/operations'
        routes = ops / 'routes'; routes.mkdir()
        for name in ['firewall-local.ts', 'firewall-program.ts', 'local-program.ts', 'router-lan.ts']:
            shutil.copy(ROOT / 'extensions/behaviors/operations/routes' / name, routes / name)
        node = ops / 'node'; node.mkdir()
        for name in ['network_root.ts', 'network_parent.ts', 'network_root_native_fixture.ts', 'network_system.ts', 'network_native_program.ts', 'system_command.ts', 'wireguard_direct.ts', 'wireguard_local.ts']:
            shutil.copy(ROOT / 'extensions/behaviors/operations/node' / name, node / name)
        module = node / 'network_root.ts'
        module.write_text('import {fixtureHttp,fixtureSystem} from "./network_root_native_fixture.ts";\nimport {peerNetworkActions} from "./network_parent.ts";\n' + module.read_text().replace(
            'export const behavior = nodeNetworkRoot();', 'export const behavior = nodeNetworkRoot(peerNetworkActions(fixtureSystem), () => fixtureHttp, fixtureSystem);'))
        module = node / 'wireguard_direct.ts'
        module.write_text('import {fixtureHttp,fixtureSystem} from "./network_root_native_fixture.ts";\n' + module.read_text().replace(
            'export const behavior = nodeWireGuard();', 'export const behavior = nodeWireGuard(undefined, () => fixtureHttp, fixtureSystem);'))
        failure = (ROOT / 'extensions/behaviors/operations/failure.ts').read_text()
        (ops / 'failure.ts').write_text('import {fixtureHttp} from "./node/network_root_native_fixture.ts";\n' + failure.replace(
            'export const behavior = nativeFailure();', 'export const behavior = nativeFailure(() => fixtureHttp);'))
        self.key = 'A' * 43 + '='
        self.hub = {'schema_version': 1, 'executor_node_id': 1, 'hub_id': 1, 'context_node_id': 13, 'excluded_node_id': None, 'agent_sha256': 'a' * 64}
        self.network = {'schema_version': 1, 'node_id': 13, 'agent_sha256': 'a' * 64, 'hub_id': 1, 'existing_key': None, 'architecture': 'x86_64',
                        'peer': {'server_public_key': 'B' * 43 + '=', 'endpoint': 'vpn.example.test:51820', 'address': '10.44.0.13/24', 'subnet': '10.44.0.0/24',
                                 'dns_server': '10.44.0.1', 'domains': ['orbit'], 'operator_dns': None, 'retain': False},
                        'trust': {'desired': [], 'remove': [], 'enable': False}}
        self.operation = {'id': 7, 'kind': 'node.network_converge', 'node_id': 13, 'status': 'queued', 'cancel_requested': False,
                          'input': {'network': self.network, 'hub': self.hub}}
        self.calls = []; self.hub_configs = []; self.peer_key = None; self.committed = False; self.mode = 'success'
        workflow = json.loads((ROOT / 'workflows/workflow-operation-node-network-converge.yaml').read_text())
        workflow.pop('target'); workflow.pop('labels')
        for job in workflow['jobs']:
            job.pop('target', None); job.pop('labels', None)
        (self.repo / 'workflows/workflow-operation-node-network-converge.yaml').write_text(json.dumps(workflow))
        owner = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def respond(self, value, status=200):
                data = json.dumps(value).encode()
                self.send_response(status); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(data))); self.end_headers(); self.wfile.write(data)

            def do_GET(self):
                if urlsplit(self.path).path.endswith('/private-material'):
                    peers = [] if owner.peer_key is None else [{'node_id': 13, 'name': 'shark', 'public_key': owner.peer_key, 'address': '10.44.0.13'}]
                    return self.respond({'data': {'configuration': {'snapshot': owner.hub, 'address': '10.44.0.1/24', 'port': 51820, 'private_key': owner.secrets[0], 'peers': peers}}})
                return self.respond({'data': owner.operation})

            def do_POST(self):
                body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                if self.path == '/_system':
                    name = body['name']; owner.calls.append(name)
                    if name == 'wireguard.project-hub':
                        owner.hub_configs.append(body['input']['config'])
                        if owner.mode == 'hub_failure' and len(owner.hub_configs) == 1:
                            return self.respond({'error_code': 'wireguard.hub_start_failed'})
                    elif name == 'wireguard.recover-peer':
                        return self.respond({'restore_public_key': not owner.committed})
                    elif name == 'firewall.rules':
                        return self.respond({'error_code': 'firewall.apply_failed'} if owner.mode == 'firewall_failure' else {'matches': True})
                    elif name == 'wireguard.commit-peer':
                        if owner.mode == 'commit_refusal':
                            return self.respond({'error_code': 'wireguard.peer_commit_failed'})
                        owner.committed = True
                    return self.respond({'public_key': owner.key} if name in ['wireguard.prepare-peer-key', 'wireguard.publish-peer'] else {})
                owner.assertNotIn(owner.secrets[0], json.dumps(body))
                if self.path.endswith('/failure'):
                    owner.operation['status'] = 'failed'
                    return self.respond({'data': owner.operation})
                if body['status'] == 'succeeded' and owner.mode == 'lost_commit_callback':
                    return self.respond({'error': {'code': 'fixture.lost_callback'}}, 500)
                owner.operation['status'] = body['status']; owner.operation['result'] = body['result']
                if body['result'].get('restore_peer_key'):
                    owner.peer_key = owner.network['existing_key']
                elif 'public_key' in body['result']:
                    owner.peer_key = body['result']['public_key']
                return self.respond({'data': owner.operation})

        server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        self.addCleanup(server.server_close); self.addCleanup(server.shutdown)
        self.native_env['ORBIT_NETWORK_ROOT_FIXTURE'] = f'http://127.0.0.1:{server.server_port}'

    def run_network(self, success=True):
        self.cli('workflow', 'validate', 'operation-node-network-converge')
        self.cli('workflow', 'run', 'operation-node-network-converge', '--input', 'operation_id=7', '--input', 'node_id=13', '--input', 'kind=node.network_converge', success=success)

    def test_success_records_key_before_hub_then_commits_the_peer(self):
        self.run_network()
        self.assertEqual(self.operation['status'], 'succeeded')
        self.assertEqual(self.calls, ['wireguard.prepare-peer-key', 'wireguard.project-hub', 'wireguard.publish-peer', 'firewall.rules', 'wireguard.commit-peer'])
        self.assertIn('PublicKey = ' + self.key, self.hub_configs[0]); self.assertEqual(self.peer_key, self.key)

    def test_hub_refusal_restores_recorded_key_and_hub_before_failure(self):
        self.mode = 'hub_failure'; self.run_network(success=False)
        self.assertEqual(self.operation['status'], 'failed'); self.assertIsNone(self.peer_key)
        self.assertEqual(self.calls, ['wireguard.prepare-peer-key', 'wireguard.project-hub', 'wireguard.recover-peer', 'wireguard.project-hub'])
        self.assertNotIn('[Peer]', self.hub_configs[-1])

    def test_post_publish_failure_recovers_peer_then_public_key_and_hub(self):
        self.mode = 'firewall_failure'; self.run_network(success=False)
        self.assertEqual(self.operation['status'], 'failed'); self.assertIsNone(self.peer_key)
        self.assertEqual(self.calls, ['wireguard.prepare-peer-key', 'wireguard.project-hub', 'wireguard.publish-peer', 'firewall.rules', 'wireguard.recover-peer', 'wireguard.project-hub'])
        self.assertNotIn('[Peer]', self.hub_configs[-1])

    def test_lost_commit_callback_preserves_applied_peer_without_faking_success(self):
        self.mode = 'lost_commit_callback'; self.run_network(success=False)
        self.assertEqual(self.operation['status'], 'failed'); self.assertEqual(self.peer_key, self.key)
        self.assertTrue(self.committed); self.assertEqual(len(self.hub_configs), 1); self.assertEqual(self.calls[-1], 'wireguard.recover-peer')

    def test_bounded_commit_refusal_enters_compensation_and_failed_settlement(self):
        self.mode = 'commit_refusal'; self.run_network(success=False)
        self.assertEqual(self.operation['status'], 'failed'); self.assertIsNone(self.peer_key)
        self.assertFalse(self.committed)
        self.assertEqual(self.calls[-3:], ['wireguard.commit-peer', 'wireguard.recover-peer', 'wireguard.project-hub'])

    def test_cancelled_admission_starts_no_network_effect(self):
        self.operation['cancel_requested'] = True
        self.run_network()
        self.assertEqual(self.operation['status'], 'cancelled'); self.assertEqual(self.calls, [])


if __name__ == '__main__':
    unittest.main()
