"""The real-Docker guard: skip outside a disposable host, and run cleanups when `timeout` sends SIGTERM.

Delete with the last Python real-Docker test once it runs on the Deno harness."""
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from native_harness import ROOT

PROBE = '''import pathlib, sys, time, unittest
from native_harness import require_disposable_docker
class Probe(unittest.TestCase):
    def test_probe(self):
        require_disposable_docker(self)
        self.addCleanup(lambda: pathlib.Path(sys.argv[1]).write_text('removed'))
        time.sleep(30)
unittest.main(argv=[sys.argv[0]])
'''


class RequireDisposableDocker(unittest.TestCase):
    def run_probe(self, disposable):
        with tempfile.TemporaryDirectory() as temp:
            probe, marker = Path(temp) / 'probe.py', Path(temp) / 'cleaned'
            probe.write_text(PROBE)
            env = dict(os.environ, PYTHONPATH=str(ROOT / 'tests'), ORBIT_DISPOSABLE_DOCKER='1' if disposable else '0')
            result = subprocess.run(['timeout', '2', sys.executable, str(probe), str(marker)], env=env, capture_output=True, text=True, check=False)
            return result, marker.exists()

    def test_real_docker_skips_outside_a_disposable_host(self):
        result, cleaned = self.run_probe(disposable=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('skipped=1', result.stderr)
        self.assertFalse(cleaned)

    def test_sigterm_still_runs_real_docker_cleanups(self):
        result, cleaned = self.run_probe(disposable=True)
        self.assertEqual(result.returncode, 124)
        self.assertTrue(cleaned, result.stderr)


if __name__ == '__main__':
    unittest.main()
