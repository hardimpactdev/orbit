"""Native retained-timer enable checks consume actual resource compliance semantics."""
import json
import unittest

import yaml
import schedule_create_native_test as create


class ScheduleEnableNative(create.ScheduleCreateNative):
    test_compliant_resources_complete_without_a_reporting_shell = None
    test_noncompliant_file_or_timer_fails_the_native_end_check = None

    def setUp(self):
        super().setUp()
        workflow = yaml.safe_load((create.ROOT / 'workflows/workflow-operation-schedule-control.yaml').read_text())
        for job in workflow['jobs']:
            job.pop('labels', None)
            for step in job['steps']:
                step.pop('labels', None)
                if step['task'].get('behaviorType') == '@swamp/cfgmgmt/systemd':
                    step['task']['behaviorType'] = '@fixture/schedule-resource'
        (self.repo / 'workflows/workflow-operation-schedule-control.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.inputs['kind'] = 'schedule.enable'
        self.inputs['intent'].pop('cascade')
        self.inputs['intent']['schedule'] = {'id': self.inputs['intent']['schedule']['id']}

    def run_control(self, drift=''):
        self.native_env['ORBIT_SCHEDULE_DRIFT'] = drift
        self.cli('workflow', 'validate', 'operation-schedule-control')
        reply = self.cli('workflow', 'run', 'operation-schedule-control', '--input', json.dumps(self.inputs), success=not drift)
        run_id = json.JSONDecoder().raw_decode(reply.stdout.lstrip())[0]['id']
        return json.loads(self.cli('workflow', 'history', 'get', run_id).stdout)

    def test_enabled_timer_completes_from_its_resource_check(self):
        history = self.run_control()
        steps = {step['name']: step for job in history['jobs'] for step in job['steps']}
        self.assertEqual(history['status'], 'succeeded')
        self.assertEqual(steps['Check the timer']['status'], 'succeeded')
        self.assertNotIn('result', steps)

    def test_resource_noncompliance_fails_even_when_the_check_method_completed(self):
        history = self.run_control('timer')
        steps = {step['name']: step for job in history['jobs'] for step in job['steps']}
        self.assertEqual(steps['check_timer']['status'], 'succeeded')
        self.assertEqual(steps['Check the timer']['status'], 'failed')
        self.assertEqual(history['status'], 'failed')


if __name__ == '__main__':
    unittest.main()
