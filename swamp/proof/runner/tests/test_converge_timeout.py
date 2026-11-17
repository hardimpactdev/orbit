"""Copy deadlines of the Node Operation commands and complete receipts when their Operation may outlive the CLI."""
import contextlib
import io
import json
import subprocess
import sys
import tempfile
import threading
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parent))
import fleet
import runner
import spec
import test_parallel
from test_parallel import BATCHES, load_text

HEADER = 'command: node:converge\nchart: node/converge\ntarget: app-dev\nparallel: 1\nsources: [gateway, app-prod]\nbaseline: []\n'


def case(name, setup=False, command='node:converge'):
    return f'''  - id: {name}
    batch: fresh-fleet
    covers: [main]
    summary: Converge a copy Node.
    {'setup' if setup else 'act'}:
      - cli: {{run: [{command}, "211"], expect: {{exit: 0}}}}
''' + ('    act: []\n' if setup else '') + '    expect: []\n'


def transport():
    return fleet.Fleet({role: {'instance': 'nested-' + role, 'node_id': ident}
                        for role, ident in [('gateway', 209), ('app-dev', 211), ('app-prod', 212)]})


class ConvergeTimeoutTest(unittest.TestCase):
    def test_copy_outer_and_nested_node_operations_get_600_but_vm_and_other_commands_keep_240(self):
        for shift in ('true', 'false'):
            calls = []

            def invoke(argv, **kwargs):
                calls.append((argv, kwargs['timeout']))
                return subprocess.CompletedProcess(argv, 0, shift if argv[-1] == 'shift' else '{}', '')

            with patch.object(fleet.subprocess, 'run', invoke):
                target = transport()
                for node in (None, 'app-prod'):
                    for command in ('node:converge', 'node:list', 'node:role:add'):
                        target.cli([command, '211'], node=node)
                        self.assertEqual(calls[-1][1], 600 if command != 'node:list' and shift == 'false' else 240)
                target.cli(['doctor'], timeout=40)
                self.assertEqual(calls[-1][1], 40)
            self.assertEqual(sum(argv[-1] == 'shift' for argv, _ in calls), 1)

    def test_unknown_checkout_mode_is_not_treated_as_a_copy_or_a_vm(self):
        with patch.object(fleet.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, '', '')):
            with self.assertRaisesRegex(fleet.FleetError, 'unknown fleet checkout shift'):
                transport().cli(['node:converge', '211'])

    def test_copy_wait_does_not_exhaust_setup_repeat_or_output_form_budgets(self):
        body = '''  - id: repeated
    batch: fresh-fleet
    covers: [main]
    summary: Setup then two converge stages, including both forms.
    setup:
      - cli: {run: [node:converge, "211"], expect: {exit: 0}}
    stages:
      - act:
          - cli: {run: [node:converge, "211"], forms: [json, human], expect: {exit: 0}}
        expect: []
      - act:
          - cli: {run: [node:converge, "211"], expect: {exit: 0, json: [{path: result.elapsed_seconds, at_most: 120}]}}
        expect: []
'''
        loaded = load_text(body, HEADER)
        for shift, product_seconds, expected, count in [('false', 120, 'passed', 4), ('false', 121, 'failed', 4), ('true', 120, 'failed', 1)]:
            clock, calls = [0.0], []

            def invoke(argv, **kwargs):
                if argv[-1] == 'shift':
                    return subprocess.CompletedProcess(argv, 0, shift, '')
                calls.append(argv)
                clock[0] += 500 if shift == 'false' else 200
                return subprocess.CompletedProcess(argv, 0, json.dumps({'result': {'elapsed_seconds': product_seconds}}), '')

            with tempfile.TemporaryDirectory() as directory, patch.object(fleet.subprocess, 'run', invoke), \
                    patch.object(runner.time, 'time', lambda: clock[0]), patch.object(runner.time, 'monotonic', lambda: clock[0]):
                result, _ = runner.execute(loaded.cases[0], transport(), loaded, directory)
            self.assertEqual(result['verdict'], expected, result)
            self.assertEqual(len(calls), count)
            if product_seconds == 121:
                self.assertEqual(result['cause'], 'assertion')
                self.assertIn('120', str(result['failed']))

    def test_non_converge_work_still_exhausts_the_case_budget(self):
        body = case('mixed').replace('    expect: []', '      - cli: {run: [node:list], expect: {exit: 0}}\n      - cli: {run: [node:list], expect: {exit: 0}}\n    expect: []')
        loaded = load_text(body, HEADER)
        clock, calls = [0.0], []

        def invoke(argv, **kwargs):
            if argv[-1] == 'shift':
                return subprocess.CompletedProcess(argv, 0, 'false', '')
            calls.append(argv)
            clock[0] += 500 if 'node:converge' in argv else 181
            return subprocess.CompletedProcess(argv, 0, '{}', '')

        with tempfile.TemporaryDirectory() as directory, patch.object(fleet.subprocess, 'run', invoke), \
                patch.object(runner.time, 'time', lambda: clock[0]), patch.object(runner.time, 'monotonic', lambda: clock[0]):
            result, _ = runner.execute(loaded.cases[0], transport(), loaded, directory)
        self.assertEqual(result['verdict'], 'failed')
        self.assertIn('case timeout passed', str(result['failed']))
        self.assertEqual(len(calls), 2)

    def test_timeout_in_setup_or_act_stops_later_cases_and_writes_every_receipt(self):
        # Proof 25268 (release 2120): node:role:add timed out at 240 s, the batch went on, and the next case failed at 'Apply role
        # prerequisites' beside the Operation that still ran.
        for setup, command in ((True, 'node:converge'), (False, 'node:converge'), (False, 'node:role:add')):
            calls = []

            def invoke(argv, **kwargs):
                if argv[-1] == 'shift':
                    return subprocess.CompletedProcess(argv, 0, 'false', '')
                calls.append(argv)
                raise subprocess.TimeoutExpired(argv, kwargs['timeout'])

            with tempfile.TemporaryDirectory() as directory:
                source = Path(directory) / 'converge.cases.yaml'
                source.write_text(HEADER + 'cases:\n' + case('times-out', setup, command) + case('later') + case('last'))
                out = io.StringIO()
                with patch.object(fleet, 'from_environment', return_value=transport()), patch.object(fleet.subprocess, 'run', invoke), \
                        contextlib.redirect_stdout(out), contextlib.redirect_stderr(io.StringIO()):
                    code = runner.main(['batch', 'fresh-fleet', '--file', str(source), '--evidence', directory])
                results = {row['id']: row for row in map(json.loads, out.getvalue().splitlines())}
                receipts = {row['id']: row for row in (json.loads(path.read_text()) for path in Path(directory).glob('*/*/receipt.json'))}
            self.assertEqual(code, 1)
            self.assertEqual(len(calls), 1)
            self.assertEqual(set(receipts), {'times-out', 'later', 'last'})
            self.assertEqual(results['times-out']['verdict'], 'failed')
            self.assertIn('600 seconds', str(results['times-out']['failed']))
            for name in ('later', 'last'):
                self.assertEqual(results[name]['verdict'], 'unrun')
                self.assertEqual(results[name]['cause'], 'infrastructure')
                self.assertEqual(receipts[name]['blocked_by'], 'times-out')
                self.assertEqual(receipts[name]['steps'], [])

    def test_receipt_write_failure_does_not_lose_the_batch_stop(self):
        loaded = load_text(case('times-out') + case('later'), HEADER)
        target = transport()

        def invoke(argv, **kwargs):
            if argv[-1] == 'shift':
                return subprocess.CompletedProcess(argv, 0, 'false', '')
            raise subprocess.TimeoutExpired(argv, kwargs['timeout'])

        with tempfile.TemporaryDirectory() as directory, patch.object(fleet.subprocess, 'run', invoke), \
                patch.object(Path, 'write_text', side_effect=OSError('disk full')), contextlib.redirect_stderr(io.StringIO()):
            results = [result for _, result in runner.schedule(loaded.cases, 1, lambda item: runner.run_case(item, target, loaded, directory))]
        self.assertTrue(results[0]['stop_batch'])
        self.assertIn('receipt: disk full', results[0]['failed'])
        self.assertEqual(results[1]['verdict'], 'unrun')

    def test_disk_full_keeps_timeout_and_all_waiting_verdicts_on_stdout(self):
        def invoke(argv, **kwargs):
            if argv[-1] == 'shift':
                return subprocess.CompletedProcess(argv, 0, 'false', '')
            raise subprocess.TimeoutExpired(argv, kwargs['timeout'])

        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / 'converge.cases.yaml'
            source.write_text(HEADER + 'cases:\n' + case('times-out') + case('later') + case('last'))
            out = io.StringIO()
            with patch.object(fleet, 'from_environment', return_value=transport()), patch.object(fleet.subprocess, 'run', invoke), \
                    patch.object(Path, 'write_text', side_effect=OSError('disk full')), \
                    contextlib.redirect_stdout(out), contextlib.redirect_stderr(io.StringIO()):
                code = runner.main(['batch', 'fresh-fleet', '--file', str(source), '--evidence', directory])
            results = {row['id']: row for row in map(json.loads, out.getvalue().splitlines())}
        self.assertEqual(code, 1)
        self.assertEqual(set(results), {'times-out', 'later', 'last'})
        self.assertEqual(results['times-out']['verdict'], 'failed')
        self.assertIn('600 seconds', str(results['times-out']['failed']))
        for name in ('later', 'last'):
            self.assertEqual(results[name]['verdict'], 'unrun')
            self.assertIn('receipt: disk full', results[name]['failed'])

    def test_a_stopped_batch_drains_already_running_cases_without_starting_waiters(self):
        cases = [test_parallel.SchedulerTest.case('timeout', 'app-dev'), test_parallel.SchedulerTest.case('active', 'app-prod'), test_parallel.SchedulerTest.case('waiting', 'gateway')]
        barrier = threading.Barrier(2, timeout=2)
        release = threading.Event()
        ran = []

        def work(item):
            ran.append(item.id)
            barrier.wait()
            if item.id == 'active':
                if not release.wait(2):
                    raise AssertionError('scheduler did not report timeout')
                return {'id': item.id, 'verdict': 'passed'}
            return {'id': item.id, 'verdict': 'failed', 'stop_batch': True}

        results = {}
        for item, result in runner.schedule(cases, 2, work):
            results[item.id] = result
            if item.id == 'timeout':
                release.set()
        self.assertEqual(set(ran), {'timeout', 'active'})
        self.assertEqual(results['active']['verdict'], 'passed')
        self.assertEqual(results['waiting']['verdict'], 'unrun')

    def test_real_repeat_keeps_its_product_120_second_assertion(self):
        loaded = spec.load(Path(__file__).resolve().parents[2] / 'node/converge.cases.yaml', BATCHES)
        assertions = loaded.case('default-scope').stages[1].act[0].args['expect']['json']
        self.assertIn({'path': 'result.elapsed_seconds', 'at_most': 120}, assertions)
