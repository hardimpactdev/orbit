"""Execute the actual native DAG with disposable Docker/SSH child boundaries."""
import json
import sys
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class DockerNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/fleet-docker')
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-fleet-docker-migrate.yaml').read_text())
        for job in workflow['jobs']:
            for step in job['steps']:
                step.pop('target')
                task = step.get('task', {})
                if task.get('behaviorType') == '@swamp/ssh':
                    task['behaviorType'] = '@fixture/docker-transport'
                    task.pop('globalArgs')
        (self.repo / 'workflows/workflow-operation-fleet-docker-migrate.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        child = {'id': '689cad3b-d90c-49fe-bf2f-5ed0974a32ec', 'name': 'operation-process-create', 'inputs': {'type': 'object', 'additionalProperties': True}, 'jobs': [{'name': 'child', 'steps': [{'name': 'apply', 'task': {'type': 'behavior', 'behaviorType': '@fixture/docker-transport', 'behaviorName': 'child', 'methodName': 'exec', 'inputs': {'argv': ['child-process']}}}]}]}
        (self.repo / 'workflows/workflow-operation-process-create.yaml').write_text(yaml.safe_dump(child))
        self.intent = {'mode': 'migrate', 'migration': {'recipe': 'fixture', 'snapshot': '20261031T000000Z', 'architecture': 'amd64', 'docker_version': '29.1.3', 'containerd_version': '2.2.2', 'process_ids': ['42'], 'process_states': {'42': 'running'}, 'process_specs': {'42': {'name': 'orbit-process-42-app', 'hash': 'a'*64, 'volumes': []}}, 'fallback': False}, 'ssh_execution': {'identity_file': '/synthetic', 'target': {'host': 'fixture', 'user': 'orbit', 'port': 22, 'host_keys': ['fixture']}}, 'processes': [{'id': 42, 'intent': {}}]}
        self.save()

    def save(self, **extra):
        (self.repo / 'fixture.json').write_text(json.dumps(dict(calls=[], packages=0, **extra)))

    def state(self):
        return json.loads((self.repo / 'fixture.json').read_text())

    def start(self, success=True):
        self.cli('workflow', 'validate', 'operation-fleet-docker-migrate')
        result = self.cli('workflow', 'run', 'operation-fleet-docker-migrate', '--input', json.dumps({'operation_id': 7, 'node_id': 3, 'kind': 'fleet.docker_migrate', 'intent': self.intent, 'execution_secrets': {}}), success=success)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def test_visible_native_order_process_child_and_terminal_stdout(self):
        root = self.start()
        calls = self.state()['calls']
        self.assertLess(calls.index('stop'), calls.index('packages'))
        self.assertLess(calls.index('packages'), calls.index('readiness'))
        self.assertLess(calls.index('readiness'), calls.index('restore-policy'))
        self.assertLess(calls.index('restore-policy'), calls.index('report'))
        history = json.loads(self.cli('workflow', 'history', 'get', root).stdout)
        result = next(s for job in history['jobs'] for s in job['steps'] if s['name'] == 'result')
        self.assertTrue(json.loads(result['outputs']['stdout'])['healthy'])
        process = next(s for job in history['jobs'] for s in job['steps'] if s['name'] == 'process-0')
        self.assertEqual(process['status'], 'succeeded')

    def test_plan_skips_every_mutation_and_process_child(self):
        self.intent['mode'] = 'plan'
        self.start()
        self.assertEqual([c for c in self.state()['calls'] if c != 'transport'], ['inventory'])
        self.assertEqual(self.state()['packages'], 0)

    def test_unstartable_layer_requires_explicit_fallback_and_native_stop_probe(self):
        self.save(fail_probe=True)
        self.intent['migration']['fallback'] = True
        self.start()
        calls = self.state()['calls']
        self.assertLess(calls.index('probe-start'), calls.index('probe-stop'))
        self.assertLess(calls.index('probe-stop'), calls.index('fallback-config'))
        self.assertTrue(self.state()['fallback'])

    def test_unapproved_layer_fallback_stops_without_recreating_containers(self):
        self.save(fail_probe=True)
        self.start(success=False)
        self.assertNotIn('fallback-config', self.state()['calls'])
        self.assertNotIn('report', self.state()['calls'])

    def test_uncertain_package_result_stops_downstream_and_native_resume_reuses_root(self):
        self.save(lose='packages')
        root = self.start(success=False)
        self.assertNotIn('readiness', self.state()['calls'])
        self.cli('workflow', 'resume', 'operation-fleet-docker-migrate', '--run', root, '--from', 'packages')
        self.assertEqual(self.state()['packages'], 1)
        self.assertIn('report', self.state()['calls'])


if __name__ == '__main__':
    unittest.main()
