"""The named Valkey primitive keeps the old ACL refusal and compensation behavior."""
from pathlib import Path
import runpy
import unittest


class Outcome(Exception):
    def __init__(self, outcome, data):
        self.outcome, self.data = outcome, data


PROGRAM = runpy.run_path(str(Path(__file__).resolve().parents[4] / 'resources/remote/database/valkey.py'), init_globals={'run_program': lambda main: None, 'Outcome': Outcome})


class Client:
    def __init__(self, replies=None, fail=None):
        self.calls = []
        self.replies = replies or {}
        self.fail = fail

    def command(self, *args):
        self.calls.append(args)
        if self.fail and self.fail(args):
            raise PROGRAM['RedisError']()
        return self.replies.get(args, 'OK')


class ValkeyProgramTest(unittest.TestCase):
    def test_create_denies_admin_dangerous_function_and_script_after_allows(self):
        client = Client()
        PROGRAM['apply'](client, 'create', {'username': 'queue', 'hash': 'a' * 64, 'patterns': ['queue:*'], 'allow': ['@all']}, 'secret')
        self.assertEqual(client.calls[0][-4:], ('-@admin', '-@dangerous', '-function', '-script'))
        self.assertIn('+@all', client.calls[0])
        self.assertEqual(client.calls[-1], ('ACL', 'SAVE'))
        self.assertNotIn('secret', repr(client.calls))

    def test_save_failure_removes_the_unsaved_new_user(self):
        client = Client(fail=lambda args: args == ('ACL', 'SAVE'))
        with self.assertRaises(PROGRAM['RedisError']):
            PROGRAM['apply'](client, 'create', {'username': 'queue', 'hash': 'a' * 64, 'patterns': ['queue:*'], 'allow': ['@list']}, 'secret')
        self.assertEqual(client.calls[-1], ('ACL', 'DELUSER', 'queue'))

    def test_invalid_acl_rules_return_only_a_fixed_code(self):
        client = Client(fail=lambda args: args[:2] == ('ACL', 'SETUSER'))
        with self.assertRaises(Outcome) as refused:
            PROGRAM['apply'](client, 'create', {'username': 'queue', 'hash': 'a' * 64, 'patterns': ['queue:*'], 'allow': ['invalid']}, 'secret')
        self.assertEqual(refused.exception.data, {'error_code': 'database.acl_rules_invalid'})

    def test_remove_preserves_default_and_restores_users_after_failed_save(self):
        client = Client({('ACL', 'LIST'): ['user default on nopass ~* +@all', 'user queue on #aaaa ~queue:* +@list']}, fail=lambda args: args == ('ACL', 'SAVE') and len([call for call in client.calls if call == ('ACL', 'SAVE')]) == 1)
        with self.assertRaises(PROGRAM['RedisError']):
            PROGRAM['apply'](client, 'remove', {'usernames': ['queue', 'default']}, 'secret')
        self.assertIn(('ACL', 'DELUSER', 'queue'), client.calls)
        self.assertIn(('ACL', 'SETUSER', 'queue', 'reset', 'on', '#aaaa', '~queue:*', '+@list'), client.calls)
        self.assertNotIn(('ACL', 'DELUSER', 'default'), client.calls)

    def test_close_refuses_other_clients_before_changing_authentication(self):
        client = Client({('CLIENT', 'ID'): 7, ('CLIENT', 'LIST', 'USER', 'default'): 'id=7 addr=127.0.0.1\nid=8 addr=127.0.0.2'})
        with self.assertRaises(Outcome) as refused:
            PROGRAM['apply'](client, 'close', {}, 'secret')
        self.assertEqual(refused.exception.data, {'error_code': 'database.server_in_use'})
        self.assertFalse(any(call[0] == 'ACL' for call in client.calls))

    def test_converge_keeps_an_open_default_and_removes_only_stale_users(self):
        client = Client({('ACL', 'LIST'): ['user default on nopass ~* +@all', 'user stale on nopass ~* +@all']})
        PROGRAM['apply'](client, 'converge', {'users': [], 'default_open': True}, 'secret')
        self.assertEqual(client.calls[-2:], [('ACL', 'DELUSER', 'stale'), ('ACL', 'SAVE')])

    def test_converge_changes_only_differing_users_and_preserves_command_order(self):
        client = Client({('ACL', 'LIST'): [
            'user unchanged sanitize-payload on #aaaa #bbbb ~b ~a &* -@all +@list',
            'user changed on #cccc ~* +@list -@all',
        ]})
        PROGRAM['apply'](client, 'converge', {'users': [
            {'name': 'unchanged', 'rules': 'on ~a ~b &* -@all +@list', 'hashes': ['bbbb', 'aaaa']},
            {'name': 'changed', 'rules': 'on ~* -@all +@list', 'hashes': ['cccc']},
        ], 'default_open': False}, 'secret')
        sets = [call for call in client.calls if call[:2] == ('ACL', 'SETUSER')]
        self.assertEqual([call[2] for call in sets], ['changed'])



if __name__ == '__main__':
    unittest.main()
