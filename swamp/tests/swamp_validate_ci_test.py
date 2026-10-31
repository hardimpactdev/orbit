"""The auth-free callable reports blockers without waiving real failures."""
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class ValidateCI(unittest.TestCase):
    def test_auth_free_workflows_and_identical_callable(self):
        callable_source = (ROOT / '.github/workflows/swamp-shape.yml').read_text()
        self.assertEqual(callable_source, (ROOT.parent / '.github/workflows/swamp-shape.yml').read_text())
        for path in [ROOT / '.github/workflows/swamp-shape.yml', ROOT / '.github/workflows/swamp-shape-ci.yml']:
            source = path.read_text()
            for forbidden in ['SWAMP_API_KEY', 'SWAMP_SIGNIN_TOKEN', 'secrets: inherit']:
                self.assertNotIn(forbidden, source)
        self.assertIn('default: false', callable_source)

    def test_only_explicit_initial_import_can_report_auth_unavailable(self):
        source = (ROOT / '.github/workflows/swamp-shape.yml').read_text()
        script = source.split('          report="$RUNNER_TEMP/workflow-validation.json"', 1)[1].split('      - name: Check placement', 1)[0]
        script = 'report="$RUNNER_TEMP/workflow-validation.json"\n' + '\n'.join(line[10:] if line.startswith('          ') else line for line in script.splitlines())
        with tempfile.TemporaryDirectory() as scratch:
            report = Path(scratch) / 'workflow-validation.json'
            env = {**os.environ, 'RUNNER_TEMP': scratch, 'GITHUB_STEP_SUMMARY': str(Path(scratch) / 'summary'),
                   'SHAPE_NATIVE_OUTCOME': 'failure', 'SHAPE_IMPORT_AUTH_EXCEPTION': 'true',
                   'GITHUB_REPOSITORY': 'hardimpactdev/orbit', 'GITHUB_EVENT_NAME': 'pull_request',
                   'SHAPE_PR_NUMBER': '2002', 'GITHUB_REF': 'refs/pull/2002/merge'}
            def run(**updates):
                return subprocess.run(['bash', '-e', '-c', script], env={**env, **updates}, text=True, capture_output=True)
            report.write_text(json.dumps({'status': 'auth_unavailable'}))
            self.assertEqual(run().returncode, 0)
            self.assertIn('SWAMP AUTH UNAVAILABLE', run().stdout)
            self.assertNotEqual(run(SHAPE_IMPORT_AUTH_EXCEPTION='false').returncode, 0)
            self.assertNotEqual(run(SHAPE_PR_NUMBER='2003').returncode, 0)
            self.assertNotEqual(run(GITHUB_REPOSITORY='other/orbit').returncode, 0)
            self.assertEqual(run(GITHUB_EVENT_NAME='push', GITHUB_REF='refs/heads/main').returncode, 0)
            self.assertNotEqual(run(GITHUB_EVENT_NAME='push', GITHUB_REF='refs/heads/other').returncode, 0)
            report.write_text(json.dumps({'status': 'validated'}))
            self.assertNotEqual(run().returncode, 0, 'Ordinary validation failure must never be waived')
            self.assertEqual(run(SHAPE_NATIVE_OUTCOME='success').returncode, 0)
            report.unlink()
            self.assertNotEqual(run().returncode, 0)


if __name__ == '__main__':
    unittest.main()
