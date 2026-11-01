"""Credential monitor scripts: expiry classes, renewal argv, custody order and alerts. No Swamp or root needed."""
import datetime
import importlib.util
import json
import os
import stat
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SCRIPTS = Path(__file__).resolve().parents[1] / 'extensions/behaviors/operations/node-worker-assets/scripts'


def load(name):
    spec = importlib.util.spec_from_file_location(name, SCRIPTS / f'{name}.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


check = load('credential_check')
renew = load('credential_renew')
NOW = datetime.datetime(2026, 11, 2, 7, 7, tzinfo=datetime.UTC)
SECRET = 'orbit-node-209-access.fixture-secret-never-in-argv'
ENROLL = 'orbit-node-209.fixture-enrollment-never-in-argv'


def at(hours):
    return (NOW + datetime.timedelta(hours=hours)).isoformat().replace('+00:00', 'Z')


class Classification(unittest.TestCase):
    def test_each_class(self):
        cases = [(100, 'ok'), (72, 'within_72h'), (7, 'within_72h'), (6, 'within_6h'), (0.5, 'within_6h'),
                 (0, 'expired'), (-3, 'expired')]
        for hours, expected in cases:
            with self.subTest(hours=hours):
                self.assertEqual(check.classify(check.instant(at(hours)), NOW)[0], expected)
        self.assertEqual(check.classify(None, NOW), ('no_expiry', None))

    def test_native_rows_skip_revoked_and_report_no_expiry(self):
        def run(label, argv, env=None):
            if 'access' in argv:
                return json.dumps({'tokens': [
                    {'name': 'orbit-node-209-access', 'principalId': 'worker:orbit-node-209', 'state': 'active',
                     'effectiveState': 'active', 'expiresAt': at(5)},
                    {'name': 'old', 'state': 'revoked', 'expiresAt': at(-1)}]})
            if 'worker' in argv:
                return json.dumps([{'name': 'orbit-node-13', 'state': 'enrolled', 'expiresAt': at(30)}])
            if 'auth' in argv:
                return json.dumps({'tokens': [{'name': 'orbit-monorepo-ci', 'enabled': True, 'expiresAt': None}]})
            return 'notAfter=Nov 27 20:56:22 2027 GMT\n'

        with patch.object(check, 'run', side_effect=run):
            found = {item['name']: item for item in check.inspect(NOW)}
        self.assertNotIn('old', found)
        self.assertEqual(found['orbit-node-209-access']['class'], 'within_6h')
        self.assertEqual(found['orbit-node-13']['class'], 'within_72h')
        self.assertEqual((found['orbit-monorepo-ci']['class'], found['orbit-monorepo-ci']['expires_at']), ('no_expiry', None))
        self.assertEqual((found['swamp.orbit']['kind'], found['swamp.orbit']['class']), ('tls', 'ok'))

    def test_failed_native_list_fails_the_check(self):
        failed = check.subprocess.CompletedProcess([], 4, '', 'detail')
        with patch.object(check.subprocess, 'run', return_value=failed), \
                self.assertRaisesRegex(check.CheckFailed, 'swamp access token list exited 4'):
            check.inspect(NOW)

    def test_report_stays_bounded(self):
        many = [{'name': f'stale-{n}', 'state': 'active', 'expiresAt': at(-200)} for n in range(3)]
        many += [{'name': f'live-{n}', 'state': 'active', 'expiresAt': at(n + 1)} for n in range(400)]

        def run(label, argv, env=None):
            if 'access' in argv:
                return json.dumps(many)
            return 'notAfter=Nov  7 20:56:22 2027 GMT' if argv[0] == 'openssl' else '[]'

        with patch.object(check, 'run', side_effect=run):
            found = check.inspect(NOW)
        self.assertEqual(len(found), check.MAXIMUM_ROWS)
        self.assertEqual(found[0]['name'], 'live-0')
        self.assertFalse(any(item['name'].startswith('stale-') for item in found))


class Renewal(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        self.owner = os.getuid()
        self.server = self.root / 'swamp-worker/server.token'
        self.enrollment = self.root / 'swamp-worker/enrollment.token'
        self.server.parent.mkdir()
        for path, value in ((self.server, 'orbit-node-209-access.old'), (self.enrollment, 'orbit-node-209.old')):
            path.write_text(value + '\n')
            os.chmod(path, 0o600)
        self.argv = []
        self.renewable = patch.dict(renew.RENEWABLE, {('access', 'orbit-node-209-access'): str(self.server),
                                                     ('enrollment', 'orbit-node-209'): str(self.enrollment)}, clear=True)
        self.renewable.start()
        self.addCleanup(self.renewable.stop)

    def cli(self):
        def served(label, command):
            self.argv.append(command)
            if command[:3] == ['access', 'token', 'rotate']:
                return {'name': command[3], 'principalId': 'worker:orbit-node-209', 'expiresAt': at(24)}
            if command[:3] == ['worker', 'token', 'create']:
                return {'name': command[3], 'token': ENROLL, 'expiresAt': at(24)}
            return {'name': command[3], 'state': 'revoked'}

        def local(label, command):
            self.argv.append(command)
            return {'token': SECRET}

        return {'served': served, 'local': local}

    def report(self, hours):
        return {'checked_at': NOW.isoformat(), 'credentials': [
            {'kind': 'access', 'name': 'orbit-node-209-access', 'expires_at': at(hours), 'hours_left': hours,
             'class': check.classify(check.instant(at(hours)), NOW)[0]},
            {'kind': 'enrollment', 'name': 'orbit-node-209', 'expires_at': at(hours), 'hours_left': hours,
             'class': check.classify(check.instant(at(hours)), NOW)[0]}]}

    def test_due_pair_renews_with_names_only_in_argv(self):
        result = renew.maintain(self.report(5), NOW, self.cli(), self.root / 'backups', self.owner)
        self.assertEqual([item['outcome'] for item in result['renewals']], ['renewed', 'renewed'])
        self.assertEqual(self.argv, [['access', 'token', 'rotate', 'orbit-node-209-access', '--duration', '24h'],
                                     ['access', 'token', 'reveal', 'orbit-node-209-access'],
                                     ['worker', 'token', 'revoke', 'orbit-node-209'],
                                     ['worker', 'token', 'create', 'orbit-node-209', '--duration', '24h']])
        flat = json.dumps(self.argv) + json.dumps(result)
        self.assertNotIn(SECRET, flat)
        self.assertNotIn(ENROLL, flat)
        self.assertEqual(self.server.read_text(), SECRET + '\n')
        self.assertEqual(self.enrollment.read_text(), ENROLL + '\n')
        self.assertEqual(stat.S_IMODE(self.server.stat().st_mode), 0o600)
        backups = sorted(path.name for path in (self.root / 'backups').rglob('*.before'))
        self.assertEqual(len(backups), 2)
        self.assertEqual(result['alerts'], [])

    def test_real_serve_argv_carries_no_value(self):
        recorded = []

        def run(argv, **kwargs):
            recorded.append(argv)
            body = {'name': 'orbit-node-209-access', 'token': SECRET, 'expiresAt': at(24)}
            return renew.subprocess.CompletedProcess(argv, 0, json.dumps(body), '')

        with patch.object(renew.subprocess, 'run', side_effect=run):
            value, _ = renew.issue('access', 'orbit-node-209-access')
        self.assertEqual(value, SECRET)
        self.assertNotIn(SECRET, json.dumps(recorded))
        self.assertIn('--token-file', recorded[0])
        self.assertEqual(recorded[1][:3], ['runuser', '-u', 'orbit'])

    def test_custody_order_is_backup_then_write_then_readback(self):
        order = []
        originals = {name: getattr(renew, name) for name in ('backup', 'issue', 'write', 'readback')}

        def recorder(name):
            def call(*args, **kwargs):
                order.append(name)
                return originals[name](*args, **kwargs)
            return call

        with patch.multiple(renew, **{name: recorder(name) for name in originals}):
            renew.renew('access', 'orbit-node-209-access', str(self.server), NOW, self.cli(), self.root / 'b', self.owner)
        self.assertEqual(order, ['backup', 'issue', 'write', 'readback'])

    def test_changed_custody_refuses_before_any_native_call(self):
        self.server.write_text('someone-else.value\n')
        result = renew.maintain(self.report(5), NOW, self.cli(), self.root / 'backups', self.owner)
        self.assertEqual(result['renewals'][0], {'kind': 'access', 'name': 'orbit-node-209-access',
                                                 'outcome': 'failed', 'error': 'custody_changed'})
        self.assertNotIn(['access', 'token', 'rotate', 'orbit-node-209-access', '--duration', '24h'], self.argv)
        self.assertEqual(result['alerts'][0]['reason'], 'renewal_failed')

    def test_not_due_and_unowned_credentials_are_never_renewed(self):
        report = self.report(30)
        report['credentials'].append({'kind': 'access', 'name': 'orbit-node-13-access', 'expires_at': at(2),
                                      'hours_left': 2, 'class': 'within_6h'})
        result = renew.maintain(report, NOW, self.cli(), self.root / 'backups', self.owner)
        self.assertEqual((result['renewals'], self.argv), ([], []))
        self.assertEqual([(item['name'], item['reason']) for item in result['alerts']],
                         [('orbit-node-13-access', 'within_6h')])


class Alerts(unittest.TestCase):
    def credential(self, name, category, kind='access'):
        return {'kind': kind, 'name': name, 'class': category, 'expires_at': None, 'hours_left': None}

    def test_alert_rules(self):
        credentials = [self.credential('orbit-gateway-run-20261030', 'within_72h'),
                       self.credential('orbit-node-9', 'expired', 'enrollment'),
                       self.credential('swamp.orbit', 'ok', 'tls'),
                       self.credential('orbit-monorepo-ci', 'no_expiry', 'club'),
                       self.credential('orbit-node-209-access', 'within_72h'),
                       self.credential('orbit-node-209', 'expired', 'enrollment')]
        renewals = [{'kind': 'enrollment', 'name': 'orbit-node-209', 'outcome': 'renewed'}]
        self.assertEqual([(item['name'], item['reason']) for item in renew.alerts(credentials, renewals)],
                         [('orbit-gateway-run-20261030', 'within_72h'), ('orbit-node-9', 'expired'),
                          ('orbit-node-209', 'expired_renewed')])

    def test_failed_renewal_escalates_at_expiry_and_a_missing_pair_alerts(self):
        failed = [{'kind': 'access', 'name': 'orbit-node-209-access', 'outcome': 'failed'}]
        due = [self.credential('orbit-node-209-access', 'within_6h')]
        self.assertEqual([item['reason'] for item in renew.alerts(due, failed)], ['missing', 'renewal_failed'])
        gone = [self.credential('orbit-node-209-access', 'expired')]
        self.assertEqual([(item['name'], item['reason']) for item in renew.alerts(gone, failed)],
                         [('orbit-node-209', 'missing'), ('orbit-node-209-access', 'expired')])


if __name__ == '__main__':
    unittest.main()
