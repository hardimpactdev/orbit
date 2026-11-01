"""Run the real clone DAG; replace only local effects and Gateway I/O."""
import json
from pathlib import Path
import sys
import unittest
import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class CloneNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations')
        source = ROOT / 'workflows/workflow-operation-instance-clone.yaml'
        self.workflow = yaml.safe_load(source.read_text())
        local = yaml.safe_load(source.read_text())
        for job in local['jobs']:
            job.pop('target', None)
            job.pop('labels', None)
            for step in job['steps']:
                step.pop('target', None)
                step.pop('labels', None)
        (self.repo / 'workflows' / source.name).write_text(yaml.safe_dump(local, sort_keys=False))
        module = self.repo / 'extensions/behaviors/operations/instance-lifecycle/clone_root.ts'
        module.write_text('import {cloneHttp, cloneInvoke} from "./clone_native_fixture.ts";\n' +
                          module.read_text().replace('provisioningRoot("instance.clone")',
                              'provisioningRoot("instance.clone", () => cloneHttp, cloneInvoke, undefined, {\n                                  local: (mode, input) => {\n                                      if (mode !== "project") throw new Error("Unexpected native VP effect");\n                                      return cloneInvoke({name: "tool.read-vp-project", input, pin: "", sudo: false, mutates: false, deadlineMs: 120000});\n                                  }\n                              })'))
        self.state_file = self.repo / 'provisioning-fixture.json'
        checkout = '/home/orbit-app-1/releases/initial'
        plan = {'instance_id': 11, 'project_id': 1, 'node_id': 9, 'node_user': 'orbit',
                'platform': 'linux', 'architecture': 'x86_64', 'vp_materialize': {'user': 'orbit'},
                'environment': 'production', 'user': 'orbit-app-1',
                'checkout': checkout, 'application': checkout, 'agent_sha256': 'a' * 64,
                'project_type': 'node-package', 'entry_point': 'artisan', 'skip_profile': False,
                'php_versions': ['8.5'], 'already_active': False, 'retained_source': None,
                'home': {'user': 'orbit-app-1', 'home': '/home/orbit-app-1'},
                'source': {'user': 'orbit-app-1', 'home': '/home/orbit-app-1',
                           'repository': 'https://github.com/example/proof.git', 'instance': 11},
                'resolve': {'relative_root': '.', 'application_suffix': '', 'branch': 'main', 'clone_target': True},
                'candidate': {'node_id': 17, 'environment': 'development', 'user': 'orbit',
                              'base': '/srv/candidate/default', 'expected_source': '/srv/candidate/default',
                              'repository': 'https://github.com/example/proof.git',
                              'configured_branch': 'main', 'target_branch': 'main'}}
        self.state = {'operation': {'id': 7, 'kind': 'instance.clone', 'node_id': 9, 'status': 'queued',
                                   'result': None, 'cancel_requested': False, 'input': {'direct_provisioning': plan}},
                      'effects': [], 'requests': [], 'callbacks': []}

    def run_clone(self, success=True):
        self.state_file.write_text(json.dumps(self.state))
        self.cli('workflow', 'run', 'operation-instance-clone', '--input', 'operation_id=7',
                 '--input', 'node_id=9', '--input', 'kind=instance.clone', success=success)
        actual = json.loads(self.state_file.read_text())
        self.assertTrue(all('/direct-operations/7' in row['path'] for row in actual['requests']))
        runs = {row['body']['run_id'] for row in actual['callbacks'] if row['method'] == 'POST'}
        if runs:
            self.assertEqual(len(runs), 1)
        else:
            self.assertFalse(success)
        return actual

    def test_clone_places_candidate_and_destination_in_one_native_root(self):
        job = self.workflow['jobs'][0]
        self.assertIn('inputs.node_id', job['target'])
        steps = {step['name']: step for step in job['steps']}
        self.assertIn('steps.admission.outputs.candidate_node_id', steps['candidate']['target'])
        self.assertIn('steps.admission.outputs.candidate_node_id', steps['candidate']['labels']['node'])
        self.assertTrue(all(step['task']['inputs']['run_id'] == '${{ run.id }}' for step in job['steps']))
        self.cli('workflow', 'validate', 'operation-instance-clone')
        actual = self.run_clone()
        self.assertEqual(actual['operation']['status'], 'succeeded')
        self.assertEqual(actual['operation']['result'], {'instance_id': 11, 'ready': True})
        names = [effect['name'] for effect in actual['effects']]
        self.assertEqual(names[0], 'instance.inspect-clone-candidate')
        self.assertIn('instance.prepare-production-user', names)
        self.assertIn('instance.resolve-production-source', names)
        source = next(row['body']['result'] for row in actual['callbacks']
                      if row['method'] == 'POST' and row['body']['result'].get('phase') == 'source')
        self.assertEqual(source['candidate_commit'], 'a' * 40)

    def test_candidate_failure_records_failure_without_destination_effects(self):
        self.state['fail_effect'] = 'instance.inspect-clone-candidate'
        actual = self.run_clone(success=False)
        self.assertEqual(actual['effects'], [])
        self.assertEqual(actual['operation']['status'], 'failed')
        self.assertEqual(actual['operation']['result'], {'failed_step': 'candidate'})

    def test_engine_failure_does_not_report_through_a_gateway_callback(self):
        self.state['operation']['input'] = {}
        self.state['operation']['status'] = 'running'
        actual = self.run_clone(success=False)
        self.assertEqual(actual['effects'], [])
        self.assertEqual(actual['operation']['status'], 'running')
        self.assertIsNone(actual['operation'].get('error_code'))
        self.assertFalse(any(row['method'] == 'POST' for row in actual['callbacks']))

    def test_cancelled_clone_settles_without_effects(self):
        self.state['operation']['cancel_requested'] = True
        actual = self.run_clone(success=False)
        self.assertEqual(actual['effects'], [])
        self.assertEqual(actual['operation']['status'], 'cancelled')


if __name__ == '__main__':
    unittest.main()
