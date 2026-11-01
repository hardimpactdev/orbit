"""Real native create DAG; only local effects and Gateway I/O are replaced."""
import json
from pathlib import Path
import sys
import unittest
import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class ProvisioningNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations')
        source = ROOT / 'workflows/workflow-operation-instance-create.yaml'
        workflow = yaml.safe_load(source.read_text())
        for job in workflow['jobs']:
            self.assertEqual(job.pop('target'), '${{ "orbit-node-" + string(inputs.node_id) }}')
            self.assertEqual(job.pop('labels'), {'node': '${{ string(inputs.node_id) }}'})
        (self.repo / 'workflows' / source.name).write_text(yaml.safe_dump(workflow, sort_keys=False))
        module = self.repo / 'extensions/behaviors/operations/instance-lifecycle/create_root.ts'
        module.write_text('''import {fixtureHttp, fixtureInvoke, fixtureEngineRefused} from "./provisioning_native_fixture.ts";
import {provisioningRoot} from "./provisioning_root.ts";
const root = provisioningRoot("instance.create", () => fixtureHttp, fixtureInvoke, undefined, {
 local: (mode, input) => {
  if (mode !== "project") throw new Error("Unexpected native VP effect");
  return fixtureInvoke({name: "tool.read-vp-project", input, pin: "", sudo: false, mutates: false, deadlineMs: 120000});
 }
});
function available() { if (fixtureEngineRefused()) throw new Error("Family bundle refused before first callback"); }
export const behavior = {...root, methods: {...root.methods,
stage: {...root.methods.stage, execute: (...args: Parameters<typeof root.methods.stage.execute>) => {
  available(); return root.methods.stage.execute(...args);
}},
fail: {...root.methods.fail, execute: (...args: Parameters<typeof root.methods.fail.execute>) => {
  available(); return root.methods.fail.execute(...args);
}},
}};
''')
        self.state_file = self.repo / 'provisioning-fixture.json'

    def run_create(self, success=True):
        return self.cli('workflow', 'run', 'operation-instance-create', '--input', 'operation_id=7',
                        '--input', 'node_id=9', '--input', 'kind=instance.create', success=success)

    def test_create_has_one_native_root_and_no_child_endpoints(self):
        checkout = '/srv/apps/proof/test'
        plan = {'instance_id': 11, 'project_id': 1, 'node_id': 9, 'node_user': 'orbit',
                'platform': 'linux', 'architecture': 'x86_64', 'vp_materialize': {'user': 'orbit'},
                'environment': 'development', 'user': 'orbit', 'checkout': checkout, 'application': checkout,
                'agent_sha256': 'a' * 64, 'project_type': 'node-package', 'entry_point': 'artisan',
                'skip_profile': False, 'php_versions': ['8.5'], 'already_active': False, 'retained_source': None,
                'source': {'user': 'orbit', 'checkout': checkout, 'root': '/srv/apps',
                           'repository': 'https://github.com/example/proof.git',
                           'prepare_id': '0c4f71cf-84bd-4b80-8da4-8cd1b39cb8b6', 'seed_repository': None},
                'resolve': {'instance_name': 'test', 'default_branch': 'main', 'branch_override': None,
                            'seed_commit': None, 'network': True, 'protocols': ['https']},
                'setup': {'instance_id': 11, 'project_id': 1, 'environment': 'development', 'list': 'setup',
                          'steps': [], 'execution': {'variant': 'checkout', 'user': 'orbit', 'directory': checkout,
                                                    'seed_path': '', 'seed_commit': ''}}}
        self.state_file.write_text(json.dumps({'operation': {'id': 7, 'kind': 'instance.create', 'node_id': 9,
                                   'status': 'queued', 'result': None, 'cancel_requested': False,
                                   'input': {'direct_provisioning': plan}}, 'effects': [], 'requests': []}))
        self.cli('workflow', 'validate', 'operation-instance-create')
        self.run_create()
        state = json.loads(self.state_file.read_text())
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['operation']['result'], {'instance_id': 11, 'ready': True, 'name_converted_from': None})
        self.assertTrue(all('/direct-operations/' in row['path'] for row in state['requests']))
        self.assertFalse(any('/failure' in row['path'] for row in state['requests']))

    def test_engine_failure_retains_native_proof_without_a_gateway_callback(self):
        self.state_file.write_text(json.dumps({'operation': {'id': 7, 'kind': 'instance.create', 'node_id': 9,
                                   'status': 'running', 'result': None, 'cancel_requested': False, 'input': {}},
                                   'engineRefused': True, 'effects': [], 'requests': []}))
        self.run_create(success=False)
        state = json.loads(self.state_file.read_text())
        self.assertEqual(state['operation']['status'], 'running')
        self.assertIsNone(state['operation'].get('error_code'))
        self.assertEqual(state['effects'], [])
        self.assertEqual(state['requests'], [])


if __name__ == '__main__':
    unittest.main()
