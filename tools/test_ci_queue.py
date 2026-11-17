"""Queue evidence separates execution pools and never turns missing timings into zero waits."""
from datetime import datetime, timedelta, timezone
import io
import json
import os
from pathlib import Path
import runpy
import subprocess
import tempfile
import unittest
from unittest.mock import patch

import yaml

ROOT = Path(__file__).resolve().parents[1]


def job(index, created=0, started=10, completed=30, **values):
    def stamp(seconds):
        return (datetime(2026, 11, 17, tzinfo=timezone.utc) + timedelta(seconds=seconds)).isoformat()
    return dict(name=f'Fixtures ({index:02d})', created_at=stamp(created), started_at=stamp(started),
                completed_at=stamp(completed), status='completed', conclusion='success',
                labels=['self-hosted', 'hardimpact', 'orbit-shard'], runner_name=f'ci-sabre-{index}') | values


class QueueReportTest(unittest.TestCase):
    def report(self, jobs, slots=10):
        return runpy.run_path(str(ROOT / 'bin/ci-queue'))['queue_summary'](jobs, slots)

    def test_nearest_rank_timings_separate_pools_and_deduplicate_runner_names(self):
        jobs = [job(i, started=i * 10, completed=i * 10 + i) for i in range(1, 12)]
        jobs[-1]['runner_name'] = jobs[0]['runner_name']
        jobs += [job(20, name='Self-hosted fixtures (01)', started=200, completed=350),
                 job(21, labels=['ubuntu-26.04'], runner_name='GitHub Actions 1', started=2, completed=5),
                 job(22, name='Gateway tests', started=900, completed=1900)]
        report = self.report(jobs)
        self.assertIn('| Ordinary shared | 11 | 60 | 100 | 110 | 6 | 10 | 11 |', report)
        self.assertIn('| Tool-dependent shared | 1 | 200 | 200 | 200 | 150 | 150 | 150 |', report)
        self.assertIn('| GitHub-hosted | 1 | 2 | 2 | 2 | 3 | 3 | 3 |', report)
        self.assertIn('Distinct shared runners: 11', report)
        self.assertIn('Peak running: 1 / 10 model slots; peak queued: 12', report)
        # The denominator comes from the capacity model, not runner names in this run.
        self.assertIn('1 / 7 model slots', self.report(jobs, slots=7))

    def test_skips_cancellations_missing_and_copied_rerun_timings_are_not_zero_samples(self):
        report = self.report([
            job(1, started=70, completed=110, conclusion='failure'),
            job(2, started=80, completed=120, conclusion='cancelled'),
            job(3, conclusion='skipped', runner_name=None),
            job(4, conclusion='cancelled', runner_name=None),
            job(5, status='in_progress', completed_at=None),
            job(6, created=100, started=20, completed=50),
            job(7, completed_at=None),
        ])
        self.assertIn('| Ordinary shared | 2 | 70 | 80 | 80 | 40 | 40 | 40 |', report)
        self.assertIn('Excluded fixture records: skipped 1; unassigned 1; unfinished 2; invalid timestamps 1', report)
        empty = self.report([])
        self.assertIn('| Ordinary shared | 0 | n/a | n/a | n/a | n/a | n/a | n/a |', empty)
        self.assertIn('Peak running: 0 / 10 model slots; peak queued: 0', empty)

    def test_peaks_use_half_open_intervals_and_ignore_zero_length_queues(self):
        report = self.report([job(1, started=10, completed=20), job(2, created=10, started=20, completed=30),
                              job(3, created=30, started=30, completed=40)])
        self.assertIn('Peak running: 1 / 10 model slots; peak queued: 1', report)

    def test_cli_reads_paginated_attempt_evidence_and_appends_the_same_report(self):
        def api(argv, **kwargs):
            if argv[-1] == 'repos/hardimpactdev/orbit/actions/runs/42':
                self.assertFalse(attempt_args or environment)
                return json.dumps({'run_attempt': 2})
            if argv[-1] == 'repos/hardimpactdev/orbit/actions/runs/42/attempts/2/jobs?per_page=100' and '--paginate' in argv and '--slurp' in argv:
                return json.dumps([{'jobs': [job(1)]}, {'jobs': [job(2)]}])
            raise AssertionError(argv)
        with tempfile.TemporaryDirectory() as directory:
            summary = Path(directory) / 'summary.md'
            for attempt_args, environment in (([], {}), (['--attempt', '2'], {}), ([], {'GITHUB_RUN_ATTEMPT': '2'})):
                with self.subTest(arguments=attempt_args, environment=environment):
                    summary.write_text('Existing summary\n')
                    output = io.StringIO()
                    with patch.dict(os.environ, {'GITHUB_STEP_SUMMARY': str(summary), **environment}, clear=True), \
                         patch('sys.argv', ['ci-queue', '--repo', 'hardimpactdev/orbit', '--run-id', '42', *attempt_args]), \
                         patch('subprocess.check_output', side_effect=api), patch('sys.stdout', output):
                        with self.assertRaises(SystemExit) as exited:
                            runpy.run_path(str(ROOT / 'bin/ci-queue'), run_name='__main__')
                        self.assertEqual(exited.exception.code, 0)
                    self.assertEqual(summary.read_text(), 'Existing summary\n' + output.getvalue())
                    self.assertIn('Run 42, attempt 2', output.getvalue())
                    self.assertIn('| Ordinary shared | 2 |', output.getvalue())

    def test_api_timeout_is_visible_in_stdout_and_summary_with_nonzero_exit(self):
        with tempfile.TemporaryDirectory() as directory:
            summary = Path(directory) / 'summary.md'
            output = io.StringIO()
            with patch.dict(os.environ, {'GITHUB_STEP_SUMMARY': str(summary)}, clear=True), \
                 patch('sys.argv', ['ci-queue', '--run-id', '42', '--attempt', '1']), \
                 patch('subprocess.check_output', side_effect=subprocess.TimeoutExpired(['gh', 'api'], 20)), \
                 patch('sys.stdout', output):
                with self.assertRaises(SystemExit) as exited:
                    runpy.run_path(str(ROOT / 'bin/ci-queue'), run_name='__main__')
                self.assertEqual(exited.exception.code, 1)
            self.assertIn('Fixture queue pressure unavailable', output.getvalue())
            self.assertEqual(summary.read_text(), output.getvalue())

    def test_pr_and_main_gates_publish_advisory_evidence_even_after_failures(self):
        jobs = yaml.safe_load((ROOT / '.github/workflows/ci.yml').read_text())['jobs']
        for name in ('required', 'main-required'):
            with self.subTest(job=name):
                steps = [step for step in jobs[name]['steps'] if step.get('run') == 'python3 bin/ci-queue']
                self.assertEqual(len(steps), 1)
                self.assertEqual(steps[0]['if'], 'always()')
                self.assertTrue(steps[0]['continue-on-error'])
                self.assertEqual(steps[0]['env']['GH_TOKEN'], '${{ github.token }}')
                self.assertEqual(jobs[name]['permissions']['actions'], 'read')


if __name__ == '__main__':
    unittest.main()
