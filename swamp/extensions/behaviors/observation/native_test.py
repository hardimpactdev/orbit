"""Validate native Doctor at its real placement."""
import json
import shutil
import sys
import unittest
import yaml
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
        path = ROOT / 'workflows/workflow-operation-doctor-inspect.yaml'
        shutil.copy(path, self.repo / 'workflows' / path.name)

    def test_native_doctor_validates_with_its_real_placement(self):
        self.cli('workflow', 'validate', 'operation-doctor-inspect')


if __name__ == '__main__':
    unittest.main()
