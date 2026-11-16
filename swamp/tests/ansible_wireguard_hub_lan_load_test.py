"""A move to a new hub endpoint: the endpoint pin and the load of the new peers (ADR 0251)."""
import runpy
import unittest
from pathlib import Path

SUPPORT = runpy.run_path(str(Path(__file__).parent / "_support/wireguard_hub_lan.py"))
LAN = SUPPORT["LAN"]
LIVE = SUPPORT["LIVE"]
OLD = SUPPORT["OLD"]


class WireGuardHubLanLoadTest(SUPPORT["HubLanHarness"]):
    def test_a_pin_that_a_killed_move_left_is_removed_first(self):
        # Two stale pins and a rule that is not Orbit's: the pins go, last first, and the other rule stays.
        rules = ('-p udp --dport 39975 ! -s 10.0.0.9 -m comment --comment orbit-hub-move -j DROP\n'
                 '-p tcp --dport 22 -m comment --comment other -j ACCEPT\n'
                 '-p udp --dport 39975 ! -s 10.0.0.8 -m comment --comment orbit-hub-move -j DROP\n')
        code, output, calls = self.move(9_999_999_999, **{'rules.txt': rules})
        self.assertEqual(code, 0, output)
        self.assertLess(calls.index('iptables -w -D INPUT 3\niptables -w -D INPUT 1\n'), calls.index(self.PIN))
        self.assertEqual(self.left['rules.txt'], '-p tcp --dport 22 -m comment --comment other -j ACCEPT\n')

    def test_a_move_that_cannot_pin_changes_nothing(self):
        code, output, calls = self.move(9_999_999_999, **{'iptables.fail': ''})
        self.assertNotEqual(code, 0)
        self.assertIn('node.hub_switch_unpinned', output)
        self.assertNotIn('wg set', calls)
        self.assertEqual((self.left['wg/orbit.conf'], self.left['wg/orbit.peers.next']), ('old conf\n', self.PEERS.format(self.LAN)))

    def test_a_hostname_endpoint_pins_nothing(self):
        code, output, calls = self.move(9_999_999_999, endpoint='vpn.example.com:51820')
        self.assertEqual(code, 0, output)
        self.assertNotIn('iptables -w -I', calls)

    def test_the_new_peers_are_loaded_without_the_interface_section_node_add_wrote(self):
        code, output, _ = self.move(9_999_999_999, **{'wg/orbit.peers.next': '[Interface]\nPrivateKey = OLD\n' + self.PEERS.format(self.LAN)})
        self.assertEqual(code, 0, output)
        self.assertEqual(self.left['loaded.log'].count('[Interface]'), 1)

    def test_a_move_loads_a_key_file_without_a_final_newline(self):
        # Node 209: the key ran into the next line (`PrivateKey = <key>[Peer]`), and `wg` refused the whole input.
        code, output, _ = self.move(9_999_999_999, **{'bin/wg': self.STRICT_WG, 'wg/orbit.key': self.KEY})
        self.assertEqual(code, 0, output)
        self.assertEqual(self.left['loaded.log'], f'[Interface]\nPrivateKey = {self.KEY}\n{self.PEERS.format(self.LAN)}---\n')

    def test_a_move_whose_new_configuration_does_not_load_goes_back_at_once_with_its_own_outcome(self):
        code, output, calls = self.move(9_999_999_999, **{'bin/wg': self.STRICT_WG, 'wg/orbit.key': 'UNLOADABLE\n'})
        self.assertNotEqual(code, 0)
        self.assertIn('node.hub_switch_load_failed', output)
        self.assertNotIn('node.hub_switch_no_handshake', output)
        # No wait for a handshake that cannot come: the hub peer is gone and nothing was loaded.
        self.assertNotIn('latest-handshakes', calls)
        self.assertEqual((self.left['wg/orbit.conf'], self.left['wg/orbit.peers']), ('old conf\n', self.PEERS.format(self.PUBLIC)))
        self.assertEqual(self.left['loaded.log'], 'setconf: LIVE CONFIG\n')
        self.assertEqual([name for name in self.left if name.endswith(('.previous', '.next', 'move-result'))], [])
        self.assertEqual(self.left['rules.txt'], '')
        self.assertNotIn('UNLOADABLE', output)

    def test_every_load_composed_from_the_key_file_ends_the_key_line_once_with_or_without_a_final_newline(self):
        head = f'[Interface]\nPrivateKey = {self.KEY}\n'
        for key in (self.KEY, self.KEY + '\n'):
            with self.subTest(key=key):
                code, output, names = self.sync(key)
                self.assertEqual(code, 0, output)
                self.assertEqual(dict(zip(names, self.left['loaded.log'].split('---\n'), strict=False)), dict(zip(names, (
                    head + self.PEERS.format(self.PUBLIC), head + self.INTERFACE + self.HUB_PEER,
                    head + self.INTERFACE + '\n' + self.HUB_PEER, head + self.PEERS.format(self.PUBLIC)), strict=True)))

    def test_a_refused_load_does_not_print_the_value_wg_refused(self):
        # `wg` prints the value it rejects; in the key line that is what the key file holds.
        code, output, names = self.sync('UNLOADABLE\n', ignore_errors=True)
        self.assertEqual(code, 0, output)
        self.assertEqual(output.count('...ignoring'), len(names), output)
        self.assertIn('Key is not the correct length or format: ', output)
        self.assertNotIn('UNLOADABLE', output)


if __name__ == '__main__':
    unittest.main()
