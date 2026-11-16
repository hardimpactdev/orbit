"""Run the ci-runner role's state script against a fake `incus` and check the textfile it writes."""
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'apps/gateway/resources/ci-runner/state.py'

# The fake answers `incus list` from FIXTURE_LIST and each `incus exec` from FIXTURE_EXEC[name], and logs every call.
INCUS = '''#!{python}
import json, os, sys
with open(os.environ['FIXTURE_LOG'], 'a') as log:
    log.write(json.dumps(sys.argv[1:]) + '\\n')
if os.environ.get('FIXTURE_FAIL'):
    sys.exit(1)
if sys.argv[1] == 'list':
    sys.stdout.write(os.environ['FIXTURE_LIST'])
    sys.exit(0)
name = sys.argv[sys.argv.index('--') - 1]
answer = json.loads(os.environ['FIXTURE_EXEC'])[name]
sys.stdout.write(answer['stdout'])
sys.exit(answer.get('code', 0))
'''


class CiRunnerStateTest(unittest.TestCase):
    def setUp(self):
        scratch = tempfile.TemporaryDirectory(prefix='ci-runner-state-test-')
        self.addCleanup(scratch.cleanup)
        self.root = Path(scratch.name)
        (self.root / 'bin').mkdir()
        incus = self.root / 'bin/incus'
        incus.write_text(INCUS.format(python=sys.executable))
        incus.chmod(incus.stat().st_mode | stat.S_IXUSR)
        self.output = self.root / 'textfile/orbit_ci_runners.prom'
        self.output.parent.mkdir()
        self.log = self.root / 'calls.jsonl'

    def run_script(self, listed='', inside=None, fail=False):
        env = dict(os.environ, PATH=str(self.root / 'bin'), FIXTURE_LOG=str(self.log), FIXTURE_LIST=listed,
                   FIXTURE_EXEC=json.dumps(inside or {}))
        if fail:
            env['FIXTURE_FAIL'] = '1'
        subprocess.run([sys.executable, '-I', str(SCRIPT), str(self.output)], env=env, check=True, timeout=30)

        return self.output.read_text()

    def calls(self):
        return [json.loads(line) for line in self.log.read_text().splitlines()]

    def test_writes_each_runner_with_its_state_service_version_and_busy_flag(self):
        text = self.run_script('ci-b,RUNNING\nci-a,RUNNING\nci-c,STOPPED\n', {
            'ci-a': {'stdout': 'version=bin.2.330.0\nservice=active\nbusy=1\n'},
            'ci-b': {'stdout': 'version=bin\nservice=failed\n'},
        })

        lines = [line for line in text.splitlines() if not line.startswith('#')]
        self.assertEqual(lines[:6], [
            'orbit_ci_runner_info{runner="ci-a",state="running",service="active",version="2.330.0"} 1',
            'orbit_ci_runner_info{runner="ci-b",state="running",service="failed",version=""} 1',
            'orbit_ci_runner_info{runner="ci-c",state="stopped",service="unknown",version=""} 1',
            'orbit_ci_runner_busy{runner="ci-a"} 1',
            'orbit_ci_runner_busy{runner="ci-b"} 0',
            'orbit_ci_runner_busy{runner="ci-c"} 0',
        ])
        self.assertRegex(lines[6], r'\Aorbit_ci_runners_observed_timestamp_seconds [0-9]+\Z')
        self.assertEqual(lines[7:], ['orbit_ci_runners_read_ok 1'])
        self.assertTrue(text.endswith('\n'))

        calls = self.calls()
        self.assertEqual(calls[0], ['list', '--project', 'orbit-ci', '--format=csv', '--columns=ns'])
        # One exec for each running runner only; the stopped one is never entered.
        self.assertEqual([call[4] for call in calls[1:]], ['ci-b', 'ci-a'])
        self.assertEqual(calls[1][:6], ['exec', '--project', 'orbit-ci', '--force-noninteractive', 'ci-b', '--'])
        self.assertEqual(calls[1][6:8], ['timeout', '3'])

    def test_a_failed_exec_or_unexpected_answer_reports_unknown(self):
        text = self.run_script('ci-a,RUNNING\nci-b,RUNNING\n', {
            'ci-a': {'stdout': 'service=active\n', 'code': 1},
            'ci-b': {'stdout': 'service=active"} 1\nversion=bin.2.330.0/../x\n'},
        })

        self.assertIn('orbit_ci_runner_info{runner="ci-a",state="running",service="unknown",version=""} 1', text)
        self.assertIn('orbit_ci_runner_info{runner="ci-b",state="running",service="unknown",version=""} 1', text)

    def test_skips_names_and_states_it_cannot_write_as_labels(self):
        text = self.run_script('ci-a,RUNNING\nbad"name,RUNNING\nci-b,UNKNOWN\n', {'ci-a': {'stdout': ''}})

        self.assertEqual([line for line in text.splitlines() if line.startswith('orbit_ci_runner_info')],
                         ['orbit_ci_runner_info{runner="ci-a",state="running",service="unknown",version=""} 1'])

    def test_reports_a_failed_incus_read_without_runners(self):
        text = self.run_script(fail=True)

        self.assertNotIn('orbit_ci_runner_info{', text)
        self.assertIn('orbit_ci_runners_read_ok 0\n', text)
        self.assertRegex(text, r'orbit_ci_runners_observed_timestamp_seconds [0-9]+\n')

    def test_replaces_the_file_and_leaves_no_temporary_file(self):
        self.output.write_text('old\n')
        self.run_script('ci-a,STOPPED\n')

        self.assertEqual(sorted(path.name for path in self.output.parent.iterdir()), ['orbit_ci_runners.prom'])
        self.assertEqual(stat.S_IMODE(self.output.stat().st_mode), 0o644)


if __name__ == '__main__':
    unittest.main()
