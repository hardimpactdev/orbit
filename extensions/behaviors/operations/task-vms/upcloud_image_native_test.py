"""Native UpCloud image root with shared harness and fake provider/private transport."""
import hashlib
import json
from pathlib import Path
import sys
import unittest
import uuid
import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class UpCloudImageNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/task-vms')
        artifact = 'pinned-image-' + uuid.uuid4().hex
        private = '-----BEGIN OPENSSH PRIVATE KEY-----\n' + uuid.uuid4().hex + '\n-----END OPENSSH PRIVATE KEY-----\n'
        token = 'ucat_' + uuid.uuid4().hex
        self.native_env.update(ORBIT_TEST_IMAGE_PRIVATE=private, ORBIT_TEST_IMAGE_TOKEN=token, ORBIT_TEST_IMAGE_ARTIFACT=artifact)
        self.secrets = (private, token, artifact)
        p = self.repo / 'extensions/behaviors/operations/task-vms/upcloud_image_direct.ts'
        self.assertEqual(p.read_text().count('...upcloudImageDirect()'), 1)
        p.write_text('import {fixtureHttp,fixtureCloud,fixtureGuest,fixtureKeys} from "./upcloud_image_native_fixture.ts";\n' + p.read_text().replace('...upcloudImageDirect()', '...upcloudImageDirect(() => fixtureHttp, fixtureCloud, fixtureGuest, fixtureKeys)'))
        wf = yaml.safe_load((ROOT / 'workflows/workflow-operation-task-vm-upcloud-image-build.yaml').read_text())
        for job in wf['jobs']:
            self.assertEqual(job.pop('target'), '${{ "orbit-node-" + string(inputs.node_id) }}')
            self.assertEqual(job.pop('labels'), {'node': '${{ string(inputs.node_id) }}'})
        (self.repo / 'workflows/workflow-operation-task-vm-upcloud-image-build.yaml').write_text(yaml.safe_dump(wf, sort_keys=False))
        self.state = {'calls': [], 'provider': [], 'guest': [], 'phases': [], 'servers': {},
            'templates': {'00000000-0000-4000-8000-000000000030': {'uuid': '00000000-0000-4000-8000-000000000030', 'title': 'orbit-task-base-20261030000000', 'access': 'private', 'state': 'online'}},
            'keysStored': False, 'artifactDelivered': False, 'progress': {'resources': {}, 'template_uuid': None},
            'operation': {'id': 7, 'kind': 'task_vm.upcloud_image_build', 'node_id': 14, 'status': 'queued', 'cancel_requested': False,
                'input': {'build_id': 7, 'node_id': 14, 'identity': 'sha256:' + 'b' * 64, 'generation': 1,
                    'stamp': '20261031050000', 'name': 'tvm-image-20261031050000', 'source': '00000000-0000-4000-8000-000000000001',
                    'agent_sha256': 'a' * 64, 'gateway_public_key': 'ssh-ed25519 AAAA fixture', 'ssh_identity': '/unused/key',
                    'upcloud': {'zone': 'fi-hel1', 'plan': 'test-plan', 'disk_gb': 30}, 'pinned_templates': [],
                    'recipe': {'identity': 'sha256:' + 'b' * 64, 'agent_sha256': 'a' * 64, 'pi_sha256': hashlib.sha256(artifact.encode()).hexdigest()},
                    'pi_artifact': {'sha256': hashlib.sha256(artifact.encode()).hexdigest()}}}}
        self.save()

    def save(self):
        (self.repo / 'upcloud-image-fixture.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / 'upcloud-image-fixture.json').read_text())

    def run_image(self, success=True):
        self.cli('workflow', 'validate', 'operation-task-vm-upcloud-image-build')
        result = self.cli('workflow', 'run', 'operation-task-vm-upcloud-image-build', '--input', 'operation_id=7',
            '--input', 'node_id=14', '--input', 'kind=task_vm.upcloud_image_build', success=success)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def test_full_native_root_smokes_before_promotion_and_leaves_no_servers_or_private_bytes(self):
        self.run_image()
        saved = self.read()
        self.assertEqual(saved['operation']['status'], 'succeeded')
        self.assertTrue(saved['keysStored'])
        self.assertTrue(saved['artifactDelivered'])
        self.assertEqual(saved['servers'], {})
        self.assertEqual(saved['templates']['00000000-0000-4000-8000-000000000020']['title'], 'orbit-task-base-20261031050000')
        self.assertLess(saved['phases'].index('smoke'), saved['phases'].index('promote'))
        self.assertTrue(all('/direct-operations/' in path for _, path in saved['calls']))

    def test_failed_smoke_cleans_only_owned_servers_and_unverified_template(self):
        self.state['failSmoke'] = True
        self.save()
        self.run_image(success=False)
        saved = self.read()
        self.assertEqual(saved['operation']['status'], 'failed')
        self.assertEqual(saved['failure'], 'task_vm.image_check_failed')
        self.assertEqual(saved['servers'], {})
        self.assertEqual(list(saved['templates']), ['00000000-0000-4000-8000-000000000030'])
        self.assertFalse(any(method == 'PUT' for method, _ in saved['provider']))

    def test_lost_terminal_projection_resumes_same_root_without_repeating_provider_actions(self):
        self.state['loseFinal'] = True
        self.save()
        run = self.run_image(success=False)
        saved = self.read()
        self.assertEqual(saved['operation']['status'], 'succeeded')
        mutations = [call for call in saved['provider'] if call[0] != 'GET']
        self.cli('workflow', 'resume', 'operation-task-vm-upcloud-image-build', '--run', run)
        self.assertEqual([call for call in self.read()['provider'] if call[0] != 'GET'], mutations)

    def test_an_owned_builder_from_a_lost_create_reply_is_adopted_without_another_create(self):
        self.state['keysStored'] = True
        ident = '00000000-0000-4000-8000-000000000011'
        name = self.state['operation']['input']['name']
        self.state['servers'][ident] = {'uuid': ident, 'title': name, 'hostname': name, 'state': 'started',
            'labels': {'label': [{'key': 'orbit-task-image', 'value': '20261031050000'}]},
            'ip_addresses': {'ip_address': [{'address': '203.0.113.50', 'access': 'public', 'family': 'IPv4'}]},
            'storage_devices': {'storage_device': [{'storage': '00000000-0000-4000-8000-000000000015'}]}}
        self.save()
        self.run_image()
        self.assertEqual(sum(call == ['POST', 'server'] for call in self.read()['provider']), 1)

    def test_a_matching_name_with_a_foreign_label_is_never_mutated(self):
        ident = '00000000-0000-4000-8000-000000000011'
        name = self.state['operation']['input']['name']
        foreign = {'uuid': ident, 'title': name, 'hostname': name, 'state': 'started',
            'labels': {'label': [{'key': 'orbit-task-image', 'value': 'foreign'}]},
            'ip_addresses': {'ip_address': []},
            'storage_devices': {'storage_device': [{'storage': '00000000-0000-4000-8000-000000000015'}]}}
        self.state['servers'][ident] = foreign
        self.save()
        self.run_image(success=False)
        self.assertEqual(self.read()['servers'][ident], foreign)
        self.assertTrue(all(method == 'GET' for method, _ in self.read()['provider']))

    def test_superseded_intent_performs_no_provider_mutation(self):
        self.state['current'] = False
        self.save()
        self.run_image(success=False)
        saved = self.read()
        self.assertEqual(saved['operation']['status'], 'failed')
        self.assertTrue(all(method == 'GET' for method, _ in saved['provider']))


if __name__ == '__main__':
    unittest.main()
