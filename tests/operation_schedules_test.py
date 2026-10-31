"""Native parents are submitted directly; Gateway discovery schedules are retired."""
from pathlib import Path
import unittest
import yaml

ROOT = Path(__file__).resolve().parents[1]

class OperationSchedulesTest(unittest.TestCase):
    def test_operation_workflows_never_schedule_gateway_claims(self):
        for path in ROOT.glob('workflows/workflow-operation-*.yaml'):
            workflow = yaml.safe_load(path.read_text())
            with self.subTest(workflow=workflow['name']):
                self.assertFalse(workflow.get('trigger', {}).get('schedule'))
                self.assertNotIn('@nckrtl/operation-discovery', path.read_text())
                self.assertNotIn('self.work.slot', path.read_text())

    def test_no_gateway_discovery_or_slot_models_can_claim_work(self):
        self.assertFalse(list(ROOT.glob('models/@nckrtl/operation-discovery/*.yaml')))
        self.assertFalse(list(ROOT.glob('models/@nckrtl/operation-*/*-slot-*.yaml')))

    def test_native_retention_keeps_history_and_current_data(self):
        marker = yaml.safe_load((ROOT / '.swamp.yaml').read_text())
        self.assertTrue(marker['autoGc'])
        self.assertEqual(marker['garbageCollection'], {'automatic': True, 'retention': '30d', 'workflowRuns': '1d', 'outputs': '1d'})

if __name__ == '__main__':
    unittest.main()
