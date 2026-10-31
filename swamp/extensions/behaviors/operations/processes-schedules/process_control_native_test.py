"""Run the Process control workflow through real native binding/recovery semantics."""
import control_native_test as schedule
import json
from pathlib import Path

class ProcessNative(schedule.ScheduleNative):
    def setUp(self):
        super().setUp()
        module = self.repo/'extensions/behaviors/operations/processes-schedules/process-control.ts'
        module.write_text('import {http, action} from "./process-control_native_fixture.ts";\n'+module.read_text().replace('export const behavior = processControl();', 'export const behavior = processControl(() => http, action);'))
        source = schedule.ROOT/'workflows/workflow-operation-process-control.yaml'
        text = '\n'.join(line for line in source.read_text().splitlines() if not line.lstrip().startswith(('labels:', 'target:')))+'\n'
        (self.repo/'workflows'/source.name).write_text(text)
        self.state['operation']['kind'] = 'process.stop'
        self.state['operation']['input'] = {'process_id': 7, 'agent_sha256': 'a'*64, 'runtime': 'systemd', 'name': 'orbit-process-7-proof.service', 'on_demand': False, 'vite': None}
        self.save()

    def run_workflow(self, success=True):
        result = self.cli('workflow', 'run', 'operation-process-control', '--input', 'operation_id=17', '--input', 'node_id=9', '--input', 'kind=process.stop', success=success)
        self.run_id = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def cli(self, *args, success=True):
        return super().cli(*(arg.replace('operation-schedule-control', 'operation-process-control') for arg in args), success=success)
