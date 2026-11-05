"""The legacy `up` entrypoint runs under `python3 -I`, which drops the script directory from sys.path."""
import subprocess
import sys
import unittest
from pathlib import Path

UP = Path(__file__).resolve().parents[4] / 'tools/swamp-proof-guest/up.py'


class LegacyUpEntrypointTests(unittest.TestCase):
    def test_isolated_python_finds_the_fixture_module(self):
        # A source path that does not exist stops up at its first read, after the imports.
        completed = subprocess.run([sys.executable, '-I', str(UP), '/nonexistent-source'], capture_output=True, text=True, timeout=60, cwd='/')
        self.assertNotEqual(0, completed.returncode)
        self.assertNotIn('ModuleNotFoundError', completed.stderr)
        self.assertIn('FileNotFoundError', completed.stderr)


if __name__ == '__main__':
    unittest.main()
