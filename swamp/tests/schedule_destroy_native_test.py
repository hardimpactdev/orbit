"""Native removal checks fail on drift and refuse busy standalone copies before writes."""
import json
import unittest

import yaml
import schedule_create_native_test as create


class ScheduleDestroyNative(create.ScheduleCreateNative):
    test_compliant_resources_complete_without_a_reporting_shell = None
    test_noncompliant_file_or_timer_fails_the_native_end_check = None

    def setUp(self):
        super().setUp()
        fixture = self.repo / 'extensions/behaviors/operations/resource_fixture.ts'
        fixture.write_text('''import {z} from "npm:zod@4.4.3";
export const behavior={type:"@fixture/schedule-resource",version:"2026.11.03.1",globalArguments:z.record(z.string(),z.unknown()),resources:{state:{schema:z.object({status:z.string(),current:z.object({loaded:z.boolean()})}),lifetime:"1d",garbageCollection:10}},methods:Object.fromEntries(["apply","check","exec"].map(method=>[method,{description:"Resource boundary",arguments:z.record(z.string(),z.unknown()),execute:async(args,ctx)=>{const drift=Deno.env.get("ORBIT_SCHEDULE_DRIFT");if(method==="exec"&&args.okExitCodes&&drift==="busy")throw new Error("service active: exit 0 refused");const bad=method==="check"&&((drift==="file"&&ctx.globalArgs.path)||(drift==="timer"&&ctx.globalArgs.service));return {dataHandles:[await ctx.writeResource("state","state",{status:bad?"non_compliant":"compliant",current:{loaded:drift==="loaded"}})]};}}]))};''')
        workflow = yaml.safe_load((create.ROOT / 'workflows/workflow-operation-schedule-destroy.yaml').read_text())
        for job in workflow['jobs']:
            job.pop('labels', None)
            for step in job['steps']:
                step.pop('labels', None)
                if step['task']['type'] == 'behavior':
                    step['task']['behaviorType'] = '@fixture/schedule-resource'
        (self.repo / 'workflows/workflow-operation-schedule-destroy.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.inputs['intent']['ssh_execution']['target']['host_keys'] = ['ssh-ed25519 public-fixture-key']
        self.inputs['kind'] = 'schedule.destroy'
        self.inputs['intent']['schedule'] = {'id': self.inputs['intent']['schedule']['id']}

    def run_removal(self, drift='', cascade=False):
        self.native_env['ORBIT_SCHEDULE_DRIFT'] = drift
        self.inputs['intent']['cascade'] = cascade
        self.cli('workflow', 'validate', 'operation-schedule-destroy')
        reply = self.cli('workflow', 'run', 'operation-schedule-destroy', '--input', json.dumps(self.inputs), success=not drift or (drift == 'busy' and cascade))
        run_id = json.JSONDecoder().raw_decode(reply.stdout.lstrip())[0]['id']
        return json.loads(self.cli('workflow', 'history', 'get', run_id).stdout)

    def test_standalone_removal_refuses_a_busy_service_before_mutation(self):
        history = self.run_removal('busy')
        steps = {step['name']: step for job in history['jobs'] for step in job['steps']}
        self.assertEqual(steps['idle']['status'], 'failed')
        self.assertFalse(any(step['status'] == 'succeeded' for name, step in steps.items() if name != 'idle'))

    def test_standalone_and_cascade_finish_without_a_reporting_shell(self):
        for cascade in [False, True]:
            history = self.run_removal('busy' if cascade else '', cascade)
            steps = {step['name']: step for job in history['jobs'] for step in job['steps']}
            self.assertEqual(history['status'], 'succeeded')
            self.assertEqual(steps['Check the removal']['status'], 'succeeded')
            self.assertEqual(steps.get('idle', {}).get('status', 'skipped'), 'skipped' if cascade else 'succeeded')
            self.assertEqual(steps.get('stop_service', {}).get('status', 'skipped'), 'succeeded' if cascade else 'skipped')
            self.assertNotIn('result', steps)

    def test_file_timer_drift_or_loaded_unit_fails_the_end_assertion(self):
        for drift in ['file', 'timer', 'loaded']:
            history = self.run_removal(drift)
            steps = {step['name']: step for job in history['jobs'] for step in job['steps']}
            self.assertEqual(steps['check_timer']['status'], 'succeeded')
            self.assertEqual(steps['Check the removal']['status'], 'failed')


if __name__ == '__main__':
    unittest.main()
