"""Failure diagnostics from the shared Reverb phase subprocess boundary."""

import subprocess
import sys
import unittest
from pathlib import Path


class Diagnostics(unittest.TestCase):
    def test_child_stderr_survives_while_stdout_remains_private(self):
        path = Path(__file__).parent / "runtime_scripts/phase_common.py"
        program = """
import runpy,sys,types
for name in ['role_file','websocket_cleanup','websocket_link','websocket_policy']:
    sys.modules[name]=types.ModuleType(name)
phase=runpy.run_path(sys.argv[1])
phase['run']([sys.executable,'-c',
    'import sys;print("private-output");print("composer-scope-refused",file=sys.stderr);sys.exit(3)'])
"""
        result = subprocess.run(
            [sys.executable, "-I", "-c", program, str(path.resolve())],
            capture_output=True,
            text=True,
            check=False,
        )
        self.assertNotEqual(0, result.returncode)
        self.assertIn("composer-scope-refused", result.stderr)
        self.assertNotIn("private-output", result.stdout)


if __name__ == "__main__":
    unittest.main()
