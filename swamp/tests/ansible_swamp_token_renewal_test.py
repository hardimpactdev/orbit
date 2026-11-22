"""Exercise the Swamp Node token renewal decisions against a fake serve; every secret here is a fake."""
import datetime
import importlib.util
import json
import os
import tempfile
import time
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('orbit_swamp_token_renewal', ROOT / 'ansible/library/orbit_swamp_token_renewal.py')
renewal = importlib.util.module_from_spec(spec)
spec.loader.exec_module(renewal)

START = datetime.datetime(2026, 11, 21, 12, 0, tzinfo=datetime.UTC)
TODAY = '20261121'


class Failed(Exception):
    pass


class FakeServe:
    """Access tokens, enrollment tokens and worker units as serve and systemd report them; secrets are `<name>.fake-secret-<n>`."""

    def __init__(self, expires):
        self.access = {name: {'name': name, 'principalId': principal, 'expiresAt': at, 'state': 'active'}
                       for name, (principal, at) in expires['access'].items()}
        self.enrollment = {name: {'name': name, 'expiresAt': at, 'state': 'enrolled', 'generation': 0}
                           for name, at in expires['enrollment'].items()}
        self.started = {}
        self.calls = []
        self.argvs = []
        # swamp 20261114 lists access tokens as a plain array; 'envelope' is the older {"tokens": [...]}.
        self.shape = 'array'
        self.crash_on = None
        self.lose_reply = None
        self.mint_principal = None
        self.garbage = None
        self.on_list = None

    def __call__(self, argv):
        if argv[0] == 'systemctl':
            slot = argv[2].split('@')[1].split('.')[0]
            return 0, '%d\n' % self.started.get(slot, 0), ''
        self.argvs.append(argv)
        args = argv[argv.index('/usr/local/bin/swamp') + 1:argv.index('--server')]
        call = ' '.join(args)
        self.calls.append(call)
        if self.crash_on and call.startswith(self.crash_on):
            self.crash_on = None
            raise Failed('crash before ' + call)
        if self.garbage and call.startswith(self.garbage):
            return 0, 'not json ' + args[3] + '.fake-secret-garbage', ''
        if self.on_list and args == ['access', 'token', 'list']:
            self.on_list(argv)
        result = self.answer(args)
        if self.lose_reply and call.startswith(self.lose_reply):
            self.lose_reply = None
            raise Failed('lost reply to ' + call)
        return result

    def answer(self, args):
        match args:
            case ['access', 'token', 'list']:
                rows = list(self.access.values())
                listed = rows if self.shape == 'array' else {'tokens': rows} if self.shape == 'envelope' else {'rows': rows}
                return 0, json.dumps(listed), ''
            case ['access', 'token', 'mint', name, '--principal', principal, '--duration', '30d']:
                self.access[name] = {'name': name, 'principalId': self.mint_principal or principal, 'state': 'active',
                                     'expiresAt': (START + datetime.timedelta(days=30)).isoformat()}
                return 0, json.dumps({'name': name}), ''
            case ['access', 'token', 'reveal', name, '--yes']:
                return 0, json.dumps({'name': name, 'token': name + '.fake-secret-0'}), ''
            case ['access', 'token', 'revoke', name]:
                self.access[name]['state'] = 'revoked'
                return 0, '{}', ''
            case ['worker', 'token', 'list']:
                return 0, json.dumps(list(self.enrollment.values())), ''
            case ['worker', 'token', 'rotate', name]:
                self.enrollment[name]['generation'] += 1
                self.enrollment[name]['expiresAt'] = (START + datetime.timedelta(days=30)).isoformat()
                return 0, json.dumps({'name': name, 'token': '%s.fake-secret-%d' % (name, self.enrollment[name]['generation'])}), ''
            case ['worker', 'token', 'reveal', name, '--yes']:
                return 0, json.dumps({'name': name, 'token': '%s.fake-secret-%d' % (name, self.enrollment[name]['generation'])}), ''
        return 1, '', 'unknown command'


class TokenRenewalTest(unittest.TestCase):
    def setUp(self):
        self.folder = Path(tempfile.mkdtemp())
        (self.folder / 'home/.config/swamp').mkdir(parents=True)
        (self.folder / 'credentials/a').mkdir(parents=True)
        soon = (START + datetime.timedelta(days=3)).isoformat()
        self.later = (START + datetime.timedelta(days=25)).isoformat()
        self.serve = FakeServe({'access': {'serve-driver': ('user:nckrtl', soon),
                                           'swamp-role-209-a-access': ('worker:orbit-node-209', soon),
                                           'orbit-node-209-access': ('worker:orbit-node-209', soon)},
                                'enrollment': {'swamp-role-209-a': soon, 'orbit-node-209': soon}})
        self.secret('home/.config/swamp/serve-driver-token', 'serve-driver.fake-secret-old')
        self.secret('credentials/a/server.token', 'swamp-role-209-a-access.fake-secret-old')
        self.secret('credentials/a/enrollment.token', 'swamp-role-209-a.fake-secret-old')

    def tearDown(self):
        for path in sorted(self.folder.rglob('*'), reverse=True):
            path.rmdir() if path.is_dir() else path.unlink()
        self.folder.rmdir()

    def secret(self, path, value):
        (self.folder / path).write_text(value + '\n')

    def read(self, path):
        return (self.folder / path).read_text().strip()

    def run_at(self, hours, slots=('a',)):
        now = START + datetime.timedelta(hours=hours)

        def fail(**result):
            raise Failed(result['msg'])

        module = SimpleNamespace(params={
            'swamp': '/usr/local/bin/swamp', 'swamp_env': '/usr/local/lib/orbit-swamp/swamp-env', 'server': 'ws://127.0.0.1:9418', 'user': 'orbit',
            'home': str(self.folder / 'home'), 'slots': list(slots),
            'credentials': str(self.folder / 'credentials'), 'state_file': str(self.folder / 'state/state.json'),
            'renew_days': 10, 'grace_hours': 24, 'duration': '30d', 'now': None,
        }, run_command=self.serve, fail_json=fail)
        with patch.object(renewal.Renewal, 'owner', return_value=(os.getuid(), os.getgid())), patch('os.fchown'):
            return renewal.Renewal(module, now).run()

    def age(self, path, hours):
        """Sets a file's mtime `hours` before the run's clock, as the switch would have left it."""
        at = (START - datetime.timedelta(hours=hours)).timestamp()
        os.utime(self.folder / path, (at, at))

    def start_worker(self, seconds_after_switch, slot='a'):
        """systemd's monotonic start of a slot's worker process, relative to when server.token last changed."""
        changed = time.monotonic() - (time.time() - os.stat(self.folder / 'credentials' / slot / 'server.token').st_mtime)
        self.serve.started[slot] = int((changed + seconds_after_switch) * 1_000_000)

    def test_every_swamp_call_sources_the_credential_env_through_swamp_env_as_the_serve_account(self):
        self.run_at(0)
        self.assertTrue(self.serve.argvs)
        for argv in self.serve.argvs:
            self.assertEqual(argv[:4], ['runuser', '-u', 'orbit', '--'])
            at = argv.index('/usr/local/bin/swamp')
            self.assertEqual(argv[at - 1], '/usr/local/lib/orbit-swamp/swamp-env')
            self.assertIn('HOME=' + str(self.folder / 'home'), argv[:at])

    def test_the_older_tokens_envelope_renews_like_the_plain_array(self):
        self.serve.shape = 'envelope'
        self.assertEqual(self.run_at(0)[0], 'minted serve-driver-' + TODAY)

    def test_an_unknown_token_list_shape_fails_closed_and_changes_no_file(self):
        self.serve.shape = 'unknown'
        with self.assertRaisesRegex(Failed, 'token list of an unknown shape'):
            self.run_at(0)
        self.assertEqual(self.read('home/.config/swamp/serve-driver-token'), 'serve-driver.fake-secret-old')

    def test_nothing_is_due_so_nothing_changes(self):
        for row in [*self.serve.access.values(), *self.serve.enrollment.values()]:
            row['expiresAt'] = self.later
        self.assertEqual(self.run_at(0), [])
        self.assertEqual([call for call in self.serve.calls if 'list' not in call], [])
        self.assertEqual(self.read('home/.config/swamp/serve-driver-token'), 'serve-driver.fake-secret-old')

    def test_due_tokens_mint_a_dated_generation_verify_and_switch_without_rotate_or_revoke(self):
        actions = self.run_at(0)
        self.assertIn('minted serve-driver-' + TODAY, actions)
        self.assertIn('minted swamp-role-209-a-access-' + TODAY, actions)
        self.assertEqual(self.read('home/.config/swamp/serve-driver-token'), 'serve-driver-%s.fake-secret-0' % TODAY)
        self.assertEqual(self.read('credentials/a/server.token'), 'swamp-role-209-a-access-%s.fake-secret-0' % TODAY)
        self.assertEqual(self.read('credentials/a/enrollment.token'), 'swamp-role-209-a.fake-secret-1')
        self.assertFalse([call for call in self.serve.calls if 'access token rotate' in call or 'revoke' in call])
        self.assertEqual(self.serve.access['swamp-role-209-a-access-' + TODAY]['principalId'], 'worker:orbit-node-209')
        # The serve-driver candidate is used for a list before the switch.
        mint = self.serve.calls.index('access token mint serve-driver-%s --principal user:nckrtl --duration 30d' % TODAY)
        self.assertIn('access token list', self.serve.calls[mint + 2:mint + 3])
        self.assertFalse([path for path in self.folder.rglob('.*') if path.is_file()])
        self.assertNotIn('fake-secret', (self.folder / 'state/state.json').read_text())
        self.assertNotIn('fake-secret', json.dumps(actions))

    def test_a_refused_worker_candidate_leaves_server_token_untouched(self):
        self.serve.mint_principal = 'user:someone-else'
        with self.assertRaises(Failed) as failure:
            self.run_at(0)
        self.assertIn('server.token was not changed', str(failure.exception))
        self.assertEqual(self.read('credentials/a/server.token'), 'swamp-role-209-a-access.fake-secret-old')
        self.assertFalse([path for path in self.folder.rglob('.*') if path.is_file()])

    def test_out_of_scope_tokens_are_never_touched(self):
        self.run_at(0)
        self.age('home/.config/swamp/serve-driver-token', 30)
        self.start_worker(60)
        self.run_at(1)
        touched = [call for call in self.serve.calls if {'orbit-node-209', 'orbit-node-209-access'} & set(call.split())]
        self.assertEqual(touched, [])
        self.assertEqual(self.serve.access['orbit-node-209-access']['state'], 'active')

    def test_the_old_worker_token_stays_until_the_worker_process_started_after_the_switch(self):
        self.run_at(0)
        # A connection just before the replacement, and a same-process reconnect, both still hold the old token.
        self.start_worker(-1)
        self.run_at(30)
        self.assertEqual(self.serve.access['swamp-role-209-a-access']['state'], 'active')
        self.start_worker(5)
        self.assertIn('revoked swamp-role-209-a-access', self.run_at(31))
        self.assertEqual(self.serve.access['swamp-role-209-a-access']['state'], 'revoked')

    def test_the_old_serve_driver_stays_until_its_file_is_a_day_old(self):
        self.run_at(0)
        self.age('home/.config/swamp/serve-driver-token', 23)
        self.run_at(0)
        self.assertEqual(self.serve.access['serve-driver']['state'], 'active')
        self.age('home/.config/swamp/serve-driver-token', 24)
        self.assertIn('revoked serve-driver', self.run_at(0))
        self.assertEqual(self.serve.access['serve-driver-' + TODAY]['state'], 'active')
        self.assertEqual(self.run_at(48), [])

    def test_a_rerun_after_a_crash_or_lost_reply_at_any_step_finishes_once_and_keeps_the_predecessor(self):
        steps = [('crash_on', 'access token mint'), ('lose_reply', 'access token mint'), ('crash_on', 'access token reveal'),
                 ('lose_reply', 'worker token rotate'), ('crash_on', 'worker token reveal')]
        for fault, step in steps:
            with self.subTest(fault=fault, step=step):
                self.setUp()
                setattr(self.serve, fault, step)
                with self.assertRaises(Failed):
                    self.run_at(0)
                self.run_at(0)
                self.assertEqual(self.read('home/.config/swamp/serve-driver-token'), 'serve-driver-%s.fake-secret-0' % TODAY)
                self.assertEqual(self.read('credentials/a/server.token'), 'swamp-role-209-a-access-%s.fake-secret-0' % TODAY)
                # One rotate only: a second would retire the secret the connected worker still holds.
                self.assertEqual(self.serve.enrollment['swamp-role-209-a']['generation'], 1)
                self.assertEqual(self.read('credentials/a/enrollment.token'), 'swamp-role-209-a.fake-secret-1')
                self.assertEqual([name for name in self.serve.access if name.startswith('serve-driver-')], ['serve-driver-' + TODAY])
                # The predecessor is found again from the live list, with no state: it is revoked once unused.
                self.age('home/.config/swamp/serve-driver-token', 25)
                self.assertIn('revoked serve-driver', self.run_at(1))
                self.tearDown()
        self.setUp()

    def test_a_revoked_name_of_today_gets_a_numbered_suffix(self):
        self.serve.access['serve-driver-' + TODAY] = {'name': 'serve-driver-' + TODAY, 'principalId': 'user:nckrtl',
                                                      'state': 'revoked', 'expiresAt': self.later}
        self.assertIn('minted serve-driver-%s-2' % TODAY, self.run_at(0))
        self.assertEqual(self.read('home/.config/swamp/serve-driver-token'), 'serve-driver-%s-2.fake-secret-0' % TODAY)

    def test_malformed_output_never_reaches_the_failure(self):
        self.serve.garbage = 'worker token rotate'
        with self.assertRaises(Failed) as failure:
            self.run_at(0)
        self.assertNotIn('fake-secret', str(failure.exception))
        self.assertIn('worker token rotate', str(failure.exception))

    def test_families_cover_dated_numbered_and_legacy_names(self):
        self.assertEqual(renewal.family('orbit-gateway-read-20261121'), 'orbit-gateway-read')
        self.assertEqual(renewal.family('serve-driver-20261121-2'), 'serve-driver')
        self.assertEqual(renewal.family('serve-driver-20261121T120000Z'), 'serve-driver')
        self.assertEqual(renewal.family('swamp-role-209-a-access'), 'swamp-role-209-a-access')

    def test_worker_started_during_verification_does_not_revoke_predecessor_for_any_slot(self):
        soon = (START + datetime.timedelta(days=3)).isoformat()
        for slot in ('b', 'c'):
            (self.folder / 'credentials' / slot).mkdir(parents=True)
            self.serve.access['swamp-role-209-%s-access' % slot] = {
                'name': 'swamp-role-209-%s-access' % slot, 'principalId': 'worker:orbit-node-209', 'expiresAt': soon, 'state': 'active'}
            self.serve.enrollment['swamp-role-209-%s' % slot] = {
                'name': 'swamp-role-209-%s' % slot, 'expiresAt': soon, 'state': 'enrolled', 'generation': 0}
            self.secret('credentials/%s/server.token' % slot, 'swamp-role-209-%s-access.fake-secret-old' % slot)
            self.secret('credentials/%s/enrollment.token' % slot, 'swamp-role-209-%s.fake-secret-old' % slot)

        def start_during_verify(argv):
            # When verify_listed runs after mint, the temporary file exists on disk while server.token still holds the predecessor.
            for slot in ('a', 'b', 'c'):
                temp_files = list((self.folder / 'credentials' / slot).glob('.server.token.*'))
                if temp_files and slot not in self.serve.started:
                    time.sleep(0.02)
                    self.serve.started[slot] = int(time.monotonic() * 1_000_000)
                    time.sleep(0.02)

        self.serve.on_list = start_during_verify
        self.run_at(0, slots=('a', 'b', 'c'))
        self.serve.on_list = None
        # Next run must NOT revoke any predecessor because every worker started before atomic rename + mtime update.
        self.run_at(1, slots=('a', 'b', 'c'))
        for slot in ('a', 'b', 'c'):
            self.assertEqual(self.serve.access['swamp-role-209-%s-access' % slot]['state'], 'active', slot)
        # Once each worker starts after publication, all three predecessors are revoked.
        for slot in ('a', 'b', 'c'):
            self.start_worker(5, slot=slot)
        actions = self.run_at(2, slots=('a', 'b', 'c'))
        for slot in ('a', 'b', 'c'):
            self.assertIn('revoked swamp-role-209-%s-access' % slot, actions)
            self.assertEqual(self.serve.access['swamp-role-209-%s-access' % slot]['state'], 'revoked', slot)

    def test_enrollment_fails_closed_on_missing_or_unknown_state(self):
        for bad_state in (None, '', 'pending', 'revoked'):
            with self.subTest(state=bad_state):
                self.setUp()
                if bad_state is None:
                    self.serve.enrollment['swamp-role-209-a'].pop('state', None)
                else:
                    self.serve.enrollment['swamp-role-209-a']['state'] = bad_state
                with self.assertRaises(Failed) as failure:
                    self.run_at(0)
                self.assertIn('valid state', str(failure.exception))
                self.tearDown()
        self.setUp()

    def test_undelimited_secret_in_any_credential_file_fails_without_printing_secret(self):
        canary = 'CANARY_SECRET_NO_DOT_ANSIBLE'
        for rel in ('home/.config/swamp/serve-driver-token', 'credentials/a/server.token', 'credentials/a/enrollment.token'):
            with self.subTest(path=rel):
                self.setUp()
                self.secret(rel, canary)
                with self.assertRaises(Failed) as failure:
                    self.run_at(0)
                self.assertNotIn(canary, str(failure.exception))
                self.assertIn(os.path.basename(rel), str(failure.exception))
                self.assertIn('delimited <name>.<secret>', str(failure.exception))
                self.tearDown()
        self.setUp()


if __name__ == '__main__':
    unittest.main()
