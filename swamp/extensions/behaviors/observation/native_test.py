"""Validate native Doctor and read-only observation at their real placement."""
import json
import shutil
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class ObservationNativeTest(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('observation')
        dest = self.repo / '.swamp/pulled-extensions/@swamp/ssh'
        dest.parent.mkdir(parents=True)
        shutil.copytree(ROOT / '.swamp/pulled-extensions/@swamp/ssh', dest)
        index = self.repo / 'extensions/models/upstream_extensions.json'
        index.parent.mkdir(parents=True)
        index.write_text(json.dumps({'@swamp/ssh': json.loads((ROOT / 'extensions/models/upstream_extensions.json').read_text())['@swamp/ssh']}))
        for name in ('doctor-inspect','observation-maintain'):
            path = ROOT / f'workflows/workflow-operation-{name}.yaml'
            shutil.copy(path, self.repo / 'workflows' / path.name)

    def test_native_doctor_and_readonly_observation_validate_with_their_real_placement(self):
        for name in ('operation-doctor-inspect', 'operation-observation-maintain'):
            self.cli('workflow', 'validate', name)


if __name__ == '__main__':
    unittest.main()
