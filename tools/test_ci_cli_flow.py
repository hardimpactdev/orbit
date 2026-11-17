"""The design flow runner discovers fixtures and refuses an empty replay suite."""
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class CliFlowTest(unittest.TestCase):
    def test_list_finds_the_recorded_design_flows(self):
        result = subprocess.run([sys.executable, str(ROOT / 'bin/cli-flow'), '--list'],
                                capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual({line.split()[0] for line in result.stdout.splitlines()},
                         {'node-add', 'node-add-package-failure'})

    def test_all_refuses_missing_or_empty_flow_directory(self):
        with tempfile.TemporaryDirectory() as scratch:
            root = Path(scratch)
            (root / 'bin').mkdir()
            shutil.copyfile(ROOT / 'bin/cli-flow', root / 'bin/cli-flow')
            for directory_exists in (False, True):
                with self.subTest(directory_exists=directory_exists):
                    if directory_exists:
                        (root / 'apps/cli/design/Flows').mkdir(parents=True)
                    result = subprocess.run([sys.executable, str(root / 'bin/cli-flow'), '--all'],
                                            capture_output=True, text=True, check=False)
                    self.assertNotEqual(result.returncode, 0)
                    self.assertIn('No flows found', result.stderr)

if __name__ == '__main__':
    unittest.main()
