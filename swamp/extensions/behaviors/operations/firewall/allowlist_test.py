"""Ordered UFW behavior against an inventory double; no host firewall calls."""
import copy
import importlib.util
import ipaddress
import os
import stat
import tempfile
import unittest
from pathlib import Path
from unittest import mock

SCRIPT = Path(__file__).parent / 'scripts/allowlist.py'
spec = importlib.util.spec_from_file_location('allowlist', SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class Ufw:
    def __init__(self, rows=None):
        self.rows = copy.deepcopy(rows or [])
        self.effects = []
        self.snapshots = []
        self.active = True
        self.lose = False

    def command(self, args):
        if args[0] == 'status':
            return 'Status: ' + ('active' if self.active else 'inactive') + '\n' + '\n'.join(
                f"[{index + 1}] {row['to']} {row['action'].upper()} IN {row['source']} # {row['comment']}" for index, row in enumerate(self.rows))
        self.effects.append(args)
        if self.lose:
            return 'Rule inserted'
        if args[:2] == ['--force', 'delete']:
            self.rows.pop(int(args[2]) - 1)
        else:
            if args[0] != 'prepend':
                raise RuntimeError('Use UFW prepend for family-aware first position')
            source = args[args.index('from') + 1]
            destination = args[args.index('to') + 1]
            port = args[args.index('port') + 1] + '/' + args[args.index('proto') + 1]
            families = [4, 6] if source == destination == 'any' else [6 if ':' in source + destination else 4]
            for family in families:
                to = (destination + ' ' if destination != 'any' else '') + port
                source_text = 'Anywhere' if source == 'any' else source
                if family == 6:
                    to += ' (v6)'
                    source_text += ' (v6)'
                row = {'to': to, 'source': source_text, 'action': args[1], 'comment': args[-1]}
                # UFW insert skips any existing identical tuple, even with another action/comment.
                if not any(all(other[k] == row[k] for k in ('to', 'source')) for other in self.rows):
                    position = next((i for i, old in enumerate(self.rows) if '(v6)' in old['to']), len(self.rows)) if family == 6 else 0
                    self.rows.insert(position, row)
        self.snapshots.append(copy.deepcopy(self.rows))
        return 'Rule inserted'

    def admits(self, source, port=7297):
        for row in self.rows:
            if '(v6)' in row['to']:
                continue
            target = row['to'].split()[0]
            if len(row['to'].split()) > 1 and ipaddress.ip_address('10.44.0.9') not in ipaddress.ip_network(target):
                continue
            service = row['to'].split()[-1].split('/')[0]
            if service != 'Anywhere':
                bounds = list(map(int, service.split(':')))
                if not bounds[0] <= port <= bounds[-1]:
                    continue
            if row['source'] == 'Anywhere' or ipaddress.ip_address(source) in ipaddress.ip_network(row['source']):
                return row['action'] == 'allow'
        return False


def intent(sources=None, ensure='present'):
    return {'rule': {'node_id': 9, 'name': 'rex', 'action': 'allowlist', 'port': '7297', 'protocol': 'tcp',
                     'sources': sources or ['10.44.0.6', '10.44.0.9']}, 'ensure': ensure}


class AllowlistTest(unittest.TestCase):
    def setUp(self):
        self.ufw = Ufw([{'to': 'Anywhere', 'source': '10.44.0.0/24', 'action': 'allow', 'comment': 'orbit:wireguard-members'}])
        self.previous = module.command
        module.command = self.ufw.command
        self.addCleanup(setattr, module, 'command', self.previous)

    def test_peer_permits_and_final_deny_precede_trust_and_repeat_has_no_writes(self):
        self.assertEqual(module.converge(intent()), {'backend': 'active'})
        self.assertEqual([row['action'] for row in self.ufw.rows[:3]], ['allow', 'allow', 'deny'])
        self.assertTrue(self.ufw.admits('10.44.0.6'))
        self.assertTrue(self.ufw.admits('10.44.0.9'))
        self.assertFalse(self.ufw.admits('10.44.0.7'))
        count = len(self.ufw.effects)
        module.converge(intent())
        self.assertEqual(len(self.ufw.effects), count)

    def test_replace_withdraws_old_sources_and_never_admits_an_unlisted_peer(self):
        module.converge(intent())
        self.ufw.snapshots = []
        module.converge(intent(['10.44.0.16/28']))
        self.assertFalse(self.ufw.admits('10.44.0.6'))
        self.assertTrue(self.ufw.admits('10.44.0.17'))
        final = self.ufw.rows
        for snapshot in self.ufw.snapshots:
            self.ufw.rows = snapshot
            self.assertFalse(self.ufw.admits('10.44.0.7'))
        self.ufw.rows = final
        self.assertEqual([row['comment'] for row in final if ':updating' in row['comment']], [])

    def test_remove_cleans_all_owned_lines_and_preserves_unrelated_rules(self):
        before = copy.deepcopy(self.ufw.rows)
        module.converge(intent())
        self.assertEqual(module.converge(intent(ensure='absent')), {'backend': 'absent'})
        self.assertEqual(self.ufw.rows, before)
        module.converge(intent(ensure='absent'))
        self.assertEqual(self.ufw.rows, before)

    def test_inactive_and_lost_writes_fail_without_success(self):
        self.ufw.active = False
        with self.assertRaisesRegex(RuntimeError, 'firewall.backend_inactive'):
            module.converge(intent())
        self.assertEqual(self.ufw.effects, [])
        self.ufw.active = True
        self.ufw.lose = True
        with self.assertRaisesRegex(RuntimeError, 'firewall.verify_failed'):
            module.converge(intent())

    def test_unowned_tuple_collision_refuses_before_any_mutation(self):
        self.ufw.rows.insert(0, {'to': '7297/tcp', 'action': 'allow', 'source': 'Anywhere', 'comment': 'office'})
        before = copy.deepcopy(self.ufw.rows)
        with self.assertRaisesRegex(RuntimeError, 'firewall.rule_collision'):
            module.converge(intent())
        self.assertEqual(self.ufw.rows, before)
        self.assertEqual(self.ufw.effects, [])

    def test_stale_transition_guard_is_removed_on_repeat(self):
        module.converge(intent())
        module.insert(intent()['rule'], 'deny', 'any', 'orbit:node:9:allowlist:rex:updating', '0.0.0.0/1')
        self.assertFalse(self.ufw.admits('10.44.0.6'))
        module.converge(intent())
        self.assertTrue(self.ufw.admits('10.44.0.6'))
        self.assertFalse(any(':updating' in row['comment'] for row in self.ufw.rows))

    def test_range_udp_and_zero_prefix_render_without_colliding_with_final_deny(self):
        value = intent(['0.0.0.0/0'])
        value['rule'].update(port='7297:7299', protocol='udp')
        module.converge(value)
        self.assertEqual([row['source'] for row in self.ufw.rows[:3]], ['0.0.0.0/1', '128.0.0.0/1', 'Anywhere'])
        self.assertEqual([row['to'] for row in self.ufw.rows[:3]], ['7297:7299/udp'] * 3)


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
                self.assertFalse((Path(directory) / 'elsewhere').exists())


if __name__ == '__main__':
    unittest.main()
