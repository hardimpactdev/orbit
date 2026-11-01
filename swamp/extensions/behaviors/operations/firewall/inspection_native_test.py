"""The real native inspection root records a complete public snapshot or a bounded refusal."""
import json
import shutil
import sys
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class FirewallInspectionNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operations/_lib')
        modules = self.repo / 'extensions/behaviors'
        for folder, names in {
            'operations/firewall': ['inspection.ts', 'inspection_native_fixture.ts'],
            'operations/routes': ['firewall-program.ts'],
            'operations/routes/mutation-command/scripts': ['firewall_snapshot.py'],
            'operations/tools': ['system_program.ts'],
            'observation': ['doctor_native_runtime.ts', 'doctor_contract.ts'],
        }.items():
            destination = modules / folder
            destination.mkdir(parents=True, exist_ok=True)
            for name in names:
                shutil.copy(ROOT / 'extensions/behaviors' / folder / name, destination)
        module = modules / 'operations/firewall/inspection.ts'
        module.write_text('import {fixtureHttp,fixtureLocal} from "./inspection_native_fixture.ts";\n' + module.read_text().replace(
            'const inspection = firewallInspection();', 'const inspection = firewallInspection(() => fixtureHttp,fixtureLocal);'))
        source = yaml.safe_load((ROOT / 'workflows/workflow-operation-firewall-inspect.yaml').read_text())
        for job in source['jobs']:
            self.assertEqual(job.pop('target'), '${{ "orbit-node-" + string(inputs.node_id) }}')
            self.assertEqual(job.pop('labels'), {'node': '${{ string(inputs.node_id) }}'})
        (self.repo / 'workflows/workflow-operation-firewall-inspect.yaml').write_text(yaml.safe_dump(source, sort_keys=False))
        self.shape = dict(comment='orbit:rule-7',action='allow',direction='in',source='192.0.2.1',destination='any',port='443',protocol='tcp',in_interface=None,out_interface=None,family='v4')
        self.state = dict(calls=[],reads=0,snapshot=dict(backend='active',live=[self.shape]),operation=dict(
            id=17,kind='firewall.inspect',node_id=9,status='queued',cancel_requested=False,result=None,input=dict(node_id=9)))

    def run_workflow(self, success=True):
        (self.repo / 'native-firewall-inspection.json').write_text(json.dumps(self.state))
        self.cli('workflow','validate','operation-firewall-inspect')
        self.cli('workflow','run','operation-firewall-inspect','--input','operation_id=17',
                 '--input','node_id=9','--input','kind=firewall.inspect',success=success)
        return json.loads((self.repo / 'native-firewall-inspection.json').read_text())

    def test_inspection_records_complete_snapshot_without_executor_or_agent_calls(self):
        state = self.run_workflow()
        self.assertEqual(state['operation']['status'],'succeeded')
        self.assertEqual(state['operation']['result']['backend'],'active')
        self.assertEqual(state['operation']['result']['live'],[self.shape])
        self.assertEqual(set(state['operation']['result']),{'backend','live','observed_at'})
        self.assertEqual(state['reads'],1)
        self.assertTrue(all(path.startswith('/api/v1/direct-operations/17') for path in state['calls']))
        self.assertFalse(any('primitive' in path or 'executor' in path for path in state['calls']))

    def test_oversized_inventory_fails_without_partial_snapshot(self):
        self.state['snapshot']['live'] = [self.shape] * 513
        state = self.run_workflow(success=False)
        self.assertEqual(state['operation']['status'],'failed')
        self.assertEqual(state['operation']['result'],{'error_code':'firewall.inspection_too_large'})
        self.assertEqual(state['reads'],1)

    def test_cancellation_does_not_read_firewall(self):
        self.state['operation']['cancel_requested'] = True
        state = self.run_workflow()
        self.assertEqual(state['operation']['status'],'cancelled')
        self.assertEqual(state['reads'],0)
        self.assertEqual(state['operation']['result'],{})

    def test_foreign_admitted_node_fails_before_read(self):
        self.state['operation']['input']['node_id'] = 3
        state = self.run_workflow(success=False)
        # The native failure owns settlement; pre-effect exceptions send no fake terminal callback.
        self.assertEqual(state['operation']['status'],'running')
        self.assertFalse(any(path.endswith('/failure') for path in state['calls']))
        self.assertEqual(state['reads'],0)


if __name__ == '__main__':
    unittest.main()
