"""The cases phase: several programs against one fleet, one verdict each. No Incus; programs are real bash files."""
import io
import json
import os
import subprocess
import sys
import tempfile
import time
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import launcher
import phases

GENERATION = 'a' * 32


class CasePhaseTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.source = self.root / 'source'
        self.evidence = self.root / 'evidence'
        self.source.mkdir()
        self.evidence.mkdir()
        launcher.write_json(self.evidence / 'publish.json', {'generation': GENERATION})
        self.marker = self.root / 'marker'
        # A case "leaves residue" by creating the marker; the fake baselines read it as a firewall database record.
        self.baselines = 0
        self.preservation = patch.object(phases, 'preservation', return_value={'passed': True})
        self.preservation_mock = self.preservation.start()
        for patcher in (self.preservation, patch.object(phases, 'source_for', return_value=self.source),
                        patch.object(phases, 'case_baseline', side_effect=self.baseline)):
            if patcher is not self.preservation:
                patcher.start()
            self.addCleanup(patcher.stop)

    def baseline(self, request):
        self.baselines += 1
        return {'firewall_records': ['residue'] if self.marker.exists() else [], 'ufw': {'app-dev': 'Status: active'}}

    def program(self, name, body):
        path = self.source / name
        path.write_text('set -u\n' + body + '\n')
        return name

    def request(self, programs, budget=60):
        return {'evidence': str(self.evidence), 'generation': GENERATION, 'cases': programs, 'budget_seconds': budget}

    def run_cases(self, programs, budget=60):
        try:
            return phases.run_cases(self.request(programs, budget)), None
        except RuntimeError as error:
            return None, str(error)

    def recorded(self):
        return json.loads((self.evidence / 'cases.json').read_text())

    def test_three_cases_run_in_order_with_a_verdict_each(self):
        programs = [self.program('pass.sh', 'echo "index $PROOF_CASE_INDEX of $PROOF_GENERATION"; exit 0'),
                    self.program('fail.sh', 'exit 1'), self.program('unrun.sh', 'exit 2')]
        value, error = self.run_cases(programs)
        self.assertIsNone(value)
        self.assertIn('fail.sh failed, unrun.sh unrun', error)
        recorded = self.recorded()
        self.assertEqual(GENERATION, recorded['generation'])
        cases = recorded['cases']
        self.assertEqual(programs, [case['program'] for case in cases])
        self.assertEqual(['passed', 'failed', 'unrun'], [case['verdict'] for case in cases])
        self.assertEqual([0, 1, 2], [case['exit_code'] for case in cases])
        self.assertEqual([None, 'exited 1', 'the case reported that it could not run (exit 2)'], [case['note'] for case in cases])
        self.assertTrue(all(isinstance(case['seconds'], float) for case in cases))
        self.assertEqual(f'index 1 of {GENERATION}\n', (self.evidence / 'cases-1.log').read_text())
        self.assertTrue((self.evidence / 'cases-3.log').exists())
        self.assertEqual({'generation': GENERATION, 'programs': programs}, json.loads((self.evidence / 'cases-started.json').read_text()))
        self.preservation_mock.assert_called_once()

    def test_every_program_gets_the_proof_environment(self):
        program = self.program('env.sh', 'printf "%s\\n" "$PROOF_RESULT" "$PROOF_SOURCE" "$PROOF_GENERATION" "$PROOF_EVIDENCE" "$PROOF_CASE_INDEX" "$PWD"')
        value, error = self.run_cases([program])
        self.assertIsNone(error)
        self.assertEqual([str(self.evidence / 'publish.json'), str(self.source), GENERATION, str(self.evidence), '1', str(self.source)],
                         (self.evidence / 'cases-1.log').read_text().splitlines())
        self.assertEqual({'generation': GENERATION, 'cases': self.recorded()['cases']}, value)

    def test_a_failed_case_does_not_stop_the_next_one(self):
        _, error = self.run_cases([self.program('a.sh', 'exit 7'), self.program('b.sh', 'exit 0')])
        self.assertIn('a.sh failed', error)
        self.assertEqual([('failed', 7), ('passed', 0)], [(case['verdict'], case['exit_code']) for case in self.recorded()['cases']])

    def test_residue_fails_only_the_case_that_left_it(self):
        clean = self.program('clean.sh', 'exit 0')
        dirty = self.program('dirty.sh', f'touch {self.marker}; exit 0')
        after = self.program('after.sh', 'exit 0')
        _, error = self.run_cases([clean, dirty, after])
        self.assertIn('dirty.sh failed', error)
        cases = self.recorded()['cases']
        self.assertEqual(['passed', 'failed', 'passed'], [case['verdict'] for case in cases])
        self.assertEqual(0, cases[1]['exit_code'])
        self.assertEqual('left residue in firewall database records', cases[1]['note'])
        # The next case is compared with its own pre-case state, which already holds the residue.
        self.assertIsNone(cases[2]['note'])
        # Two baselines per case.
        self.assertEqual(6, self.baselines)

    def test_a_failing_case_that_also_left_residue_names_both(self):
        self.run_cases([self.program('both.sh', f'touch {self.marker}; exit 3')])
        case = self.recorded()['cases'][0]
        self.assertEqual('failed', case['verdict'])
        self.assertEqual('exited 3; left residue in firewall database records', case['note'])

    def test_ufw_residue_names_the_node(self):
        states = iter([{'firewall_records': [], 'ufw': {'app-dev': 'one', 'app-prod': 'x'}},
                       {'firewall_records': [], 'ufw': {'app-dev': 'one', 'app-prod': 'y'}}])
        with patch.object(phases, 'case_baseline', side_effect=lambda request: next(states)):
            self.run_cases([self.program('a.sh', 'exit 0')])
        case = self.recorded()['cases'][0]
        self.assertEqual(('failed', 'left residue in UFW rules on app-prod'), (case['verdict'], case['note']))

    def test_the_budget_fails_the_hanging_case_kills_it_and_leaves_later_cases_unrun(self):
        programs = [self.program('quick.sh', 'exit 0'), self.program('hang.sh', f'echo $$ > {self.root}/pid; sleep 60 & wait'),
                    self.program('later.sh', 'exit 0'), self.program('last.sh', 'exit 0')]
        started = time.monotonic()
        _, error = self.run_cases(programs, budget=1.5)
        self.assertLess(time.monotonic() - started, 20)
        cases = self.recorded()['cases']
        self.assertEqual(['passed', 'failed', 'unrun', 'unrun'], [case['verdict'] for case in cases])
        self.assertEqual((None, 'exceeded the budget'), (cases[1]['exit_code'], cases[1]['note']))
        self.assertEqual('an earlier case exceeded the budget', cases[2]['note'])
        self.assertEqual([None, None], [case['exit_code'] for case in cases[2:]])
        self.assertIn('hang.sh failed, later.sh unrun, last.sh unrun', error)
        # The whole process group is gone, not only the shell.
        pid = int((self.root / 'pid').read_text())
        time.sleep(0.2)
        with self.assertRaises(ProcessLookupError):
            os.killpg(pid, 0)

    def test_a_case_still_running_when_the_wait_raises_has_its_process_group_killed(self):
        pidfile = self.root / 'pid'
        program = self.program('hang.sh', f'echo $$ > {pidfile}; sleep 60 & wait')
        waits = []

        class Interrupted(subprocess.Popen):
            def wait(self, timeout=None):
                if not waits:
                    waits.append(1)
                    deadline = time.monotonic() + 10
                    while not pidfile.exists() and time.monotonic() < deadline:
                        time.sleep(0.05)
                    raise KeyboardInterrupt
                return super().wait(timeout)

        with patch.object(subprocess, 'Popen', Interrupted), self.assertRaises(KeyboardInterrupt):
            phases.run_program(self.source / program, self.source, dict(os.environ), self.evidence / 'cases-1.log', 30)
        pid = int(pidfile.read_text())
        time.sleep(0.2)
        with self.assertRaises(ProcessLookupError):
            os.killpg(pid, 0)

    def test_each_case_gets_the_remaining_budget_as_its_timeout(self):
        timeouts = []
        real = phases.run_program

        def spy(program, source, env, log_path, timeout):
            timeouts.append(timeout)
            return real(program, source, env, log_path, timeout)
        programs = [self.program('a.sh', 'sleep 0.5'), self.program('b.sh', 'exit 0')]
        with patch.object(phases, 'run_program', side_effect=spy):
            self.run_cases(programs, budget=30)
        self.assertEqual(2, len(timeouts))
        self.assertLess(timeouts[1], timeouts[0] - 0.4)
        self.assertLessEqual(timeouts[0], 30)

    def test_an_unreadable_state_before_the_case_leaves_it_unrun(self):
        with patch.object(phases, 'case_baseline', side_effect=RuntimeError('gateway down')):
            self.run_cases([self.program('a.sh', f'touch {self.root}/ran')])
        self.assertFalse((self.root / 'ran').exists())
        case = self.recorded()['cases'][0]
        self.assertEqual(('unrun', None), (case['verdict'], case['exit_code']))
        self.assertIn('gateway down', case['note'])

    def test_preservation_still_runs_after_a_failed_case_and_its_failure_is_reported(self):
        self.preservation_mock.side_effect = RuntimeError('Unrelated fixture changed: x')
        _, error = self.run_cases([self.program('a.sh', 'exit 1')])
        self.assertIn('Preservation failed: Unrelated fixture changed: x', error)
        self.assertIn('a.sh failed', error)
        self.assertEqual('failed', self.recorded()['cases'][0]['verdict'])

    def test_an_empty_program_list_is_refused(self):
        with self.assertRaisesRegex(RuntimeError, 'no case programs'):
            phases.run_cases(self.request([]))

    def test_a_program_the_snapshot_lacks_fails_instead_of_stopping_the_run(self):
        self.run_cases(['missing.sh', self.program('ok.sh', 'exit 0')])
        self.assertEqual(['failed', 'passed'], [case['verdict'] for case in self.recorded()['cases']])


class SummaryTests(unittest.TestCase):
    def case(self, verdict, seconds):
        return {'program': 'x', 'verdict': verdict, 'exit_code': 0, 'seconds': seconds, 'note': None}

    def test_one_letter_and_rounded_seconds_per_case_in_order(self):
        cases = [self.case('passed', 12.44), self.case('failed', 3.06), self.case('unrun', 4.2), self.case('passed', 0.04), self.case('failed', 100.0)]
        self.assertEqual([['p', 12.4], ['f', 3.1], ['u', 0], ['p', 0.0], ['f', 100.0]], launcher.summary(cases))
        self.assertEqual('[["p",12.4],["f",3.1],["u",0]]', json.dumps(launcher.summary(cases[:3]), separators=(',', ':')))

    def test_the_final_line_puts_summary_right_after_status(self):
        line = launcher.final_line({'exit': 1, 'status': 'failed', 'cases': [], 'summary': [['u', 0]]}, 'g', '/e')
        self.assertEqual('{"status":"failed","summary":[["u",0]],"exit":1,"generation":"g","evidence":"/e"}', line)

    def test_forty_failing_cases_keep_the_line_under_the_swamp_output_cut(self):
        note = 'exited 3; left residue in firewall database records, UFW rules on app-dev, UFW rules on app-prod'
        cases = [{'program': f'swamp/proof/firewall/cases/some-long-case-name-{n}.sh', 'verdict': 'failed', 'exit_code': 3,
                  'seconds': 123.456, 'note': note} for n in range(40)]
        status = {'status': 'failed', 'summary': launcher.summary(cases), 'exit': 1, 'cases_ran': True, 'cases': cases}
        line = launcher.final_line(status, 'a' * 32, '/home/orbit/work/orbit/.orbit-artifacts/3187/reset/' + 'a' * 32)
        self.assertLess(len(line.encode()), 4096)
        self.assertLess(len(line.encode()), 1000)
        value = json.loads(line)
        self.assertNotIn('cases', value)
        self.assertEqual(40, len(value['summary']))

    def test_a_reset_has_no_summary(self):
        self.assertEqual('{"status":"succeeded","exit":0,"cases_ran":false,"generation":"g","evidence":"/e"}',
                         launcher.final_line({'status': 'succeeded', 'exit': 0, 'cases_ran': False}, 'g', '/e'))


class CaseResultTests(unittest.TestCase):
    def test_a_fleet_that_never_started_cases_reports_every_program_unrun(self):
        with tempfile.TemporaryDirectory() as temporary:
            request = {'evidence': temporary, 'cases': ['a.sh', 'b.sh']}
            self.assertEqual([launcher.unrun_case('a.sh', 'the test fleet was not ready'),
                              launcher.unrun_case('b.sh', 'the test fleet was not ready')], launcher.case_results(request))

    def test_a_phase_that_stopped_midway_pads_the_missing_cases_as_unrun(self):
        with tempfile.TemporaryDirectory() as temporary:
            evidence = Path(temporary)
            launcher.write_json(evidence / 'cases-started.json', {'generation': GENERATION, 'programs': ['a.sh', 'b.sh']})
            done = {'program': 'a.sh', 'verdict': 'passed', 'exit_code': 0, 'seconds': 0.1, 'note': None}
            launcher.write_json(evidence / 'cases.json', {'generation': GENERATION, 'cases': [done]})
            results = launcher.case_results({'evidence': temporary, 'cases': ['a.sh', 'b.sh']})
            self.assertEqual([done, launcher.unrun_case('b.sh', 'the case phase stopped before this case')], results)

    def verify(self, group, bodies):
        """Launcher to cases phase, with the fake Swamp running the phase in place of the workflow."""
        programs = ['bin/proof-fleet', 'swamp/proof/reset/cases/smoke.sh', 'swamp/proof/firewall/cases/allow-fresh-ipv4-tcp.sh'][:len(bodies)]
        with tempfile.TemporaryDirectory() as temporary:
            home = Path(temporary) / 'home'
            source = Path(temporary) / 'source'
            for program, body in zip(programs, bodies, strict=True):
                (source / program).parent.mkdir(parents=True, exist_ok=True)
                (source / program).write_text(body + '\n')
            key = home / '.config/swamp/club-api-key'
            key.parent.mkdir(parents=True)
            key.write_text('unit-test-not-a-real-key')
            key.chmod(0o600)
            controller = home / '.local/state' / ('orbit-proof-' + group) / 'controller'
            controller.mkdir(parents=True)
            (controller / '.swamp.yaml').write_text('fixture')

            def native(request, args, name, cwd=None):
                if args[:2] == ['behavior', 'run']:
                    return launcher.run_locked(request)
                if args[:2] == ['workflow', 'run']:
                    launcher.write_json(Path(request['evidence']) / 'publish.json', {'generation': request['generation']})
                    with patch.object(phases, 'source_for', return_value=source), patch.object(phases, 'preservation'), \
                            patch.object(phases, 'case_baseline', return_value={'firewall_records': [], 'ufw': {}}):
                        try:
                            phases.run_cases(request)
                        except RuntimeError:
                            return 1
                return 0

            output = io.StringIO()
            with patch.object(Path, 'home', return_value=home), patch.object(sys, 'argv', ['bin/proof-fleet', 'verify', group, *programs]), \
                    patch.object(launcher, 'swamp', side_effect=native), redirect_stdout(output):
                code = launcher.main()
            self.line = output.getvalue().splitlines()[-1]
            final = json.loads(self.line)
            evidence = Path(final['evidence'])
            self.status = json.loads((evidence / 'status.json').read_text())
            self.assertEqual({'generation': final['generation'], 'cases': self.status['cases']}, json.loads((evidence / 'cases.json').read_text()))
        return code, final, programs

    def test_the_final_json_line_holds_the_verdicts_and_exit_1_for_any_case_that_did_not_pass(self):
        code, final, programs = self.verify('999998', ['exit 0', 'exit 1', 'exit 2'])
        self.assertEqual(1, code)
        self.assertEqual({'status', 'summary', 'exit', 'mode', 'seconds', 'cases_ran', 'generation', 'evidence'}, set(final))
        self.assertEqual(('failed', 1, True), (final['status'], final['exit'], final['cases_ran']))
        self.assertEqual(programs, [case['program'] for case in self.status['cases']])
        self.assertEqual(['passed', 'failed', 'unrun'], [case['verdict'] for case in self.status['cases']])

    def test_the_summary_comes_first_in_compact_form_and_survives_a_truncated_tail(self):
        _, final, _ = self.verify('999995', ['exit 0', 'exit 1', 'exit 2'])
        self.assertEqual(['status', 'summary', 'exit', 'mode', 'seconds', 'cases_ran', 'generation', 'evidence'], list(final))
        self.assertEqual([['p', self.status['cases'][0]['seconds']], ['f', self.status['cases'][1]['seconds']], ['u', 0]], final['summary'])
        self.assertTrue(self.line.startswith('{"status":"failed","summary":[["p",'), self.line[:80])
        self.assertNotIn(', ', self.line)
        self.assertNotIn('"cases"', self.line)
        self.assertNotIn('": ', self.line)
        # Swamp keeps the first 4096 bytes: the head still parses as far as the summary.
        head = self.line[:200]
        self.assertEqual(final['summary'], json.loads(head[head.index('"summary":') + len('"summary":'):head.index(',"exit"')]))
        status = json.loads((Path(final['evidence']) / 'status.json').read_text())
        self.assertEqual(final['summary'], status['summary'])
        self.assertEqual(['status', 'summary'], list(status)[:2])

    def test_a_run_where_every_case_passed_exits_zero(self):
        code, final, _ = self.verify('999997', ['exit 0', 'exit 0'])
        self.assertEqual(0, code)
        self.assertEqual(('succeeded', 0, True), (final['status'], final['exit'], final['cases_ran']))
        self.assertEqual(['passed', 'passed'], [case['verdict'] for case in self.status['cases']])

    def test_one_unrun_case_alone_exits_one(self):
        code, final, _ = self.verify('999996', ['exit 2'])
        self.assertEqual((1, 'failed'), (code, final['status']))


if __name__ == '__main__':
    unittest.main()
