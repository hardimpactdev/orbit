"""Execute the real timer DAG with resource effects replaced by typed fixture states."""
import json
import shutil
import unittest
from pathlib import Path

import yaml
from native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[1]


class ScheduleCreateNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        target = self.repo / 'extensions/behaviors/operations'
        for folder, names in {'schedule-run': ['assets.ts'], 'processes-schedules': ['contract.ts', 'unit-templates.json']}.items():
            (target / folder).mkdir(parents=True)
            for name in names:
                shutil.copy(ROOT / 'extensions/behaviors/operations' / folder / name, target / folder / name)
        (target / 'resource_fixture.ts').write_text('''import {z} from "npm:zod@4.4.3";
export const behavior={type:"@fixture/schedule-resource",version:"2026.11.03.1",globalArguments:z.record(z.string(),z.unknown()),resources:{state:{schema:z.object({status:z.string()}),lifetime:"1d",garbageCollection:10}},methods:Object.fromEntries(["apply","check"].map(method=>[method,{description:"Fixture resource boundary",arguments:z.object({}),execute:async(_,ctx)=>{const drift=Deno.env.get("ORBIT_SCHEDULE_DRIFT");const bad=method==="check"&&((drift==="file"&&ctx.globalArgs.path)||(drift==="timer"&&ctx.globalArgs.service));return {dataHandles:[await ctx.writeResource("state","state",{status:bad?"non_compliant":method==="check"?"compliant":"applied"})]};}}]))};''')
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-schedule-create.yaml').read_text())
        for job in workflow['jobs']:
            job.pop('labels', None)
            for step in job['steps']:
                step.pop('labels', None)
                task = step['task']
                if task.get('behaviorType', '').startswith('@swamp/cfgmgmt/'):
                    task['behaviorType'] = '@fixture/schedule-resource'
        (self.repo / 'workflows/workflow-operation-schedule-create.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.inputs = {'operation_id': 1, 'node_id': 211, 'kind': 'schedule.create', 'execution_secrets': {},
            'intent': {'cascade': False, 'schedule': {'id': '54df47ef-1847-4798-8c56-47fa09df8b57', 'user': 'orbit', 'group': 'orbit',
                'home': '/home/orbit', 'working_directory': '/home/orbit', 'shell': '/bin/bash', 'login_shell': False,
                'command_hash': 'a' * 64, 'calendar': 'daily', 'timeout_seconds': 60, 'desired': 'disabled'},
                'ssh_execution': {'identity_file': '/public/key', 'target': {'host': '192.0.2.3', 'user': 'orbit', 'port': 22}}}}

    def run_timer(self, drift=''):
        self.native_env['ORBIT_SCHEDULE_DRIFT'] = drift
        self.cli('workflow', 'validate', 'operation-schedule-create')
        reply = self.cli('workflow', 'run', 'operation-schedule-create', '--input', json.dumps(self.inputs), success=not drift)
        run_id = json.JSONDecoder().raw_decode(reply.stdout.lstrip())[0]['id']
        return json.loads(self.cli('workflow', 'history', 'get', run_id).stdout)

    def test_compliant_resources_complete_without_a_reporting_shell(self):
        history = self.run_timer()
        steps = {step['name']: step for job in history['jobs'] for step in job['steps']}
        self.assertEqual(history['status'], 'succeeded')
        self.assertEqual(steps['Check the installation']['status'], 'succeeded')
        self.assertEqual(len([name for name in steps if name.startswith('check_files-')]), 4)
        self.assertNotIn('result', steps)

    def test_noncompliant_file_or_timer_fails_the_native_end_check(self):
        for drift in ['file', 'timer']:
            history = self.run_timer(drift)
            steps = {step['name']: step for job in history['jobs'] for step in job['steps']}
            self.assertEqual(history['status'], 'failed')
            self.assertEqual(steps['Check the installation']['status'], 'failed')


if __name__ == '__main__':
    unittest.main()
