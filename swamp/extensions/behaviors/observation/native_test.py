"""Validate native Doctor and its schedules without changing production placement."""
from pathlib import Path
import shutil
import sys
import unittest

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class ObservationNativeTest(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('observation', 'automatic-deploy', 'operations/_lib',
                            'operations/database', 'operations/tools')
        for path in ROOT.glob('workflows/workflow-observation-*.yaml'):
            shutil.copy(path, self.repo / 'workflows' / path.name)
        for name in ('doctor-inspect','observation-maintain'):
            path = ROOT / f'workflows/workflow-operation-{name}.yaml'
            shutil.copy(path, self.repo / 'workflows' / path.name)

    def test_native_doctor_and_cheap_schedules_validate_with_their_real_placement(self):
        for name in ('operation-doctor-inspect', 'operation-observation-maintain', 'observation-collection',
                     'observation-horizon', 'observation-maintenance',
                     'observation-retention'):
            self.cli('workflow', 'validate', name)


if __name__ == '__main__':
    unittest.main()
