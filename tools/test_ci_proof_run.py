"""The proof CI step fails unless `orbit proof:run` exits cleanly and every case passed."""
import importlib.machinery
import importlib.util
import json
import os
import stat
import subprocess
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / 'bin/ci-proof-run'
loader = importlib.machinery.SourceFileLoader('ci_proof_run', str(SCRIPT))
spec = importlib.util.spec_from_loader(loader.name, loader)
proof = importlib.util.module_from_spec(spec)
loader.exec_module(proof)

SHA = 'a' * 40


def document(*verdicts, wrapper=None):
    result = {'outcome': 'passed', 'cases': [{'command': 'firewall:allow', 'file': 'swamp/proof/firewall/allow.cases.yaml', 'case': f'case-{i}',
                                              'verdict': verdict} for i, verdict in enumerate(verdicts)]}
    return json.dumps({wrapper: {'result': result}} if wrapper else {'result': result})


class EvaluateTest(unittest.TestCase):
    def test_all_cases_passed(self):
        for wrapper in (None, 'data', 'operation'):
            with self.subTest(wrapper=wrapper):
                passed, lines = proof.evaluate(0, document('passed', 'passed', wrapper=wrapper))
                self.assertTrue(passed)
                self.assertIn('| firewall:allow | case-0 |  | passed |', lines)

    def test_the_ci_summary_names_the_case_and_selection_reason(self):
        output = json.dumps({'result': {'outcome': 'passed', 'selected_commands': ['demo:run'],
                                       'cases': [{'command': 'demo:run', 'case': 'changed-case', 'reason': 'uses changed fixture', 'verdict': 'passed'}]}})
        passed, lines = proof.evaluate(0, output)
        self.assertTrue(passed)
        self.assertIn('| demo:run | changed-case | uses changed fixture | passed |', lines)

    def test_any_other_verdict_fails(self):
        for verdict in ('failed', 'unrun', 'skipped', ''):
            with self.subTest(verdict=verdict):
                self.assertFalse(proof.evaluate(0, document('passed', verdict))[0])

    def test_a_nonzero_exit_fails_even_when_the_cases_passed(self):
        self.assertFalse(proof.evaluate(1, document('passed'))[0])

    def test_a_missing_or_unreadable_result_fails(self):
        for output in ('', 'not json', '{}', '{"error": {"code": "proof.path_unmapped"}}', '[]'):
            with self.subTest(output=output):
                self.assertFalse(proof.evaluate(0, output)[0])

    def test_malformed_case_rows_cannot_be_discarded_to_turn_a_report_green(self):
        for cases in (None, {}, 'bad', [{'command': 'firewall:allow', 'verdict': 'passed'}, None]):
            output = json.dumps({'result': {'outcome': 'passed', 'cases': cases}})
            self.assertFalse(proof.evaluate(0, output)[0])

    def test_a_passed_proof_without_a_case_that_ran_fails(self):
        self.assertFalse(proof.evaluate(0, json.dumps({'result': {'outcome': 'passed', 'cases': []}}))[0])

    def test_any_outcome_other_than_passed_or_nothing_to_prove_fails(self):
        for outcome in ('failed', 'unrun', '', None):
            with self.subTest(outcome=outcome):
                output = json.dumps({'result': {'outcome': outcome, 'cases': [{'command': 'firewall:allow', 'case': 'a', 'verdict': 'passed'}]}})
                self.assertFalse(proof.evaluate(0, output)[0])

    def test_nothing_to_prove_with_a_case_row_fails(self):
        output = json.dumps({'result': {'outcome': 'nothing_to_prove', 'cases': [{'command': 'firewall:allow', 'case': 'a', 'verdict': 'passed'}]}})
        self.assertFalse(proof.evaluate(0, output)[0])

    def test_a_row_of_the_gateway_names_its_program(self):
        output = json.dumps({'result': {'outcome': 'passed', 'cases': [{'command': 'firewall:allow', 'program': 'swamp/proof/firewall/allow.cases.yaml', 'verdict': 'passed'}]}})
        passed, lines = proof.evaluate(0, output)
        self.assertTrue(passed)
        self.assertIn('| firewall:allow | allow.cases.yaml |  | passed |', lines)

    def test_nothing_to_prove_passes_and_lists_uncovered_paths(self):
        output = json.dumps({'result': {'outcome': 'nothing_to_prove', 'cases': [],
                                        'uncovered': [{'path': 'swamp/x.yaml', 'reason': 'none: no case yet'}]}})
        passed, lines = proof.evaluate(0, output)
        self.assertTrue(passed)
        self.assertIn('Uncovered: swamp/x.yaml (none: no case yet)', lines)


    def test_a_proof_refused_at_admission_fails_and_says_why(self):
        output = json.dumps({'status': 'failed', 'error_code': 'tasks.capacity_unavailable', 'result': {'outcome': 'failed', 'cases': [], 'uncovered': []},
                             'error': {'code': 'tasks.capacity_unavailable', 'detail': 'Task VM: no host has 3 free slots for a large VM.'}})
        passed, lines = proof.evaluate(1, output)
        self.assertFalse(passed)
        self.assertIn('No case ran.', lines)
        self.assertIn('Error: tasks.capacity_unavailable Task VM: no host has 3 free slots for a large VM.', lines)


    def test_a_failed_proof_without_a_result_still_says_why(self):
        output = json.dumps({'status': 'failed', 'error': {'code': 'proof.queue_unavailable', 'detail': 'The Gateway could not queue the admission of the proof.'}})
        passed, lines = proof.evaluate(1, output)
        self.assertFalse(passed)
        self.assertIn('Error: proof.queue_unavailable The Gateway could not queue the admission of the proof.', lines)


class CapacityReportTest(unittest.TestCase):
    def capacity(self):
        return {'id': 73, 'status': 'failed', 'error': {'code': 'tasks.capacity_unavailable', 'detail': 'private host detail'},
                'result': {'commit': SHA, 'outcome': 'failed', 'cases': [], 'selected_commands': ['firewall:allow']}}

    def test_capacity_failure_stays_failed_in_every_envelope(self):
        for wrapper in (None, 'data', 'operation'):
            payload = self.capacity()
            output = json.dumps({wrapper: payload} if wrapper else payload)
            outcome, lines, receipt = proof.report(1, output, SHA, 'ci-proof.7.2')
            self.assertEqual('failed', outcome)
            self.assertIn('No case ran.', lines)
            self.assertEqual('failed', receipt['outcome'])
            self.assertEqual(['firewall:allow'], receipt['selected_commands'])



class RunTest(unittest.TestCase):
    def run_script(self, orbit_body, env=None):
        with tempfile.TemporaryDirectory() as tmp:
            orbit = Path(tmp, 'orbit')
            orbit.write_text('#!/bin/sh\n' + orbit_body)
            orbit.chmod(orbit.stat().st_mode | stat.S_IXUSR)
            summary = Path(tmp, 'summary.md')
            environment = {'PATH': tmp + os.pathsep + '/usr/bin:/bin', 'PROOF_SHA': SHA, 'GITHUB_RUN_ID': '7',
                           'GITHUB_RUN_ATTEMPT': '2', 'GITHUB_STEP_SUMMARY': str(summary),
                           'GITHUB_OUTPUT': str(Path(tmp, 'outputs')), 'PROOF_RECEIPT': str(Path(tmp, 'receipt.json')), **(env or {})}
            done = subprocess.run(['python3', '-I', str(SCRIPT)], env=environment, capture_output=True, text=True, timeout=60)
            self.outputs = Path(tmp, 'outputs').read_text() if Path(tmp, 'outputs').exists() else ''
            self.receipt = json.loads(Path(tmp, 'receipt.json').read_text()) if Path(tmp, 'receipt.json').exists() else None
            return done, summary.read_text() if summary.exists() else ''

    def test_passes_only_the_sha_and_the_run_key_and_writes_the_summary(self):
        done, summary = self.run_script('printf "%s\\n" "$*" >&2\ncat <<\'JSON\'\n' + document('passed') + '\nJSON\n')
        self.assertEqual(0, done.returncode, done.stderr)
        self.assertIn(f'proof:run {SHA} --json --idempotency-key ci-proof.7.2', done.stderr)
        self.assertIn('passed', summary)

    def test_a_failed_case_fails_the_job(self):
        done, _ = self.run_script('cat <<\'JSON\'\n' + document('failed') + '\nJSON\n')
        self.assertNotEqual(0, done.returncode)

    def test_a_refusal_fails_the_job_with_its_status(self):
        done, _ = self.run_script('echo \'{"error":{"code":"proof.path_unmapped"}}\'\nexit 3\n')
        self.assertEqual(3, done.returncode)

    def test_capacity_unavailable_with_no_case_can_never_turn_the_job_green(self):
        payload = CapacityReportTest().capacity()
        payload['error']['detail'] = 'not run: capacity unavailable, no case ran'
        body = "cat <<'JSON'\n" + json.dumps(payload) + "\nJSON\nexit 3\n"
        for environment in ({}, {'PROOF_REPORT_ONLY': '1'}):
            done, summary = self.run_script(body, environment)
            self.assertEqual(3, done.returncode, done.stderr)
            self.assertIn('No case ran.', summary)
            self.assertEqual('outcome=failed\n', self.outputs)
            self.assertEqual('failed', self.receipt['outcome'])

    def test_zero_cases_and_a_missing_selected_command_fail_even_with_a_clean_cli_exit(self):
        for result in ({'outcome': 'passed', 'cases': []},
                       {'outcome': 'nothing_to_prove', 'cases': [], 'selected_commands': ['firewall:allow']},
                       {'outcome': 'passed', 'selected_commands': ['firewall:allow', 'firewall:deny'],
                        'cases': [{'command': 'firewall:allow', 'case': 'a', 'verdict': 'passed'}]}):
            done, _ = self.run_script("cat <<'JSON'\n" + json.dumps({'result': result}) + "\nJSON\n")
            self.assertNotEqual(0, done.returncode)

    def test_no_selected_command_passes_without_cases(self):
        done, _ = self.run_script("echo '{\"result\":{\"outcome\":\"nothing_to_prove\",\"cases\":[],\"selected_commands\":[]}}'\n")
        self.assertEqual(0, done.returncode, done.stderr)

    def test_a_bad_sha_or_a_missing_cli_fails_before_running(self):
        self.assertNotEqual(0, self.run_script('exit 0\n', {'PROOF_SHA': 'main'})[0].returncode)
        done = subprocess.run(['python3', '-I', str(SCRIPT)], env={'PATH': '/usr/bin:/bin', 'PROOF_SHA': SHA},
                              capture_output=True, text=True, timeout=60)
        self.assertNotEqual(0, done.returncode)
        self.assertIn('orbit CLI', done.stderr)


if __name__ == '__main__':
    unittest.main()
