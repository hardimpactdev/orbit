"""The shipped rule script against a UFW double; no host firewall calls."""
import copy
import importlib.util
import os
import stat
import tempfile
import unittest
from pathlib import Path
from unittest import mock

SCRIPT = Path(__file__).parent / 'scripts/firewall.py'
spec = importlib.util.spec_from_file_location('firewall', SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
TRUST = {'to': 'Anywhere on orbit', 'action': 'allow', 'source': '10.44.0.0/24', 'comment': 'orbit:wireguard-members'}
TRUST6 = {'to': '22/tcp (v6)', 'action': 'allow', 'source': 'Anywhere (v6)', 'comment': 'orbit:public-ssh-recovery'}


class Ufw:
    """`ufw status numbered`, add, absolute `insert N` and delete by rule; `from any` is a v4 and a v6 line."""

    def __init__(self, rows=()):
        self.rows = copy.deepcopy(list(rows))
        self.effects = []
        self.active = True
        self.fail = False
        self.misplace = False

    def __call__(self, args):
        if args == ['status', 'numbered']:
            return 'Status: ' + ('active' if self.active else 'inactive') + '\n\n     To  Action  From\n' + '\n'.join(
                f"[{i + 1}] {row['to']} {row['action'].upper()} IN {row['source']}" + (f" # {row['comment']}" if row['comment'] else '')
                for i, row in enumerate(self.rows))
        if self.fail:
            raise RuntimeError('firewall.apply_failed')
        self.effects.append(args)
        remove = args[:2] == ['--force', 'delete']
        line = int(args[1]) if args[0] == 'insert' and not self.misplace else None
        rule = args[2:] if remove or args[0] == 'insert' else args
        action, protocol, source, port = rule[0], rule[3], rule[5], rule[9]
        comment = rule[rule.index('comment') + 1] if 'comment' in rule else ''
        families = {'any': [False, True], '0.0.0.0/0': [False], '::/0': [True]}.get(source, [':' in source])
        for v6 in families:
            shown = 'Anywhere' if source in ('any', '0.0.0.0/0', '::/0') else source
            row = {'to': port + '/' + protocol + (' (v6)' if v6 else ''), 'action': action,
                   'source': shown + (' (v6)' if v6 and shown == 'Anywhere' else ''), 'comment': comment}
            same = [old for old in self.rows if (old['to'], old['source']) == (row['to'], row['source'])]
            if remove:
                self.rows = [old for old in self.rows if (old['to'], old['source'], old['action']) != (row['to'], row['source'], action)]
            elif same:
                same[0].update(action=action, comment=comment)
            else:
                first6 = next((i for i, old in enumerate(self.rows) if '(v6)' in old['to'] + old['source']), len(self.rows))
                self.rows.insert(line - 1 if line else len(self.rows) if v6 else first6, row)
        return ''

    def tuples(self, port=None):
        return [(row['to'], row['action'], row['source']) for row in self.rows if port is None or row['to'].split('/')[0] == port]


def rule(**changes):
    return {'node_id': 9, 'action': 'allow', 'port': '443', 'protocol': 'tcp', 'source': '192.0.2.1', 'comment': 'orbit web', **changes}


class FirewallScriptTest(unittest.TestCase):
    def setUp(self):
        self.ufw = Ufw([TRUST, TRUST6])
        patcher = mock.patch.object(module, 'ufw', self.ufw)
        patcher.start()
        self.addCleanup(patcher.stop)

    def converge(self, ensure='present', **changes):
        return module.converge({'rule': rule(**changes), 'ensure': ensure})

    def test_any_writes_one_commented_line_per_protocol_and_family_above_managed_lines(self):
        self.assertEqual(self.converge(protocol='any', source='any'), {'backend': 'active'})
        self.assertEqual(self.ufw.tuples(), [
            ('443/tcp', 'allow', 'Anywhere'), ('443/udp', 'allow', 'Anywhere'), ('Anywhere on orbit', 'allow', '10.44.0.0/24'),
            ('443/tcp (v6)', 'allow', 'Anywhere (v6)'), ('443/udp (v6)', 'allow', 'Anywhere (v6)'), ('22/tcp (v6)', 'allow', 'Anywhere (v6)')])
        self.assertEqual({row['comment'] for row in self.ufw.rows if row['to'].startswith('443')}, {'orbit web'})
        before = copy.deepcopy(self.ufw.rows)
        self.converge(protocol='any', source='any')
        self.assertEqual(self.ufw.rows, before)

    def test_a_deny_sits_above_wireguard_trust_and_an_allowed_source_above_the_deny(self):
        self.converge(action='deny', port='5432', source='any')
        self.converge(port='5432', source='10.44.0.6')
        self.assertEqual(self.ufw.tuples()[:3], [('5432/tcp', 'allow', '10.44.0.6'), ('5432/tcp', 'deny', 'Anywhere'),
                                                 ('Anywhere on orbit', 'allow', '10.44.0.0/24')])

    def test_narrower_rules_sit_above_broader_ones_whatever_their_action(self):
        self.converge(action='deny', port='5432', source='any')
        self.converge(port='5432', source='192.0.2.10')
        self.assertEqual(self.ufw.tuples('5432'), [('5432/tcp', 'allow', '192.0.2.10'), ('5432/tcp', 'deny', 'Anywhere'),
                                                   ('5432/tcp (v6)', 'deny', 'Anywhere (v6)')])
        self.converge(port='25', source='any')
        self.converge(action='deny', port='25', source='192.0.2.10')
        self.converge(port='25', source='192.0.2.0/24')
        self.assertEqual(self.ufw.tuples('25')[:3], [('25/tcp', 'deny', '192.0.2.10'), ('25/tcp', 'allow', '192.0.2.0/24'),
                                                     ('25/tcp', 'allow', 'Anywhere')])
        # A broader rule added last still lands below every narrower one, and removal keeps the rest in order.
        self.converge(action='deny', port='20:30', source='192.0.0.0/16')
        order = [t for t in self.ufw.tuples() if not t[0].endswith('(v6)') and t[0][:2] in ('25', '20')]
        self.assertEqual(order, [('25/tcp', 'deny', '192.0.2.10'), ('25/tcp', 'allow', '192.0.2.0/24'),
                                 ('20:30/tcp', 'deny', '192.0.0.0/16'), ('25/tcp', 'allow', 'Anywhere')])
        self.converge('absent', port='25', source='192.0.2.0/24')
        order = [t for t in self.ufw.tuples() if not t[0].endswith('(v6)') and t[0][:2] in ('25', '20')]
        self.assertEqual(order, [('25/tcp', 'deny', '192.0.2.10'), ('20:30/tcp', 'deny', '192.0.0.0/16'), ('25/tcp', 'allow', 'Anywhere')])

    def test_a_misplaced_line_moves_and_the_check_verifies_order(self):
        self.ufw.rows[1:1] = [{'to': '80/tcp', 'action': 'allow', 'source': 'Anywhere', 'comment': ''},
                              {'to': '80/tcp', 'action': 'deny', 'source': '192.0.2.10', 'comment': 'orbit'}]
        self.converge(port='80', source='192.0.2.10', action='deny', comment='orbit')
        self.assertEqual(self.ufw.tuples('80'), [('80/tcp', 'deny', '192.0.2.10'), ('80/tcp', 'allow', 'Anywhere')])
        self.ufw.misplace = True
        with self.assertRaisesRegex(RuntimeError, 'firewall.verify_failed'):
            self.converge(port='80', source='192.0.2.11', action='deny')

    def test_matching_ignores_the_comment_and_remove_keeps_other_tuples(self):
        self.ufw.rows[:0] = [{'to': '443/tcp', 'action': 'allow', 'source': '192.0.2.1', 'comment': 'set by hand'},
                             {'to': '400:500/tcp', 'action': 'allow', 'source': '192.0.2.1', 'comment': 'orbit range'}]
        self.converge()
        self.assertEqual(self.ufw.tuples().count(('443/tcp', 'allow', '192.0.2.1')), 1)
        self.assertEqual(self.converge('absent'), {'backend': 'absent'})
        self.assertNotIn(('443/tcp', 'allow', '192.0.2.1'), self.ufw.tuples())
        self.assertIn(('400:500/tcp', 'allow', '192.0.2.1'), self.ufw.tuples())

    def test_a_retained_removal_leaves_the_managed_line(self):
        self.converge(action='deny', port='25')
        self.converge('absent', action='deny', port='25', retain=True)
        self.assertIn(('25/tcp', 'deny', '192.0.2.1'), self.ufw.tuples())

    def test_inactive_failed_and_invalid_input_refuse(self):
        self.ufw.active = False
        with self.assertRaisesRegex(RuntimeError, 'firewall.backend_inactive'):
            self.converge()
        self.ufw.active, self.ufw.fail = True, True
        with self.assertRaisesRegex(RuntimeError, 'firewall.apply_failed'):
            self.converge()
        for bad in ({'port': 'ssh'}, {'protocol': 'icmp'}, {'comment': "orbit it's"}, {'comment': 'web'}, {'port': '9:8'},
                    {'action': 'allowlist'}):
            with self.subTest(bad=bad), self.assertRaises((RuntimeError, ValueError)):
                self.converge(**bad)

    def test_lock_is_created_root_style_0600_and_a_loose_or_linked_lock_is_refused(self):
        with tempfile.TemporaryDirectory() as directory, mock.patch.object(module, 'LOCK_UID', os.getuid()):
            lock = Path(directory) / 'orbit-firewall.lock'
            with mock.patch.object(module, 'LOCK', str(lock)):
                previous = os.umask(0o022)
                try:
                    with module.transaction_lock():
                        pass
                finally:
                    os.umask(previous)
                self.assertEqual(stat.S_IMODE(lock.stat().st_mode), 0o600)
                lock.chmod(0o644)
                with self.assertRaisesRegex(RuntimeError, 'firewall.lock_failed'), module.transaction_lock():
                    pass
                lock.unlink()
                lock.symlink_to(Path(directory) / 'elsewhere')
                with self.assertRaisesRegex(RuntimeError, 'firewall.lock_failed'), module.transaction_lock():
                    pass


if __name__ == '__main__':
    unittest.main()
