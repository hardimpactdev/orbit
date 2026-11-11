"""Exercise the Actions validator, including invalid context regression cases."""
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import check_ci_workflow as workflow

ROOT = Path(__file__).resolve().parents[1]


class WorkflowValidationTest(unittest.TestCase):
    def lint(self, path):
        return subprocess.run([sys.executable, str(ROOT / 'tools/check_ci_workflow.py'), str(path)],
                              capture_output=True, text=True, timeout=90, check=False)

    def test_complete_ci_workflow_is_valid(self):
        result = self.lint(ROOT / '.github/workflows/ci.yml')
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_runner_context_is_rejected_in_workflow_and_job_env(self):
        for indentation in ('', '    '):
            with self.subTest(scope='workflow' if not indentation else 'job'), \
                    tempfile.TemporaryDirectory() as scratch:
                source = 'name: fixture\non: push\n'
                env = indentation + 'env:\n' + indentation + '  DENO_DIR: ${{ runner.temp }}/deno\n'
                if not indentation:
                    source += env
                source += 'jobs:\n  test:\n    runs-on: ubuntu-26.04\n'
                if indentation:
                    source += env
                source += '    steps:\n      - run: echo ok\n'
                path = Path(scratch) / 'invalid.yml'
                path.write_text(source)
                result = self.lint(path)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn('runner', result.stdout + result.stderr)
                self.assertIn('not allowed', result.stdout + result.stderr)

    def test_corrupt_cached_actionlint_archive_is_rejected(self):
        with tempfile.TemporaryDirectory() as scratch, \
                patch('urllib.request.urlretrieve') as download:
            cache = Path(scratch)
            (cache / workflow.ARCHIVE).write_bytes(b'corrupt')
            with self.assertRaisesRegex(ValueError, 'actionlint checksum mismatch'):
                workflow.verified_archive(cache)
            download.assert_not_called()


if __name__ == '__main__':
    unittest.main()
