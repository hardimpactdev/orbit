"""Caller preparation uses the real Fleet, with only the Incus/curl boundary replaced."""
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

sys.path[:0] = [str(Path(__file__).resolve().parents[1]), str(Path(__file__).resolve().parent)]
import fleet
import runner
import spec
from test_parallel import BATCHES, HEADER, refusal


class CallerPreparationTest(unittest.TestCase):
    def run_cases(self, body, writer=False, fail_mount=False, command=None, timeout=False):
        transport = fleet.Fleet({role: {'instance': 'nested-' + role, 'node_id': ident}
                                 for role, ident in [('gateway', 209), ('app-dev', 211), ('app-prod', 212)]})
        events, mounted, profiled = [], False, False
        guard = threading.Lock()
        preparation = threading.Barrier(2, timeout=0.5)
        readers = threading.Barrier(2, timeout=0.5)

        def rendezvous(barrier):
            try:
                barrier.wait()
                return True
            except threading.BrokenBarrierError:
                return False   # The unfixed runner may lose one reader to a duplicate mount.

        def done(rc=0, document=None, stdout=None):
            return subprocess.CompletedProcess([], rc, json.dumps(document) if stdout is None else stdout,
                                               'device already exists' if rc else '')

        def subprocess_run(argv, **kwargs):
            nonlocal mounted, profiled
            if argv[0] == 'curl':
                return done(document={'data': []})
            if argv[:4] == ['incus', 'config', 'device', 'get']:
                if timeout:
                    raise subprocess.TimeoutExpired(argv, 60)
                with guard:
                    ready = mounted
                    events.append('mount-check')
                # Reproduce two workers observing the missing device, or preparation beside a writer.
                # Preparation on the scheduler thread has no competing case to wait for.
                if not ready and threading.current_thread() is not threading.main_thread():
                    rendezvous(preparation)
                return done(0 if ready else 1, stdout=str(fleet.CHECKOUT) if ready else '')
            if argv[:4] == ['incus', 'config', 'device', 'add']:
                with guard:
                    events.append('mount')
                    if mounted or fail_mount:
                        return done(1, stdout='')
                    mounted = True
                return done(stdout='')
            if 'grep' in argv:
                return done(0 if profiled else 1, stdout='')
            if 'install' in argv or argv[:3] == ['incus', 'file', 'push']:
                events.append('home' if 'install' in argv else 'ca')
                return done(stdout='')
            if 'gateway:add' in argv:
                profiled = True
                events.append('profile')
                return done(document={})
            if argv[-1] == 'systemctl stop example':
                events.append('writer')
                if not profiled:
                    rendezvous(preparation)
                return done(stdout='')
            if 'firewall:allow' in argv:
                events.append('reader')
                if not writer and command is None:
                    if rendezvous(readers):
                        events.append('readers-overlapped')
                return done(1, document={'error': {'code': 'firewall.source_required'}})
            self.fail(f'unexpected transport call: {argv}')

        with tempfile.TemporaryDirectory() as directory:
            cases = Path(directory) / 'caller.cases.yaml'
            cases.write_text(HEADER + 'cases:\n' + body)
            loaded = spec.load(cases, BATCHES)
            out, err = io.StringIO(), io.StringIO()
            argv = command or ['batch', 'fresh-fleet']
            with patch.object(fleet, 'from_environment', return_value=transport), \
                    patch.object(fleet.subprocess, 'run', subprocess_run), \
                    contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
                code = runner.main([*argv, '--file', str(cases), '--evidence', directory])
            results = [json.loads(line) for line in out.getvalue().splitlines()]
        return code, results, events, loaded

    @staticmethod
    def reader(name):
        return refusal(name).replace('cli: {run:', 'cli: {as: app-prod, run:')

    def test_fresh_caller_shared_by_two_readers_is_prepared_once_before_both_run(self):
        code, results, events, loaded = self.run_cases(self.reader('a') + self.reader('b'))
        self.assertEqual([case.lock for case in loaded.cases], ['read', 'read'])
        self.assertEqual((code, [row['verdict'] for row in results]), (0, ['passed', 'passed']))
        self.assertEqual(events.count('mount-check'), 1)
        self.assertEqual(events.count('mount'), 1)
        self.assertEqual(events.count('profile'), 1)
        self.assertLess(events.index('profile'), events.index('reader'))
        self.assertEqual(events.count('reader'), 2)
        self.assertEqual(events.count('readers-overlapped'), 2)

    def test_caller_preparation_finishes_before_a_writer_on_that_caller(self):
        writer = '''  - id: writer
    batch: fresh-fleet
    covers: [main]
    summary: Write on the CLI caller.
    node: app-prod
    act:
      - service: {on: app-dev, name: example, state: stop}
    expect: []
'''
        code, results, events, loaded = self.run_cases(self.reader('a') + writer, writer=True)
        self.assertEqual([(case.node, case.lock) for case in loaded.cases], [('app-dev', 'read'), ('app-prod', 'node')])
        self.assertEqual((code, [row['verdict'] for row in results]), (0, ['passed', 'passed']))
        self.assertLess(events.index('profile'), events.index('writer'), events)
        self.assertEqual(events.count('mount-check'), 1)

    def test_failed_caller_preparation_fails_the_batch_without_starting_a_case(self):
        code, results, events, _ = self.run_cases(self.reader('a') + self.reader('b'), fail_mount=True)
        self.assertEqual(code, 1)
        self.assertEqual([(row['verdict'], row['cause']) for row in results], [('failed', 'infrastructure')] * 2)
        self.assertTrue(all('cannot mount the tested checkout' in row['failed'][0] for row in results))
        self.assertNotIn('reader', events)
        self.assertEqual(events.count('mount'), 1)

    def test_direct_case_prepares_its_caller(self):
        code, results, events, _ = self.run_cases(self.reader('a'), command=['case', 'a'])
        self.assertEqual((code, results[0]['verdict']), (0, 'passed'))
        self.assertEqual(events.count('profile'), 1)
        self.assertLess(events.index('profile'), events.index('reader'))

    def test_caller_preparation_timeout_fails_every_case_without_starting_them(self):
        code, results, events, _ = self.run_cases(self.reader('a') + self.reader('b'), timeout=True)
        self.assertEqual(code, 1)
        self.assertEqual([(row['verdict'], row['cause']) for row in results], [('failed', 'infrastructure')] * 2)
        self.assertTrue(all('preparation timed out' in row['failed'][0] for row in results))
        self.assertNotIn('reader', events)
