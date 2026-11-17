"""Exercise the Actions validator, including invalid context regression cases."""
import subprocess
import shutil
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import check_ci_workflow as workflow

ROOT = Path(__file__).resolve().parents[1]


class WorkflowValidationTest(unittest.TestCase):
    def lint(self, *paths, root=ROOT):
        return subprocess.run([sys.executable, str(root / 'tools/check_ci_workflow.py'), *map(str, paths)],
                              capture_output=True, text=True, timeout=90, check=False)

    def test_all_tracked_workflows_are_valid(self):
        result = self.lint()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_default_checks_other_tracked_workflows_but_not_untracked_files(self):
        with tempfile.TemporaryDirectory() as scratch:
            root = Path(scratch)
            (root / 'tools').mkdir()
            shutil.copyfile(ROOT / 'tools/check_ci_workflow.py', root / 'tools/check_ci_workflow.py')
            workflows = root / '.github/workflows'
            workflows.mkdir(parents=True)
            shutil.copyfile(ROOT / '.github/actionlint.yaml', root / '.github/actionlint.yaml')
            valid = 'name: fixture\non: push\njobs:\n  test:\n    runs-on: ubuntu-26.04\n    steps:\n      - run: echo ok\n'
            invalid = valid.replace('echo ok', 'echo "${{ nonexistent.value }}"')
            (workflows / 'ci.yml').write_text(valid)
            (workflows / 'untracked.yml').write_text(invalid)
            subprocess.run(['git', 'init', '-q', str(root)], check=True)
            for extension in ('yml', 'yaml'):
                with self.subTest(extension=extension):
                    other = workflows / f'release.{extension}'
                    other.write_text(invalid)
                    subprocess.run(['git', 'add', '.github/workflows/ci.yml', str(other)], cwd=root, check=True)
                    result = self.lint(root=root)
                    self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
                    self.assertIn(other.name, result.stdout + result.stderr)
                    other.write_text(valid)
                    result = self.lint(root=root)
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
