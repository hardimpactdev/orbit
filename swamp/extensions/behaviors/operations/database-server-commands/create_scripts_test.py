"""Script ownership, SQL policy and private-material boundaries without live Nodes."""

import contextlib
import io
import json
import stat
import subprocess
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).parent / 'scripts'))
import create_common as common
import create_sql as sql
import create_valkey as valkey


class CreateActions(unittest.TestCase):
    def setUp(self):
        self.i = {'repair': False, 'driver': 'pgsql', 'username': 'proof_owner', 'databases': ['proof_main', 'proof_test'], 'test_prefix': None, 'locale': 'C.UTF-8', 'host': '127.0.0.1', 'port': 5432, 'container': 'orbit-process-42-proof', 'index': None, 'patterns': [], 'allow': []}
        self.m = {'password': 'private-root-sentinel', 'payload': {'password': 'a' * 40, 'salt': 'YWFhYWFhYWFhYWFhYWFhYQ=='}}

    def test_scram_matches_existing_gateway_compatible_verifier(self):
        self.assertEqual(common.verifier(self.m['payload']['password'], self.m['payload']['salt']), 'SCRAM-SHA-256$4096:YWFhYWFhYWFhYWFhYWFhYQ==$2qQsV9GfwFmj0qVexvYaopopK+gAzzo8xP18Uo3bmZ8=:fDfnVsREThjblq2p3DIeMCGsfhdvYWlbi5hKKVD+1Cc=')

    def test_unsafe_system_names_prefixes_material_and_pool_size_refuse_before_client(self):
        for change in [{'databases': ['mysql']}, {'databases': ['template0']}, {'databases': ['a'] * 35}, {'username': 'x;drop'}, {'driver': 'mysql', 'test_prefix': 'm'}, {'container': '--privileged'}]:
            with self.assertRaises(common.Refused):
                common.validate({**self.i, **change}, self.m)
        with self.assertRaises(common.Refused):
            common.validate(self.i, {**self.m, 'password': 'private\nunsafe'})

    def test_missing_repair_and_foreign_role_are_refused_without_mutation(self):
        for response in ['', 'proof_owner\n', 'proof_owner\nforeign-hash\n']:
            with patch.object(sql, 'sql', return_value=response) as client:
                with self.assertRaisesRegex(common.Refused, 'database.name_conflict'):
                    sql.probe_user({**self.i, 'repair': True}, self.m, {})
                self.assertEqual(client.call_count, 1)

    def test_pg_owned_role_accepts_stored_salt_without_exposing_it(self):
        value = common.verifier(self.m['payload']['password'], self.m['payload']['salt'])
        with patch.object(sql, 'sql', return_value='proof_owner\n' + value + '\n'):
            self.assertEqual(sql.probe_user(self.i, self.m, {}), {'ready': True})

    def test_foreign_database_refusal_precedes_changes_for_both_engines(self):
        for driver, response in [('pgsql', 'proof_main\n'), ('mysql', 'proof_main\t0\n')]:
            with patch.object(sql, 'sql', return_value=response):
                with self.assertRaisesRegex(common.Refused, 'database.name_conflict'):
                    sql.probe_databases({**self.i, 'driver': driver}, self.m, {})

    def test_grants_escape_underscores_and_cannot_select_unadmitted_scopes(self):
        i = {**self.i, 'driver': 'mysql', 'test_prefix': 'proof_test'}
        with patch.object(sql, 'sql', return_value='') as client:
            sql.grant_mysql(i, self.m, {'name': 'proof_main', 'wildcard': False})
            self.assertIn('`proof\\_main`.*', client.call_args.args[2])
            sql.grant_mysql(i, self.m, {'name': 'proof_test', 'wildcard': True})
            self.assertIn('`proof\\_test%`.*', client.call_args.args[2])
            with self.assertRaises(common.Refused):
                sql.grant_mysql(i, self.m, {'name': 'foreign', 'wildcard': False})
            self.assertEqual(client.call_count, 2)

    def test_pg_database_creation_is_conditional_outside_transaction_and_uses_admitted_locale(self):
        with patch.object(sql, 'sql', return_value='') as client:
            sql.create_database(self.i, self.m, {'database': 'proof_main'})
            statement = client.call_args.args[2]
            self.assertIn('NOT EXISTS', statement)
            self.assertIn('\\gexec', statement)
            self.assertIn('LOCALE_PROVIDER builtin', statement)
            self.assertNotIn('DROP', statement)

    def test_pg_each_mutation_keeps_owner_assertion_and_separate_policy_action(self):
        for action, expected in [('revoke_database', 'REVOKE ALL ON DATABASE'), ('revoke_schema', 'REVOKE CREATE ON SCHEMA'), ('grant_schema', 'GRANT ALL ON SCHEMA')]:
            with patch.object(sql, 'sql', return_value='') as client:
                sql.postgres_scope(self.i, self.m, {'database': 'proof_main'}, action)
                statement = client.call_args.args[2]
                self.assertIn('datdba IS DISTINCT FROM', statement)
                self.assertIn(expected, statement)
                self.assertEqual(client.call_count, 1)

    def test_main_catches_private_exceptions_without_native_diagnostics(self):
        output = io.StringIO()
        with patch.object(sys, 'argv', ['action', json.dumps(self.i), '{}']), patch.object(sys, 'stdin', io.StringIO(json.dumps(self.m))), contextlib.redirect_stdout(output):
            with self.assertRaises(SystemExit) as status:
                common.main(lambda *_: (_ for _ in ()).throw(RuntimeError(self.m['password'])))
        self.assertEqual(status.exception.code, 1)
        self.assertEqual(json.loads(output.getvalue()), {'error_code': 'database.create_outcome_unknown'})

    def test_valkey_private_setuser_uses_tool_stdin_and_removed_0600_env_file(self):
        i = {**self.i, 'driver': 'redis'}
        paths = []
        def command(argv, data):
            self.assertNotIn(self.m['password'], repr(argv))
            self.assertNotIn('private-hash', repr(argv))
            path = Path(argv[argv.index('--env-file') + 1])
            paths.append(path)
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)
            self.assertEqual(path.read_text(), 'REDISCLI_AUTH=' + self.m['password'] + '\n')
            self.assertIn('private-hash', data)
            return subprocess.CompletedProcess(argv, 0, '"OK"\n', '')
        with patch.object(common, 'command', command):
            self.assertEqual(common.valkey(i, self.m, 'ACL', 'SETUSER', 'proof', '#private-hash', private=True), 'OK')
        self.assertTrue(all(not p.exists() for p in paths))

    def test_valkey_flushdb_survives_the_final_dangerous_deny_only_when_requested(self):
        calls = []
        def valkey_call(i, m, *argv, private=False):
            calls.append(argv)
            return 'OK'
        with patch.object(valkey, 'valkey', valkey_call):
            for allow in (['@read', 'flushdb'], ['@read']):
                valkey.set_user({**self.i, 'driver': 'redis', 'username': 'proof', 'patterns': ['proof:*'], 'allow': allow}, self.m, None)
        flush, plain = calls
        self.assertEqual(flush[flush.index('-@dangerous'):], ('-@dangerous', '-function', '-script', '+flushdb'))
        self.assertEqual(plain[plain.index('-@dangerous'):], ('-@dangerous', '-function', '-script'))
        self.assertNotIn('+flushall', flush)

    def test_valkey_auth_failure_diagnostics_refuse_even_with_successful_json(self):
        reply = subprocess.CompletedProcess([], 0, '{"databases":"16"}', 'AUTH failed: private-root-sentinel')
        with patch.object(common, 'command', return_value=reply):
            with self.assertRaisesRegex(common.Refused, '^database.server_command_failed$'):
                common.valkey({**self.i, 'driver': 'redis'}, self.m, 'CONFIG', 'GET', 'databases')

    def test_valkey_out_of_range_or_foreign_user_is_read_only(self):
        i = {**self.i, 'driver': 'redis', 'index': 16}
        with patch.object(valkey, 'valkey', return_value={'databases': '16'}) as client:
            with self.assertRaisesRegex(common.Refused, 'database.index_invalid'):
                valkey.index(i, self.m, {})
            self.assertEqual(client.call_count, 1)
        with patch.object(valkey, 'valkey', return_value={'passwords': ['foreign']}) as client:
            with self.assertRaisesRegex(common.Refused, 'database.name_conflict'):
                valkey.user(i, self.m, {})
            self.assertEqual(client.call_count, 1)

    def test_valkey_save_failure_never_deletes_or_replaces_user(self):
        with patch.object(valkey, 'valkey', return_value='ERR save failed') as client:
            with self.assertRaises(common.Refused):
                valkey.save(self.i, self.m, {})
            self.assertEqual(client.call_args.args[2:], ('ACL', 'SAVE'))


if __name__ == '__main__':
    unittest.main()
