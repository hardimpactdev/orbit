"""Real official e459 graphs and HTTP requests against a disposable provider API."""
import http.server
import json
import threading
import unittest

import yaml
from native_harness import ROOT, NativeTestCase


class UpCloudProviderNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/task-vm-lifecycle')
        self.provider_id = 'bb757fe7-0d7f-497e-b160-b545075e2cba'
        self.image = '00000000-0000-4000-8000-000000000001'
        self.public = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG'
        self.token, self.private = 'UPCloud_secret_fixture_3f31c', '-----BEGIN OPENSSH PRIVATE KEY-----\nfixture-secret-host-key\n-----END OPENSSH PRIVATE KEY-----'
        self.exists, self.state, self.lost_create, self.foreign = False, 'stopped', False, False
        self.calls, self.create_documents = [], []
        self.detail_states = []
        self.address_only_when_started = False
        self.omit_list_labels, self.wrong_detail_uuid, self.wrong_detail_image = False, False, False
        owner = self

        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def answer(self, status, value):
                self.send_response(status)
                self.end_headers()
                self.wfile.write(json.dumps(value).encode())

            def row(self):
                return {'uuid': owner.provider_id, 'title': 'tvm-42', 'hostname': 'tvm-42', 'state': owner.state,
                        'labels': {'label': [{'key': 'orbit-task-vm', 'value': '43' if owner.foreign else '42'}, {'key': 'orbit-task-vm-image', 'value': owner.image}]},
                        'storage_devices': {'storage_device': [{'type': 'disk', 'storage': '3476acd6-23c5-4f77-baf8-56d812e6fbb4'}]},
                        'ip_addresses': {'ip_address': [] if owner.address_only_when_started and owner.state != 'started' else [{'access': 'public', 'family': 'IPv4', 'address': '93.184.216.34'}]},
                        'user_data': owner.private, 'root_password': owner.token}

            def do_GET(self):
                owner.calls.append(('GET', self.path))
                if self.headers.get('Authorization') != 'Bearer ' + owner.token:
                    return self.answer(403, {})
                if self.path.endswith('/plan'):
                    return self.answer(200, {'plans': {'plan': [{'name': 'test-plan', 'storage_tier': 'standard'}]}})
                if '?label=' in self.path:
                    row = self.row()
                    if owner.omit_list_labels:
                        row.pop('labels')
                    return self.answer(200, {'servers': {'server': [row] if owner.exists else []}})
                if owner.detail_states:
                    owner.state = owner.detail_states.pop(0)
                if owner.state == 'missing':
                    return self.answer(404, {})
                row = self.row()
                if owner.wrong_detail_image:
                    row['labels']['label'][1]['value'] = 'foreign-image'
                if owner.wrong_detail_uuid:
                    row['uuid'] = '6651dd0d-fb0e-4689-9664-22de529dd200'
                return self.answer(200, {'server': row}) if owner.exists else self.answer(404, {})

            def do_POST(self):
                owner.calls.append(('POST', self.path))
                body = json.loads(self.rfile.read(int(self.headers.get('Content-Length', '0'))))
                if self.path.endswith('/server'):
                    owner.create_documents.append(body)
                    owner.exists = True
                    if owner.lost_create:
                        return self.answer(503, {'error': owner.token})
                elif self.path.endswith('/start'):
                    owner.state = 'started'
                elif self.path.endswith('/stop'):
                    owner.state = 'stopped'
                return self.answer(200, {'server': {'uuid': owner.provider_id}})

            def do_DELETE(self):
                owner.calls.append(('DELETE', self.path))
                owner.exists = False
                return self.answer(200, {})

        server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(thread.join)
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        script = self.repo / 'extensions/behaviors/operations/task-vm-lifecycle/scripts/upcloud_api.py'
        script.write_text(script.read_text().replace('https://api.upcloud.com/1.3', f'http://127.0.0.1:{server.server_port}/1.3'))
        for name in ('task-vm-provider-upcloud', 'task-vm-provider-power'):
            value = yaml.safe_load((ROOT / f'workflows/workflow-{name}.yaml').read_text())
            for job in value['jobs']:
                self.assertEqual(job.pop('target'), 'orbit-node-209')
                for step in job['steps']:
                    self.assertEqual(step.pop('target'), 'orbit-node-209')
            (self.repo / f'workflows/workflow-{name}.yaml').write_text(yaml.safe_dump(value, sort_keys=False))
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        self.cli('vault', 'put', 'orbit-control-inputs', 'task-vm-upcloud-token', input=self.token)
        self.cli('vault', 'put', 'orbit-control-inputs', 'task-vm-provider-bootstrap-42', input=json.dumps({'private': self.private, 'public': self.public}))
        self.secrets = (self.token, 'fixture-secret-host-key')
        self.intent = {'task_vm_id': 42, 'node_id': 14, 'host_node_id': None, 'guest_node_id': 142,
                       'gateway_node_id': 14, 'model_router_node_id': 6, 'provider': 'upcloud', 'provider_id': None, 'name': 'tvm-42',
                       'image_fingerprint': self.image, 'recipe_identity': 'sha256:' + 'a' * 64, 'vm': None, 'create': None,
                       'bootstrap_host_key': self.public,
                       'ssh_identity': {'control_node_id': 209, 'identity_file': '/run/credentials/orbit-node-worker.service/proxy-ssh-identity', 'public_key': self.public},
                       'upcloud': {'zone': 'fi-hel1', 'plan': 'test-plan', 'disk_gb': 20, 'max_vms': 2, 'source_template': self.image}}
        self.copies = {'upcloud': {'vault': 'orbit-control-inputs', 'key': 'task-vm-upcloud-token'},
                       'bootstrap': {'vault': 'orbit-control-inputs', 'key': 'task-vm-provider-bootstrap-42'}}

    def create(self, success=True):
        inputs = {'operation_id': 7005, 'node_id': 14, 'kind': 'tasks.provision', 'intent': self.intent, 'execution_secrets': self.copies}
        run = json.loads(self.cli('workflow', 'run', 'task-vm-provider-upcloud', '--input', json.dumps(inputs), success=success).stdout)
        history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        print('UPCLOUD-PROVIDER-NATIVE-DURATION ' + json.dumps({
            'fixture_only': True,
            **{key: run.get(key) for key in ('id', 'status', 'startedAt', 'completedAt', 'duration')},
            'phases': [{key: step.get(key) for key in ('name', 'status', 'startedAt', 'completedAt', 'duration')}
                       for job in history['jobs'] for step in job['steps']],
        }), flush=True)
        return run, history

    def test_create_and_lost_response_observation_never_duplicate_and_never_expose_bootstrap(self):
        self.lost_create = True
        run, history = self.create()
        self.assertEqual(run['status'], 'succeeded')
        self.assertEqual(history['inputs']['kind'], 'tasks.provision')
        result = history['jobs'][0]['steps'][-1]['outputs']
        self.assertEqual(result['provider_id'], self.provider_id)
        self.assertEqual(result['ssh_host_key'], self.public)
        self.assertEqual(len(self.create_documents), 1)
        config = json.loads(self.create_documents[0]['server']['user_data'].removeprefix('#cloud-config\n'))
        self.assertEqual(config['ssh_keys']['ed25519_private'], self.private)
        self.assertEqual(config['users'][0]['ssh_authorized_keys'], [self.public])
        self.create()
        self.assertEqual(len(self.create_documents), 1)
        self.assert_private_absent()

    def test_new_server_maintenance_without_address_waits_until_started_without_duplicate_mutation(self):
        self.address_only_when_started = True
        self.detail_states = ['maintenance', 'maintenance', 'started']
        run, history = self.create()
        self.assertEqual(run['status'], 'succeeded')
        self.assertEqual(len(self.create_documents), 1)
        self.assertFalse(any(path.endswith('/start') for method, path in self.calls if method == 'POST'))
        self.assertGreaterEqual(sum(method == 'GET' and '?label=' not in path and not path.endswith('/plan') for method, path in self.calls), 3)
        self.assertEqual(history['jobs'][0]['steps'][-1]['outputs']['running'], True)
        self.assert_private_absent()

    def test_server_disappearing_during_start_poll_fails_without_another_read_or_write(self):
        self.detail_states = ['maintenance', 'missing']
        run, history = self.create(False)
        self.assertEqual(run['status'], 'failed')
        self.assertEqual(len(self.create_documents), 1)
        self.assertFalse(any(path.endswith('/start') for method, path in self.calls if method == 'POST'))
        self.assertEqual(sum(method == 'GET' and path == '/1.3/server/' + self.provider_id for method, path in self.calls), 2)
        self.assertEqual(next(step for step in history['jobs'][0]['steps'] if step['name'] == 'observe')['status'], 'failed')
        self.assert_private_absent()

    def test_bad_start_state_fails_without_another_create_or_start(self):
        self.detail_states = ['maintenance', 'error']
        run, history = self.create(False)
        self.assertEqual(run['status'], 'failed')
        self.assertEqual(len(self.create_documents), 1)
        self.assertFalse(any(path.endswith('/start') for method, path in self.calls if method == 'POST'))
        self.assertEqual(next(step for step in history['jobs'][0]['steps'] if step['name'] == 'observe')['status'], 'failed')
        self.assert_private_absent()

    def test_start_phase_timeout_fails_without_retrying_the_mutation(self):
        self.detail_states = ['maintenance']
        path = self.repo / 'workflows/workflow-task-vm-provider-upcloud.yaml'
        source = path.read_text()
        self.assertEqual(source.count('timeout 240 '), 1, 'The shortened fixture phase must replace its real bound exactly once')
        path.write_text(source.replace('timeout 240 ', 'timeout 1 '))
        run, history = self.create(False)
        self.assertEqual(run['status'], 'failed')
        self.assertEqual(len(self.create_documents), 1)
        self.assertFalse(any(path.endswith('/start') for method, path in self.calls if method == 'POST'))
        phase = next(step for step in history['jobs'][0]['steps'] if step['name'] == 'observe')
        self.assertEqual(phase['status'], 'failed')
        self.assertRegex(phase['error'], r'\bcode 124\b')
        print('UPCLOUD-TIMEOUT-BOUNDARY ' + json.dumps({'run_id': run['id'], 'phase': phase['name'], 'duration_ms': phase['duration'], 'error': phase['error'], 'fixture_phase_seconds': 1, 'harness_seconds': self.native_timeout}), flush=True)
        self.assert_private_absent()

    def test_foreign_label_refuses_before_create_or_power(self):
        self.exists, self.foreign = True, True
        run, _ = self.create(False)
        self.assertEqual(run['status'], 'failed')
        self.assertFalse(any(method != 'GET' for method, _ in self.calls))

    def test_label_less_list_is_verified_by_detail_before_start(self):
        self.exists, self.omit_list_labels = True, True
        run, _ = self.create()
        self.assertEqual(run['status'], 'succeeded')
        self.assertEqual(self.create_documents, [])
        self.assertEqual(self.state, 'started')

    def test_foreign_detail_uuid_never_reaches_start_or_ready(self):
        self.exists, self.wrong_detail_uuid = True, True
        run, _ = self.create(False)
        self.assertEqual(run['status'], 'failed')
        self.assertFalse(any(method != 'GET' for method, _ in self.calls))

    def test_unknown_uuid_discovery_stop_delete_and_absence_are_native_steps(self):
        self.exists, self.state, self.omit_list_labels = True, 'started', True
        inputs = {'operation_id': 7006, 'node_id': 14, 'kind': 'task_vm.destroy',
                  'intent': {'task_vm_id': 42, 'name': 'tvm-42', 'provider': 'upcloud', 'provider_id': None, 'vm': None, 'host_ssh_execution': None},
                  'execution_secrets': {'upcloud': self.copies['upcloud']}, 'action': 'stop'}
        for action in ('inspect', 'stop', 'delete', 'delete'):
            inputs['action'] = action
            run = json.loads(self.cli('workflow', 'run', 'task-vm-provider-power', '--input', json.dumps(inputs)).stdout)
            self.assertEqual(run['status'], 'succeeded')
        self.assertFalse(self.exists)
        self.assertEqual(sum(method == 'DELETE' for method, _ in self.calls), 1)
        self.assertTrue(any('storages=1&backups=delete' in path for method, path in self.calls if method == 'DELETE'))
        self.assert_private_absent()


if __name__ == '__main__':
    unittest.main()
