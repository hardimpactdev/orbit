"""Run the real Schedule parent against a native child with a fixed refusal boundary."""
import json
import unittest
from pathlib import Path

import yaml
from native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[1]


class ScheduleInstanceNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        parent = yaml.safe_load((ROOT / 'workflows/workflow-operation-schedule-install-instance-definitions.yaml').read_text())
        for job in parent['jobs']:
            job.pop('labels', None)
            for step in job['steps']:
                step.pop('labels', None)
        (self.repo / 'workflows/workflow-operation-schedule-install-instance-definitions.yaml').write_text(yaml.safe_dump(parent, sort_keys=False))
        child = {
            'id': 'fc7e4f67-a368-44c6-8bc1-66f3acf2fe7a', 'name': 'operation-schedule-create',
            'inputs': {'type': 'object', 'properties': {'intent': {'type': 'object'}}},
            'jobs': [{'name': 'schedule', 'steps': [{'name': 'Install the member',
                'task': {'type': 'assert', 'expr': 'inputs.intent.accepted', 'message': 'fixture member refused'}}]}],
        }
        (self.repo / 'workflows/workflow-operation-schedule-create.yaml').write_text(yaml.safe_dump(child, sort_keys=False))

    def run_parent(self, accepted):
        members = [{'id': f'0ab86033-94e3-42de-8b46-57c1d8bdb00{i}', 'intent': {'accepted': value}}
                   for i, value in enumerate(accepted)]
        self.cli('workflow', 'validate', 'operation-schedule-install-instance-definitions')
        reply = self.cli('workflow', 'run', 'operation-schedule-install-instance-definitions', '--input', json.dumps({
            'operation_id': 1, 'node_id': 209, 'kind': 'schedule.install_instance_definitions',
            'intent': {'instance_id': 42, 'members': members}, 'execution_secrets': {},
        }), success=all(accepted))
        run_id = json.JSONDecoder().raw_decode(reply.stdout.lstrip())[0]['id']
        return json.loads(self.cli('workflow', 'history', 'get', run_id).stdout)

    def test_empty_and_two_member_plans_finish_without_a_reporting_shell(self):
        for accepted in ([], [True, True]):
            history = self.run_parent(accepted)
            steps = {step['name']: step for job in history['jobs'] for step in job['steps']}
            self.assertEqual(history['status'], 'succeeded')
            self.assertEqual(steps['Check the members']['status'], 'succeeded')
            self.assertNotIn('result', steps)

    def test_failed_member_skips_the_end_check(self):
        history = self.run_parent([True, False])
        steps = {step['name']: step for job in history['jobs'] for step in job['steps']}
        self.assertEqual(history['status'], 'failed')
        self.assertEqual(steps['Check the members']['status'], 'skipped')


if __name__ == '__main__':
    unittest.main()
