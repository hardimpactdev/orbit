"""Check disposable payloads and proof rejection rules without a topology."""
import hashlib
import importlib.util
import io
import json
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


parity = load('outage_parity', ROOT / 'outage_parity.py')


class OutageFixtures(unittest.TestCase):
    def test_six_cases_use_the_normal_cli_and_exact_outage_selectors(self):
        for case in parity.CASES:
            folder = ROOT if case != 'already-running' else ROOT.parent / 'instance.run_steps'
            value = json.loads((folder / (case + '.json')).read_text())
            self.assertEqual(value['request']['operation'], '$.id')
            self.assertEqual(value['request']['argv'][:2], ['orbit', 'instance:setup' if case == 'already-running' else 'instance:create'])
            self.assertEqual(value['parity']['command'][-1], case)
            self.assertEqual(value['topology'], 'app-dev')
            self.assertNotIn('needs_caddy', value)
            self.assertTrue(any(item.startswith('route_phase: not_exercised') for item in value['limitations']))
            self.assertNotIn('before_request', value)
            project = value['prepare'][0]['argv']
            self.assertEqual(project[3], 'node-package')
            self.assertEqual(project[4], '${proof.repo.url}')
            self.assertIn('--default-branch=main', project)
        for case in ('setup-fails-then-skips', 'setup-macros', 'setup-redaction'):
            value = json.loads((ROOT / (case + '.json')).read_text())
            self.assertEqual(value['expect']['outputs']['step'], 'finish-setup-1-0')

    def test_github_token_limit_is_only_a_receipt_limitation(self):
        for case in ('branch-in-use', 'setup-fails-then-skips'):
            value = json.loads((ROOT / (case + '.json')).read_text())
            self.assertIn('github_token_path: not_exercised (topology repositories are not GitHub)', value['limitations'])
            self.assertFalse(any('github_token_path' in case for case in value['parity']['cases']))

    def test_macro_alias_is_sent_to_the_gateway_not_expanded_in_the_fixture(self):
        value = json.loads((ROOT / 'setup-macros.json').read_text())
        self.assertIn('--command=@orbit/ci-caches', value['prepare'][1]['argv'])
        self.assertTrue((ROOT / 'repository/bin/ci-cache-ref').is_file())


class DisposablePayloads(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        shutil.copytree(ROOT / 'repository', self.root, dirs_exist_ok=True)

    def test_actual_setup_commands_fail_and_leave_later_marker_absent(self):
        value = json.loads((ROOT / 'setup-fails-then-skips.json').read_text())
        commands = [arg.removeprefix('--command=') for step in value['prepare'] for arg in step['argv'] if arg.startswith('--command=')]
        results = []
        for command in commands:
            answer = subprocess.run(['/bin/bash', '-eu', '-c', command], cwd=self.root, capture_output=True, check=False)
            results.append(answer.returncode)
            if answer.returncode:
                break
        self.assertEqual(results, [0, 37])
        evidence = subprocess.run([sys.executable, '-c', parity.WORKLOAD, str(self.root), 'setup-fails-then-skips'],
                                  capture_output=True, text=True, check=True)
        checked = json.loads(evidence.stdout)
        self.assertTrue(checked['first'] and checked['failed'] and not checked['skipped'])

    def test_redaction_payload_emits_both_dotenv_forms_invalid_utf8_and_fragments(self):
        prepare = load('prepare_values', self.root / 'setup-redaction/prepare_values.py')
        emit = load('emit_values', self.root / 'setup-redaction/fail_redaction.py')
        prepare.prepare(self.root)
        values = json.loads((self.root / '.orbit-proof-private-values').read_text())
        dotenv = (self.root / '.env').read_text()
        self.assertTrue('\\t' in dotenv and '\t' in values['tab'])
        self.assertTrue(values['hash'] + '#unspaced-comment' in dotenv)
        self.assertEqual((self.root / '.orbit-proof-private-values').stat().st_mode & 0o777, 0o600)
        stderr, stdout = io.BytesIO(), io.BytesIO()
        emit.emit(self.root, stderr, stdout)
        self.assertTrue(b'\xff' in stderr.getvalue() and len(stderr.getvalue()) > 4096)
        self.assertTrue(values['stdout'].encode() in stdout.getvalue())
        for field in ('tab', 'hash'):
            private = values[field].encode()
            self.assertTrue(private in stderr.getvalue() and private[12:36] in stderr.getvalue())
        evidence = subprocess.run([sys.executable, '-c', parity.WORKLOAD, str(self.root), 'setup-redaction'],
                                  capture_output=True, text=True, check=True)
        oracle = json.loads(evidence.stdout)
        self.assertEqual(set(oracle) - {'fragment_hashes'}, {'first', 'failed', 'skipped', 'empty', 'macro', 'running_setup'})
        self.assertTrue(all(len(item) == 64 for item in oracle['fragment_hashes']))
        parity.assert_private_absent(oracle, oracle['fragment_hashes'])
        for field in ('tab', 'hash', 'stdout'):
            for leaked in (values[field], values[field][12:36]):
                with self.assertRaisesRegex(RuntimeError, 'fragment-exposed'):
                    parity.assert_private_absent({'stderr': leaked}, oracle['fragment_hashes'])

    def test_empty_environment_command_checks_the_nested_application(self):
        value = json.loads((ROOT / 'empty-environment.json').read_text())
        command = next(arg.removeprefix('--command=') for arg in value['prepare'][1]['argv'] if arg.startswith('--command='))
        self.assertNotEqual(subprocess.run(['/bin/bash', '-eu', '-c', command], cwd=self.root, check=False).returncode, 0)
        (self.root / 'empty-environment/.env').write_text('')
        self.assertEqual(subprocess.run(['/bin/bash', '-eu', '-c', command], cwd=self.root, check=False).returncode, 0)
        self.assertTrue((self.root / '.orbit-proof-empty').is_file())


class OutageParity(unittest.TestCase):
    def setUp(self):
        self.operation = {'id': 31, 'kind': 'instance.create', 'status': 'failed', 'target_id': 17,
                          'started_at': '2026-11-01T00:00:01Z', 'finished_at': '2026-11-01T00:00:02Z', 'result': {}}
        self.instance = {'id': 17, 'status': 'source_resolved'}
        self.workload = {'first': True, 'failed': True, 'skipped': False}
        self.selected = {'stdout': json.dumps({'exitCode': 37, 'stderr': 'expected-setup-failure\n'})}

    def validate(self, **changes):
        args = {'case': 'setup-fails-then-skips', 'selected': self.selected, 'operation': self.operation,
                'instance': self.instance, 'workload': self.workload}
        return parity.validate(**(args | changes))

    def test_failed_command_requires_execution_evidence_and_skip(self):
        self.assertTrue(self.validate()['passed'])
        for change in ({'first': False}, {'failed': False}, {'skipped': True}):
            with self.assertRaisesRegex(RuntimeError, 'order-or-skip'):
                self.validate(workload=self.workload | change)

    def test_stale_projection_wrong_instance_and_active_failure_are_refused(self):
        for operation in (self.operation | {'status': 'running'}, self.operation | {'kind': 'instance.clone'},
                          self.operation | {'target_id': 18}, self.operation | {'finished_at': None}):
            with self.assertRaises(RuntimeError):
                self.validate(operation=operation)
        with self.assertRaisesRegex(RuntimeError, 'projected-active'):
            self.validate(instance=self.instance | {'status': 'active'})

    def test_wrong_failure_or_missing_selected_tail_is_refused(self):
        for command in ({'exitCode': 1, 'stderr': 'expected-setup-failure'}, {'exitCode': 37, 'stderr': 'unrelated'}):
            with self.assertRaises(RuntimeError):
                self.validate(selected={'stdout': json.dumps(command)})
        with self.assertRaises((KeyError, TypeError)):
            self.validate(selected={'create': {'finish-setup-1-0': self.selected}})

    def test_a_route_cannot_hide_behind_the_no_caddy_classification(self):
        for change in ({'route': {'id': 7}}, {'routes': [{'id': 7}]}):
            with self.assertRaisesRegex(RuntimeError, 'unexpected-route-in-no-caddy-fixture'):
                self.validate(instance=self.instance | change)

    def test_tail_bound_uses_utf8_bytes(self):
        with self.assertRaisesRegex(RuntimeError, 'over-4096'):
            parity.check_tail({'stderr': 'é' * 2049}, 'é')
        self.assertEqual(parity.check_tail({'stderr': 'é' * 2048}, 'é'), 'é' * 2048)

    def test_macro_must_reach_the_following_command(self):
        selected = {'stdout': json.dumps({'exitCode': 31, 'stderr': 'macro-tail-marker'})}
        self.assertTrue(self.validate(case='setup-macros', selected=selected, workload={'macro': True})['passed'])
        with self.assertRaisesRegex(RuntimeError, 'macro-never-completed'):
            self.validate(case='setup-macros', selected=selected, workload={})

    def test_redaction_checks_selected_result_and_public_projection(self):
        secret = 'private-disposable-value-0123456789'
        hashes = [hashlib.sha256(secret[i:i+8].encode()).hexdigest() for i in range(len(secret)-7)]
        command = {'exitCode': 47, 'stderr': '[REDACTED]\npublic-redaction-tail-marker\n'}
        selected = {'stdout': json.dumps(command)}
        workload = {'fragment_hashes': hashes}
        self.assertTrue(self.validate(case='setup-redaction', selected=selected, workload=workload)['passed'])
        for leaked in (secret, secret[8:20]):
            operation = self.operation | {'error': {'detail': leaked}}
            with self.assertRaisesRegex(RuntimeError, 'fragment-exposed'):
                self.validate(case='setup-redaction', selected=selected, operation=operation, workload=workload)
        for change in ({'stdout': secret}, {'stderr': 'public-redaction-tail-marker'}):
            with self.assertRaises(RuntimeError):
                self.validate(case='setup-redaction', selected={'stdout': json.dumps(command | change)}, workload=workload)

    def test_setup_cannot_pass_as_an_empty_list_or_without_workload_execution(self):
        operation = self.operation | {'kind': 'instance.run_steps', 'status': 'succeeded', 'input': {'list': 'setup'},
                                      'result': {'outcome': 'succeeded', 'no_op': False}}
        args = {'case': 'already-running', 'selected': {'status': 'succeeded'}, 'operation': operation,
                'instance': self.instance | {'status': 'active'}, 'workload': {'running_setup': True}}
        self.assertTrue(self.validate(**args)['passed'])
        for change in ({'workload': {}}, {'operation': operation | {'result': {'outcome': 'succeeded', 'no_op': True}}}):
            with self.assertRaises(RuntimeError):
                self.validate(**(args | change))

    def test_empty_environment_requires_both_native_and_gateway_success(self):
        operation = self.operation | {'status': 'succeeded', 'result': {'ready': True}}
        args = {'case': 'empty-environment', 'selected': {'stdout': json.dumps({'ready': True, 'instance_id': 17})},
                'operation': operation, 'instance': self.instance | {'status': 'active'},
                'workload': {'empty': True, 'environment_file': True}}
        self.assertTrue(self.validate(**args)['passed'])
        for change in ({'workload': {'empty': True}}, {'operation': operation | {'result': {}}}):
            with self.assertRaises(RuntimeError):
                self.validate(**(args | change))


if __name__ == '__main__':
    unittest.main()
