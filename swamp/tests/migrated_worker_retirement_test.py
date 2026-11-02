"""Migrated family source must never select an effect-Node worker."""
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
WORKFLOWS = (
    'operation-schedule-create', 'operation-schedule-control', 'operation-schedule-run',
    'operation-schedule-destroy', 'operation-database-read', 'operation-vault-sync',
    'operation-conn-pairing-revoke', 'operation-route-update', 'operation-task-publish',
)


def steps(value, inherited=None):
    inherited = inherited or {}
    if not isinstance(value, dict):
        return
    current = {**inherited, **{key: value[key] for key in ('target', 'labels') if key in value}}
    if 'task' in value:
        yield current, value['task']
    for key in ('jobs', 'steps'):
        for child in value.get(key, []):
            yield from steps(child, current)


class MigratedWorkerRetirement(unittest.TestCase):
    def test_all_migrated_effect_steps_run_on209_without_worker_enrollment(self):
        workflows = {}
        for path in (ROOT / 'workflows').glob('*.yaml'):
            workflow = yaml.safe_load(path.read_text())
            workflows[workflow['name']] = path
            workflows[workflow['id']] = path
        pending = list(WORKFLOWS)
        visited = set()
        while pending:
            path = workflows[pending.pop()]
            if path in visited:
                continue
            visited.add(path)
            with self.subTest(workflow=path.name):
                source = path.read_text()
                workflow = yaml.safe_load(source)
                self.assertNotIn('node-worker-enroll', source)
                self.assertNotIn('node-proxy-workers', source)
                self.assertNotIn('worker_credentials', source)
                self.assertNotIn('enrollment_token', source)
                placed_steps = list(steps(workflow))
                self.assertTrue(placed_steps)
                for placement, task in placed_steps:
                    self.assertNotIn('target', placement)
                    self.assertEqual(placement.get('labels'), {'role': 'swamp'})
                    if task.get('type') == 'workflow':
                        pending.append(task['workflowIdOrName'])

    def test_effect_node_expression_is_detected_in_a_nested_scope(self):
        workflow = {'labels': {'role': 'swamp'}, 'jobs': [{'steps': [
            {'labels': {'node': '${{ string(inputs.node_id) }}'}, 'task': {'type': 'assert'}}]}]}
        self.assertNotEqual(next(steps(workflow))[0]['labels'], {'role': 'swamp'})

    def test_retired_issuer_workflows_and_proxy_behavior_are_absent(self):
        for name in ('node-proxy-workers-setup', 'node-proxy-preflight', 'node-worker-enroll', 'node-worker-rotation'):
            self.assertFalse((ROOT / 'workflows' / f'workflow-{name}.yaml').exists())
        for name in ('proxy.ts', 'proxy-install.ts', 'proxy-runtime.ts', 'rotation.ts'):
            self.assertFalse((ROOT / 'extensions/behaviors/node-worker' / name).exists())


if __name__ == '__main__':
    unittest.main()
