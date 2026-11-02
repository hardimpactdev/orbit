"""Read-only credential check: expiry classes, strict native shapes, read-only argv and alerts. No Swamp needed."""
import datetime
import importlib.util
import json
import unittest
from pathlib import Path
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / 'extensions/behaviors/operations/node-worker-assets/scripts/credential_check.py'
spec = importlib.util.spec_from_file_location('credential_check', SCRIPT)
check = importlib.util.module_from_spec(spec)
spec.loader.exec_module(check)
NOW = datetime.datetime(2026, 11, 2, 7, 7, tzinfo=datetime.UTC)


def at(hours):
    return (NOW + datetime.timedelta(hours=hours)).isoformat().replace('+00:00', 'Z')


def access(name, hours, state='active', principal='worker:orbit-node-209'):
    return {'name': name, 'state': state, 'principalId': principal, 'expiresAt': at(hours)}


def enrollment(name, hours, state='enrolled'):
    return {'name': name, 'state': state, 'expiresAt': at(hours)}


def native(access_rows, enrollment_rows, club_rows=(), certificate='notAfter=Nov  7 20:56:22 2027 GMT\n'):
    calls = []

    def run(label, argv, env=check.ENV):
        calls.append((argv, env))
        if argv[0] == 'openssl':
            return certificate
        rows = {'access': access_rows, 'worker': enrollment_rows, 'auth': club_rows}[argv[1]]
        return json.dumps(list(rows))

    return run, calls


class Classification(unittest.TestCase):
    def test_each_class(self):
        cases = [(100, 'ok'), (72, 'within_72h'), (7, 'within_72h'), (6, 'within_6h'), (0.5, 'within_6h'),
                 (0, 'expired'), (-3, 'expired')]
        for hours, expected in cases:
            with self.subTest(hours=hours):
                self.assertEqual(check.record('access', 'x', at(hours), NOW)['class'], expected)
        self.assertEqual(check.record('club', 'ci', None, NOW)['class'], 'no_expiry')


class Inspect(unittest.TestCase):
    def test_lists_read_only_and_reports_every_class(self):
        run, calls = native(
            [access('orbit-node-209-access', 5), access('old', -1, 'revoked'), access('orbit-gateway-read', 400)],
            [enrollment('orbit-node-209', 30), enrollment('orbit-node-13', -2)],
            [{'name': 'orbit-monorepo-ci', 'enabled': True, 'expiresAt': None},
             {'name': 'off', 'enabled': False, 'expiresAt': at(1)}])
        with patch.object(check, 'run', side_effect=run):
            found = {item['name']: item for item in check.inspect(NOW)}
        self.assertEqual(sorted(found), ['orbit-gateway-read', 'orbit-monorepo-ci', 'orbit-node-13', 'orbit-node-209',
                                         'orbit-node-209-access', 'swamp.orbit'])
        self.assertEqual([found[name]['class'] for name in ('orbit-node-209-access', 'orbit-node-209', 'orbit-node-13',
                                                            'orbit-gateway-read', 'orbit-monorepo-ci', 'swamp.orbit')],
                         ['within_6h', 'within_72h', 'expired', 'ok', 'no_expiry', 'ok'])
        # Read-only native lists only: no mutation verb, no serve token, no sudo.
        verbs = [argv[1:4] for argv, _ in calls if argv[0] == check.SWAMP]
        self.assertEqual(verbs, [['access', 'token', 'list'], ['worker', 'token', 'list'], ['auth', 'token', 'list']])
        flat = json.dumps([argv for argv, _ in calls])
        for word in ('rotate', 'revoke', 'create', 'reveal', '--server', '--token-file', 'sudo'):
            self.assertNotIn(word, flat)

    def test_malformed_native_output_fails_the_run(self):
        cases = {'not an array': (json.dumps({'tokens': []}), 'access list is not a JSON array'),
                 'missing key': (json.dumps([{'name': 'a', 'state': 'active'}]), 'access row lacks expiresAt')}
        for label, (stdout, message) in cases.items():
            with self.subTest(label), patch.object(check, 'run', return_value=stdout), \
                    self.assertRaisesRegex(check.CheckFailed, message):
                check.inspect(NOW)

    def test_failed_native_list_fails_the_run(self):
        failed = check.subprocess.CompletedProcess([], 4, '', 'detail')
        with patch.object(check.subprocess, 'run', return_value=failed), \
                self.assertRaisesRegex(check.CheckFailed, 'swamp access token list exited 4'):
            check.inspect(NOW)

    def test_report_stays_bounded(self):
        many = [access(f'stale-{n}', -200) for n in range(3)] + [access(f'live-{n}', (n + 1) / 10) for n in range(400)]
        run, _ = native([*many, access('orbit-node-209-access', 500)], [enrollment('orbit-node-209', 500)])
        with patch.object(check, 'run', side_effect=run):
            result = check.report(check.inspect(NOW), NOW)
        self.assertEqual(sorted(result), ['alerts', 'checked_at'])
        found = result['alerts']
        self.assertEqual(len(found), check.MAXIMUM_ROWS)
        self.assertEqual(found[0]['name'], 'live-0')
        self.assertFalse(any(item['name'].startswith('stale-') for item in found))
        # The 209 pair is listed, so it is not reported missing.
        self.assertNotIn('missing', {item['reason'] for item in found})


class Alerts(unittest.TestCase):
    def credential(self, kind, name, category):
        return {'kind': kind, 'name': name, 'class': category, 'expires_at': None, 'hours_left': None}

    def test_alert_rules(self):
        credentials = [self.credential('access', 'orbit-node-209-access', 'within_72h'),
                       self.credential('access', 'orbit-gateway-run-20261030', 'within_6h'),
                       self.credential('enrollment', 'orbit-node-9', 'expired'),
                       self.credential('tls', 'swamp.orbit', 'ok'),
                       self.credential('club', 'orbit-monorepo-ci', 'no_expiry')]
        self.assertEqual([(item['name'], item['reason']) for item in check.alerts(credentials, credentials)],
                         [('orbit-node-209', 'missing'), ('orbit-node-209-access', 'within_72h'),
                          ('orbit-gateway-run-20261030', 'within_6h'), ('orbit-node-9', 'expired')])


if __name__ == '__main__':
    unittest.main()
