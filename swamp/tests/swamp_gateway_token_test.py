"""Run the Gateway's Swamp Node token entrypoint against a fake swamp that answers with the pinned CLI's recorded output shapes."""
import datetime
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'ansible/files/swamp-gateway-token.sh'
TODAY = datetime.datetime.now(datetime.UTC).strftime('%Y%m%d')
LATER = (datetime.datetime.now(datetime.UTC) + datetime.timedelta(days=30)).strftime('%Y-%m-%dT%H:%M:%S.000Z')
SOON = (datetime.datetime.now(datetime.UTC) + datetime.timedelta(days=2)).strftime('%Y-%m-%dT%H:%M:%S.000Z')

SWAMP_ENV = ROOT / 'ansible/files/swamp-env.sh'
CLUB_CANARY = 'CLUB_ENV_CANARY_7731'
# Like swamp on the Swamp Node, the fake refuses with auth_gate_blocked unless the Swamp Club credential env was sourced.
# The fake keeps its tokens in a JSON file and answers as swamp 20261114 on Node 209 does: `access token list` and
# `worker token list` are plain arrays. `shape` swaps in the older {"tokens":[...]} envelope or an unknown shape.
FAKE = r'''#!/usr/bin/env python3
import json, os, sys
if os.environ.get('SWAMP_API_KEY') != 'fake-club-key':
    print('{"error":{"code":"auth_gate_blocked"}}', file=sys.stderr)
    sys.exit(1)
state_path = sys.argv[0] + '.json'
state = json.load(open(state_path))
args = sys.argv[1:sys.argv.index('--server')]
state['calls'].append(' '.join(args))
out = {}
if args[:3] == ['access', 'token', 'list']:
    shape = state.get('shape', 'array')
    out = state['access'] if shape == 'array' else {'tokens': state['access']} if shape == 'envelope' else {'rows': state['access']}
elif args[:3] == ['worker', 'token', 'list']:
    out = state['enrollment']
elif args[:3] == ['access', 'token', 'mint']:
    state['access'].append({'name': args[3], 'principalId': args[5], 'expiresAt': state['later'], 'state': 'active',
                            'effectiveState': 'active'})
    out = {'name': args[3], 'expiresAt': state['later']}
elif args[:3] == ['access', 'token', 'reveal']:
    out = {'name': args[3], 'token': args[3] + '.fake-secret-0', 'expired': False}
elif args[:3] == ['access', 'token', 'revoke']:
    for row in state['access']:
        if row['name'] == args[3]:
            row['state'] = row['effectiveState'] = 'revoked'
json.dump(state, open(state_path, 'w'))
print(json.dumps(out))
'''


class GatewayTokenEntrypointTest(unittest.TestCase):
    def setUp(self):
        self.folder = Path(tempfile.mkdtemp())
        (self.folder / 'home/.config/swamp').mkdir(parents=True)
        (self.folder / 'home/.config/swamp/serve-driver-token').write_text('serve-driver-20261121.fake-secret-d\n')
        (self.folder / 'home/.config/swamp/env.sh').write_text(
            'echo %s\necho %s >&2\nexport SWAMP_API_KEY=fake-club-key\n' % (CLUB_CANARY, CLUB_CANARY))
        for slot in 'abc':
            (self.folder / 'credentials' / slot).mkdir(parents=True)
            (self.folder / 'credentials' / slot / 'server.token').write_text('swamp-role-209-%s-access.fake-secret-s\n' % slot)
            (self.folder / 'credentials' / slot / 'enrollment.token').write_text('swamp-role-209-%s.fake-secret-e\n' % slot)
        self.bin = self.folder / 'swamp'
        self.bin.write_text(FAKE)
        self.bin.chmod(0o755)
        self.state = {'later': LATER, 'calls': [], 'access': [
            {'name': 'orbit-gateway-read-20261121', 'state': 'active', 'effectiveState': 'active',
             'principalId': 'user:orbit-gateway-read', 'principalEmail': 'user:orbit-gateway-read',
             'createdAt': '2026-11-21T07:00:00.000Z', 'expiresAt': SOON},
            {'name': 'serve-driver-20261121', 'state': 'active', 'effectiveState': 'active', 'principalId': 'user:nckrtl',
             'principalEmail': 'user:nckrtl', 'createdAt': '2026-11-21T07:00:00.000Z', 'expiresAt': LATER},
        ], 'enrollment': [{'name': 'swamp-role-209-a', 'expiresAt': LATER, 'state': 'enrolled'}]}
        self.save()

    def tearDown(self):
        subprocess.run(['rm', '-rf', str(self.folder)], check=True)

    def save(self):
        Path(str(self.bin) + '.json').write_text(json.dumps(self.state))

    def load(self):
        return json.loads(Path(str(self.bin) + '.json').read_text())

    def entrypoint(self, *args):
        env = {**os.environ, 'ORBIT_SWAMP_TOKEN_USER': subprocess.check_output(['id', '-un'], text=True).strip(),
               'ORBIT_SWAMP_TOKEN_HOME': str(self.folder / 'home'), 'ORBIT_SWAMP_TOKEN_BIN': str(self.bin),
               'ORBIT_SWAMP_TOKEN_CREDENTIALS': str(self.folder / 'credentials'), 'ORBIT_SWAMP_TOKEN_ENV': str(SWAMP_ENV)}
        env.pop('SWAMP_API_KEY', None)
        return subprocess.run(['bash', str(SCRIPT), *args], env=env, capture_output=True, text=True, timeout=30)

    def test_list_parses_both_cli_shapes_and_names_the_held_generations_without_secrets(self):
        result = self.entrypoint('list')
        self.assertEqual(result.returncode, 0, result.stderr)
        listed = json.loads(result.stdout)
        self.assertEqual([row['name'] for row in listed['access']], ['orbit-gateway-read-20261121', 'serve-driver-20261121'])
        self.assertEqual(listed['access'][0]['principal'], 'user:orbit-gateway-read')
        self.assertEqual(listed['enrollment'][0], {'name': 'swamp-role-209-a', 'expiresAt': LATER, 'state': 'enrolled'})
        self.assertEqual(listed['held']['driver'], 'serve-driver-20261121')
        self.assertEqual(listed['held']['workers'][1], {'slot': 'b', 'access': 'swamp-role-209-b-access', 'enrollment': 'swamp-role-209-b'})
        self.assertNotIn('fake-secret', result.stdout + result.stderr)

    def test_every_swamp_call_sources_the_credential_env_without_printing_it(self):
        listed = self.entrypoint('list')
        minted = self.entrypoint('mint', 'run')
        for result in (listed, minted):
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertNotIn(CLUB_CANARY, result.stdout + result.stderr)
            self.assertNotIn('auth_gate_blocked', result.stderr)

    def test_a_missing_credential_env_fails_closed_with_a_clear_message_before_any_swamp_call(self):
        (self.folder / 'home/.config/swamp/env.sh').unlink()
        result = self.entrypoint('list')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('swamp.credential_env_missing: %s' % (self.folder / 'home/.config/swamp/env.sh'), result.stderr)
        self.assertEqual(result.stdout, '')
        self.assertEqual(self.load()['calls'], [])

    def test_list_reads_the_plain_array_of_swamp_20261114_and_the_older_envelope_and_refuses_another_shape(self):
        listed = {}
        for shape in ('array', 'envelope'):
            self.state['shape'] = shape
            self.save()
            result = self.entrypoint('list')
            self.assertEqual(result.returncode, 0, result.stderr)
            listed[shape] = json.loads(result.stdout)['access']
        self.assertEqual(listed['array'], listed['envelope'])
        self.assertEqual(listed['array'][0], {'name': 'orbit-gateway-read-20261121', 'principal': 'user:orbit-gateway-read',
                                              'expiresAt': SOON, 'state': 'active'})
        self.state['shape'] = 'unknown'
        self.save()
        refused = self.entrypoint('list')
        self.assertNotEqual(refused.returncode, 0)
        self.assertEqual(refused.stdout, '')
        self.assertIn('unknown shape', refused.stderr)
        self.assertNotIn('fake-secret', refused.stdout + refused.stderr)

    def test_list_does_not_invent_state_when_enrollment_state_is_absent(self):
        self.state['enrollment'] = [{'name': 'swamp-role-209-a', 'expiresAt': LATER}]
        self.save()
        result = self.entrypoint('list')
        self.assertEqual(result.returncode, 0, result.stderr)
        listed = json.loads(result.stdout)
        self.assertIsNone(listed['enrollment'][0]['state'])

    def test_undelimited_secret_in_credential_files_is_never_printed(self):
        canary = 'CANARY_SECRET_NO_DOT_9418'
        (self.folder / 'home/.config/swamp/serve-driver-token').write_text(canary + '\n')
        (self.folder / 'credentials/a/server.token').write_text(canary + '\n')
        (self.folder / 'credentials/a/enrollment.token').write_text(canary + '\n')
        result = self.entrypoint('list')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn(canary, result.stdout + result.stderr)
        listed = json.loads(result.stdout)
        self.assertEqual(listed['held']['driver'], '')
        self.assertEqual(listed['held']['workers'][0], {'slot': 'a', 'access': '', 'enrollment': ''})

    def test_mint_names_the_day_and_a_rerun_reveals_the_same_generation(self):
        first = self.entrypoint('mint', 'run')
        self.assertEqual(first.returncode, 0, first.stderr)
        self.assertEqual(json.loads(first.stdout)['name'], 'orbit-gateway-run-' + TODAY)
        self.assertEqual(json.loads(first.stdout)['expiresAt'], LATER)
        again = self.entrypoint('mint', 'run')
        self.assertEqual(json.loads(again.stdout)['name'], 'orbit-gateway-run-' + TODAY)
        mints = [call for call in self.load()['calls'] if call.startswith('access token mint')]
        self.assertEqual(mints, ['access token mint orbit-gateway-run-%s --principal user:orbit-gateway --duration 30d' % TODAY])

    def test_a_revoked_name_of_today_takes_the_next_suffix(self):
        self.state['access'].append({'name': 'orbit-gateway-read-' + TODAY, 'principalId': 'user:orbit-gateway-read',
                                     'expiresAt': LATER, 'state': 'revoked', 'effectiveState': 'revoked'})
        self.save()
        result = self.entrypoint('mint', 'read')
        self.assertEqual(json.loads(result.stdout)['name'], 'orbit-gateway-read-%s-2' % TODAY)

    def test_revoke_accepts_only_gateway_generations(self):
        self.assertEqual(self.entrypoint('revoke', 'orbit-gateway-read-20261121').returncode, 0)
        self.assertEqual(self.entrypoint('revoke', 'orbit-gateway-read').returncode, 0)
        self.assertEqual(self.entrypoint('revoke', 'orbit-gateway-read-manual').returncode, 0)
        self.assertEqual(self.entrypoint('revoke', 'orbit-gateway-run-20261027').returncode, 0)
        for forbidden in ('serve-driver-20261121', 'orbit-gateway-reader', 'orbit-gateway-read-'):
            refused = self.entrypoint('revoke', forbidden)
            self.assertEqual(refused.returncode, 2, forbidden)
            self.assertNotIn('access token revoke ' + forbidden, self.load()['calls'])


if __name__ == '__main__':
    unittest.main()
