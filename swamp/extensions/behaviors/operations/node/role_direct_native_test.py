"""One native role root with injected local effects; no fleet access."""
import json
import yaml
import os
import pwd
import grp
import shutil
import subprocess
from pathlib import Path
import re
import sys
import unittest
import threading
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class DirectRoleNative(NativeTestCase):
    native_timeout = 50

    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'node-worker', 'operations')
        inventory = json.loads((ROOT / 'extensions/models/upstream_extensions.json').read_text())
        self.cli('extension', 'pull', '@swamp/cfgmgmt@' + inventory['@swamp/cfgmgmt']['version'])
        self.tmpfiles = self.repo / 'orbit-tmpfiles.conf'
        tools = self.repo / 'tools'
        tools.mkdir()
        scripts = {
            'ssh': "import subprocess,sys\nif '-M' in sys.argv or '-O' in sys.argv:sys.exit(0)\nsys.exit(subprocess.call(['/bin/sh','-c',sys.argv[-1]]))\n",
            'scp': "import shutil,sys\nshutil.copyfile(sys.argv[-2],sys.argv[-1].split(':',1)[1])\n",
        }
        for name, source in scripts.items():
            binary = tools / name
            binary.write_text('#!' + sys.executable + '\n' + source)
            binary.chmod(0o700)
        self.native_env['PATH'] = str(tools) + ':' + os.environ['PATH']
        module = self.repo / 'extensions/behaviors/operations/fleet-recipe/node_role.ts'
        module.write_text('import {fixtureHttp, fixtureBaseline, fixtureFootprint, fixtureCli} from "../node/role_direct_native_fixture.ts";\n' +
                          module.read_text().replace('export const behavior = nodeRoleAdd();',
                            'export const behavior = nodeRoleAdd(fixtureBaseline, () => fixtureHttp, fixtureFootprint, fixtureCli);'))
        removal = self.repo / 'extensions/behaviors/operations/node/role_removal.ts'
        removal.write_text('import {fixtureSourceHttp,fixtureCleanup,fixtureGrace} from "./role_removal_native_fixture.ts";\n' + removal.read_text().replace('export const behavior = roleRemoval();','export const behavior = roleRemoval(() => fixtureSourceHttp, fixtureCleanup, fixtureGrace);'))
        for filename, factory in [('role_metrics.ts', 'roleMetrics'), ('role_publication.ts', 'rolePublication'), ('role_certificates.ts', 'roleCertificates')]:
            helper = self.repo / 'extensions/behaviors/operations/node' / filename
            helper.write_text('import {fixtureHttp} from "./role_direct_native_fixture.ts";\n' + helper.read_text().replace(f'export const behavior = {factory}();', f'export const behavior = {factory}(() => fixtureHttp);'))
        source = ROOT / ('workflows/workflow-' + getattr(self, 'workflow_name', 'operation-node-role-add-direct') + '.yaml')
        self.source = source.read_text()
        (self.repo / 'workflows' / source.name).write_text(json.dumps(self.local_workflow(yaml.safe_load(self.source))))
        self.state = {'operation': {'id': 91, 'kind': 'node.role_add', 'node_id': 13,
            'status': 'queued', 'result': None, 'cancel_requested': False, 'input': {
            'certificates': {'leaves': [], 'withdrawals': []}, 'metrics_fleet': None, 'runtime': None, 'schema_version': 1, 'node_id': 13, 'agent_sha256': 'a' * 64,
            'assignment_id': 33, 'publication': {'caddy_node_ids': [], 'php_node_ids': [], 'dns_node_ids': []}, 'role': 'ci-runner', 'created': False, 'user': 'nckrtl',
            'prerequisites': {'packages': ['incus'], 'releases': ['resolute'], 'apps_directory': False, 'composer_home': False},
            'firewall': {'desired': [], 'remove': [], 'enable': False},
            'cli': {'user': 'nckrtl', 'release': {'version': '1.2.3', 'url': 'https://example.test/orbit', 'sha256': 'c' * 64},
                    'profile': '{}', 'skip_reason': None, 'plan_error': None},
            'footprint_resources': {'ssh': {'control_node_id': 209, 'identity_file': '/etc/orbit/swamp-role-worker/ssh-identity', 'public_key': 'ssh-ed25519 fixture',
                'target': {'name': 'target', 'host': 'dev.invalid', 'user': 'nckrtl', 'port': 22, 'host_keys': ['ssh-ed25519 fixture']}},
                'user': 'nckrtl', 'home': '/home/nckrtl', 'php_configurations': [], 'tmpfiles': 'e /tmp/orbit-* - - - 1d\n', 'dns': None, 'residue': None},
            'footprint': {'schema_version': 1, 'node_id': 13, 'agent_sha256': 'a' * 64,
                'force_artifacts': False, 'first_visit': False, 'network': None, 'refusal': None,
                'artifacts': [{'name': name, 'digest': 'd' * 64, 'required': True, 'repair': False} for name in ['agent', 'tmpfiles']],
                'payloads': {}}}}, 'effects': [], 'calls': []}
        self.save()

    def local_workflow(self, value):
        if isinstance(value, list): return [self.local_workflow(entry) for entry in value]
        if isinstance(value, dict):
            if value.get('behaviorType') == '@swamp/cfgmgmt/file':
                value['globalArgs']['path'] = str(self.tmpfiles)
                value['globalArgs']['nodeHost'] = 'fixture'
                value['globalArgs']['owner'] = pwd.getpwuid(os.getuid()).pw_name
                value['globalArgs']['nodeUser'] = pwd.getpwuid(os.getuid()).pw_name
                value['globalArgs']['group'] = grp.getgrgid(os.getgid()).gr_name
                for key in ['nodePort', 'nodeIdentityFile', 'become']:
                    value['globalArgs'].pop(key, None)
            return {key:self.local_workflow(entry) for key,entry in value.items() if key not in ['target','labels']}
        return value

    def save(self):
        (self.repo / 'native-role.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / 'native-role.json').read_text())

    def run_workflow(self, success=True):
        self.cli('workflow', 'validate', getattr(self, 'workflow_name', 'operation-node-role-add-direct'))
        result = self.cli('workflow', 'run', getattr(self, 'workflow_name', 'operation-node-role-add-direct'),
                        '--input', 'operation_id=91', '--input', 'node_id=13',
                        '--input', 'kind=' + self.state['operation']['kind'], success=success)
        self.run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
        return result

    def test_one_native_root_composes_baseline_footprint_and_cli_without_children(self):
        self.run_workflow()
        state = self.read()
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['effects'][:5], ['agent-install', 'caddy-package', 'prerequisites', 'ci-host', 'firewall'])
        self.assertEqual(state['effects'][-1], 'native-cli-install')
        self.assertEqual(len(state['effects']), 9)
        self.assertEqual(self.tmpfiles.read_text(), 'e /tmp/orbit-* - - - 1d\n')
        self.assertEqual(len(state['operation']['result']['footprint']['artifacts']), 2)
        self.assertIsNone(state['operation']['result']['follow_up'])
        self.assertTrue(all('/direct-operations/91' in call['path'] for call in state['calls']))

    def test_php_versions_are_native_children_and_a_failed_version_is_not_proven(self):
        child = {'id': '47b3bd2e-815e-412b-a3e1-5e608e6b107d', 'name': 'node-php-runtime',
                 'inputs': {'type': 'object'}, 'jobs': [{'name': 'php', 'steps': [{
                     'name': 'Test PHP-FPM', 'task': {'type': 'assert', 'severity': 'high',
                         'expr': 'inputs.intent.php.runtime_ini != "refuse"', 'message': 'refused PHP version'}}]}]}
        (self.repo / 'workflows/workflow-node-php-runtime.yaml').write_text(json.dumps(child))
        snapshot = self.state['operation']['input']
        snapshot['footprint_resources'].update(php_configurations=[{'version': '8.4', 'runtime_ini': 'ready'}, {'version': '8.5', 'runtime_ini': 'refuse'}])
        snapshot['footprint']['artifacts'].append({'name': 'php-runtime', 'digest': 'd' * 64, 'required': True, 'repair': False})
        self.save()

        self.run_workflow(success=False)

        state = self.read()
        proof = state['operation']['result']['footprint']
        self.assertFalse(proof['footprint_proven'])
        self.assertEqual(proof['failed_artifact'], 'php-runtime')
        self.assertIn('role-footprint:', state['operation']['result']['follow_up'])
        self.assertFalse(self.tmpfiles.exists())

    def test_cached_tmpfiles_are_unchanged_without_a_node_write(self):
        snapshot = self.state['operation']['input']
        snapshot['footprint_resources']['tmpfiles'] = None
        snapshot['footprint']['artifacts'][1]['required'] = False
        self.save()

        self.run_workflow()

        state = self.read()
        self.assertFalse(self.tmpfiles.exists())
        self.assertIn({'name': 'tmpfiles', 'status': 'unchanged'}, state['operation']['result']['footprint']['artifacts'])

    def test_exact_node_label_places_dynamic_participants(self):
        self.assertNotIn('"orbit-node-" + string(', self.source)
        self.assertIn("node: '${{ string(inputs.node_id) }}'", self.source)
        self.assertIn('analytics_storage', self.source)
        self.assertIn('snapshot.runtime.clickhouse.node_id', self.source)
        self.assertEqual(self.read()['effects'], [])


    def test_real_gateway_http_private_callback_leaves_no_credentials_in_native_scratch(self):
        self.secrets = (uuid.uuid4().hex + uuid.uuid4().hex,)
        self.native_env['ORBIT_ROLE_PRIVATE_HASH'] = self.secrets[0]
        self.state['operation']['input']['footprint']['payloads']['agent'] = {
            'configuration': '[swamp_worker]\nnode_id = 13\nuser = "nckrtl"\n',
            'certificate': 'PUBLIC TEST CA', 'unit': '[Service]\nProtectHome=yes\n',
            'user': 'nckrtl', 'apps_root': None, 'checkouts': [], 'url': None,
        }
        self.save()
        owner = self
        private_reads = []
        projections = []
        errors = []

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def respond(self, body, status=200):
                payload = json.dumps(body).encode()
                self.send_response(status)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Cache-Control', 'no-store')
                self.send_header('Content-Length', str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)

            def do_GET(self):
                route = urlsplit(self.path)
                run_id = parse_qs(route.query).get('run_id', [''])[0]
                if not re.fullmatch(r'[a-f0-9-]{36}', run_id):
                    errors.append('Unbound HTTP fixture read')
                    return self.respond({}, 422)
                if route.path == '/api/v1/direct-operations/91/private-material':
                    private_reads.append(run_id)
                    return self.respond({'data': {
                        'secret_hash': owner.secrets[0],
                    }})
                if route.path != '/api/v1/direct-operations/91':
                    errors.append('Unexpected HTTP fixture read')
                    return self.respond({}, 404)
                self.respond({'data': owner.state['operation']})

            def do_POST(self):
                body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                if self.path == '/api/v1/direct-operations/91/failure':
                    if not re.fullmatch(r'[a-f0-9-]{36}', body.get('run_id','')):
                        return self.respond({},422)
                    owner.state['operation']['status'] = 'failed'
                    return self.respond({'data':owner.state['operation']})
                if self.path != '/api/v1/direct-operations/91/result':
                    errors.append('Unexpected HTTP fixture write')
                    return self.respond({}, 404)
                if any(secret in json.dumps(body) for secret in owner.secrets):
                    errors.append('Private bytes escaped into a projection')
                    return self.respond({}, 422)
                projections.append(body)
                owner.state['operation']['status'] = body['status']
                owner.state['operation']['result'] = body.get('result')
                self.respond({'data': owner.state['operation']})

        server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.native_env['ORBIT_ROLE_HTTP_FIXTURE'] = f'http://127.0.0.1:{server.server_port}'
        try:
            self.run_workflow()
            self.assertEqual(owner.state['operation']['status'], 'succeeded')
            self.assertEqual(private_reads, [self.run_id])
            self.assertEqual(projections[-1]['run_id'], self.run_id)
            artifacts = {row['name']: row['status'] for row in projections[-1]['result']['footprint']['artifacts']}
            self.assertEqual(artifacts['agent'], 'unchanged')
            self.native_env['ORBIT_ROLE_PRIVATE_FAILURE'] = 'true'
            owner.state['operation']['status'] = 'queued'
            owner.state['operation']['result'] = None
            self.run_workflow(success=False)
            self.assertEqual(owner.state['operation']['status'], 'running')
            self.assertEqual(len(private_reads), 2)
            self.assertEqual(private_reads[-1], self.run_id)
            self.assertEqual(errors, [])
            self.assert_private_absent()
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=5)


if __name__ == '__main__':
    unittest.main()
