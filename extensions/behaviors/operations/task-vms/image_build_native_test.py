"""Native image DAG and file-data proof. No VM, sudo, token or fleet mutation."""
import hashlib
import json
from pathlib import Path
import sys
import unittest
import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class ImageBuildNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/task-vms')
        module = self.repo / 'extensions/behaviors/operations/task-vms/image_build_direct.ts'
        module.write_text('import {fixtureHttp, fixtureHost, fixtureGuest} from "./image_build_native_fixture.ts";\n' + module.read_text().replace('...imageBuildDirect()', '...imageBuildDirect(() => fixtureHttp, fixtureHost, fixtureGuest)'))
        workflow = ROOT / 'workflows/workflow-operation-task-vm-image-build.yaml'
        source = yaml.safe_load(workflow.read_text())
        self.assertEqual(source['jobs'][2]['target'], '${{ "orbit-node-" + string(inputs.node_id) }}')
        self.assertIn('artifact_target', source['jobs'][1]['target'])
        self.assertIn('artifact_label', source['jobs'][1]['labels']['node'])
        for job in source['jobs']:
            self.assertIn('node', job.pop('labels'))
            job.pop('target')
        (self.repo / 'workflows' / workflow.name).write_text(yaml.safe_dump(source, sort_keys=False))
        artifact = self.repo / 'sandbox-artifacts' / 'pi'
        artifact.parent.mkdir()
        artifact.write_bytes(b'public pinned binary')
        digest = hashlib.sha256(artifact.read_bytes()).hexdigest()
        self.state = {'calls': [], 'actions': [], 'guest': [], 'artifactDelivered': False,
            'operation': {'id': 7, 'kind': 'task_vm.image_build', 'node_id': 13, 'status': 'queued',
                'cancel_requested': False, 'result': None, 'input': {
                    'build_id': 7, 'attempt': 1, 'host_node_id': 13, 'artifact_node_id': 14,
                    'agent_sha256': 'a' * 64, 'identity': 'sha256:' + 'b' * 64, 'generation': 1,
                    'project': 'orbit-tasks', 'name': 'tvm-image-7', 'cidr': '10.251.77.0/24',
                    'pool': 'orbit-tasks', 'network': 'orbittask0', 'cpus': 2, 'memory': '4GiB', 'disk': '45GiB',
                    'source': 'ubuntu-26.04-vm', 'candidate_alias': 'candidate-7', 'base_alias': 'orbit-task-base',
                    'gateway_public_key': 'public-key', 'certificate': 'public-root',
                    'recipe': {'identity': 'sha256:' + 'b' * 64, 'agent_sha256': 'a' * 64, 'pi_sha256': digest},
                    'pi_artifact': {'path': str(artifact), 'sha256': digest},
                    'prune': {'project': 'orbit-tasks', 'operation': {'kind': 'prune', 'pool': 'orbit-tasks',
                        'obsolete_aliases': [], 'retired_aliases': [], 'protected_fingerprints': []}},
                }}}
        self.state['operation']['input']['recipe'] = json.dumps(self.state['operation']['input']['recipe'])
        self.save()

    def save(self):
        (self.repo / 'image-build-fixture.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / 'image-build-fixture.json').read_text())

    def run_workflow(self, success=True):
        result = self.cli('workflow', 'run', 'operation-task-vm-image-build', '--input', 'operation_id=7',
            '--input', 'node_id=13', '--input', 'kind=task_vm.image_build', success=success)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def test_full_dag_delivers_native_file_and_promotes_only_after_smoke_and_seed(self):
        self.run_workflow()
        saved = self.read()
        self.assertTrue(saved['artifactDelivered'])
        self.assertEqual(saved['operation']['status'], 'succeeded')
        self.assertTrue(all(call['uid'] == '1000' for call in saved['guest'] if call['name'] == 'image.owner'))
        self.assertTrue(any(call['name'] == 'image.owner' for call in saved['guest']))
        self.assertTrue(all('/direct-operations/' in path for path in saved['calls']))
        self.assertEqual([call['input']['operation']['kind'] for call in saved['actions'][-3:]],
            ['finish_seed', 'promote', 'prune'])

    def test_smoke_failure_never_promotes(self):
        self.state['failSmoke'] = True
        self.save()
        self.run_workflow(success=False)
        saved = self.read()
        self.assertEqual(saved['operation']['status'], 'failed')
        self.assertFalse(any(call['input'].get('operation', {}).get('kind') == 'promote' for call in saved['actions']))

    def test_lost_final_reply_resumes_same_root_without_repeating_actions(self):
        self.state['loseFinal'] = True
        self.save()
        run = self.run_workflow(success=False)
        actions = self.read()['actions']
        self.cli('workflow', 'resume', 'operation-task-vm-image-build', '--run', run)
        self.assertEqual(self.read()['actions'], actions)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')

    def test_bad_artifact_fails_before_any_host_action(self):
        self.state['operation']['input']['pi_artifact']['sha256'] = 'd' * 64
        recipe = json.loads(self.state['operation']['input']['recipe'])
        recipe['pi_sha256'] = 'd' * 64
        self.state['operation']['input']['recipe'] = json.dumps(recipe)
        self.save()
        self.run_workflow(success=False)
        self.assertEqual(self.read()['actions'], [])
        self.assertEqual(self.read()['operation']['status'], 'failed')


if __name__ == '__main__':
    unittest.main()
