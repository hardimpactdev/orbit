"""Private F13 requests use the existing operator SDK and stdin transport."""
import copy
import io
import json
import stat
import tempfile
import unittest
from contextlib import redirect_stderr
from datetime import UTC, datetime
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock

import swamp_proof as proof
import swamp_proof_api as private
import swamp_proof_requests as requests
import swamp_proof_run as runner


class PrivateRequestTest(unittest.TestCase):
    def setUp(self):
        self.fixture = {'secrets': [{'name': 'password', 'value': 'generate:hex32'}],
                        'request': {'api': {'method': 'POST', 'path': '/api/v1/database-connections/${capture.slug}/users',
                                           'body': {'password': '${secret.password}', 'read_only': False, 'node_id': '${capture.node}'}},
                                    'operation': '$.data.operation.id'}}
        self.now = datetime(2026, 11, 1, tzinfo=UTC)

    def test_validation_keeps_generated_values_only_in_api_body(self):
        requests.validate_commands(self.fixture)
        for location in ('envelope', 'before_request', 'parity'):
            invalid = dict(self.fixture, **{location: {'unexpected': '${secret.password}'}})
            with self.subTest(location=location), self.assertRaises(proof.ProofError):
                requests.validate_commands(invalid)
        for change in ({'method': 'GET'}, {'path': 'https://another/api/v1/users'}, {'path': '/api/v1/users?password=x'},
                       {'body': {'password': '${secret.unknown}'}}, {'body': {'${secret.password}': 'x'}}):
            invalid = copy.deepcopy(self.fixture)
            invalid['request']['api'].update(change)
            with self.subTest(change=change), self.assertRaises(proof.ProofError):
                requests.validate_commands(invalid)
        for entries in ([{'name': 'password', 'value': 'fixed'}], self.fixture['secrets'] * 2):
            with self.subTest(entries=entries), self.assertRaises(proof.ProofError):
                requests.validate_commands(dict(self.fixture, secrets=entries))

    def test_private_body_preserves_capture_types_and_receipt_has_only_names(self):
        first, second = private.generate(self.fixture), private.generate(self.fixture)
        self.assertNotEqual(first, second)
        self.assertRegex(first['password'], r'^[a-f0-9]{64}$')
        context = {'capture.slug': 'proof-db', 'capture.node': 7}
        body, public = private.private_api(self.fixture['request']['api'], context, self.now, first)
        self.assertEqual(body, {'password': first['password'], 'node_id': 7, 'read_only': False})
        self.assertEqual(public['path'], '/api/v1/database-connections/proof-db/users')
        self.assertEqual(public['body']['password'], '[REDACTED:password]')
        self.assertNotIn(first['password'], json.dumps(public))
        top = Mock(private_values=first)
        top.node.return_value = {'data': {'operation': {'id': 73}}}
        self.assertEqual(runner.admit(top, self.fixture, context, self.now), 73)
        argv = top.node.call_args.args[1]
        self.assertEqual(argv[:2], ['php', '/home/orbit/orbit/tools/swamp-proof-request.php'])
        self.assertNotIn(first['password'], json.dumps(argv))
        self.assertEqual(json.loads(top.node.call_args.kwargs['stdin']), body)
        self.assertEqual(top.node.call_count, 1)

    def test_operator_transport_keeps_body_in_private_file_and_removes_it_after_failure(self):
        paths = []
        secret = private.generate(self.fixture)['password']
        def execute(argv, **kwargs):
            self.assertNotIn(secret, json.dumps(argv))
            path = Path(next(arg.split('=', 1)[1] for arg in argv if arg.startswith('--argv-file=')))
            paths.append(path)
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)
            self.assertEqual(json.loads(path.read_text())['stdin'], json.dumps({'password': secret}))
            return SimpleNamespace(returncode=1, stdout=json.dumps({'state': 'executed', 'exit_code': 1, 'stdout': '', 'stderr': 'refused ' + secret}), stderr=secret)
        top = runner.Topology({'worktree': str(proof.ROOT), 'issue': 'TASK-42'}, execute_command=execute)
        top.private_values = {'password': secret}
        errors = io.StringIO()
        with redirect_stderr(errors), self.assertRaises(proof.ProofError):
            top.node('operator', ['php', 'public-helper.php'], stdin=json.dumps({'password': secret}))
        self.assertNotIn(secret, errors.getvalue())
        self.assertIn('[REDACTED:password]', errors.getvalue())
        self.assertTrue(top.private_leak)
        self.assertTrue(paths)
        self.assertTrue(all(not path.exists() for path in paths))

    def test_parity_secret_file_is_private_and_deleted_with_its_scope(self):
        values = private.generate(self.fixture)
        with tempfile.TemporaryDirectory() as tmp:
            path = private.secret_file(tmp, values)
            self.assertEqual(json.loads(path.read_text()), values)
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)
        self.assertFalse(path.exists())

    def test_api_failure_never_repeats_a_request(self):
        top = Mock(private_values=private.generate(self.fixture))
        top.node.side_effect = proof.ProofError('refused')
        with self.assertRaises(proof.ProofError):
            runner.admit(top, self.fixture, {'capture.slug': 'proof', 'capture.node': 7}, self.now)
        self.assertEqual(top.node.call_count, 1)

    def test_fixture_accepts_and_copies_explicit_limitations(self):
        original = proof.fixture_for('firewall.inspect')
        for value in (None, 'unchecked', ['']):
            fixture = dict(original, limitations=value)
            with tempfile.TemporaryDirectory() as tmp:
                root = Path(tmp)
                (root / 'swamp/proof').mkdir(parents=True)
                (root / 'swamp/proof/firewall.inspect.json').write_text(json.dumps(fixture))
                with self.subTest(value=value), self.assertRaisesRegex(proof.ProofError, 'limitations'):
                    proof.fixture_for('firewall.inspect', root)


if __name__ == '__main__':
    unittest.main()
