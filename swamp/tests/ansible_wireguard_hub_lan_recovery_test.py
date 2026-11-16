"""A move to a new hub endpoint: the handshake proof and the way back after a cut-off or failed move (ADR 0251)."""
import runpy
import unittest
from pathlib import Path

SUPPORT = runpy.run_path(str(Path(__file__).parent / "_support/wireguard_hub_lan.py"))
LAN = SUPPORT["LAN"]
LIVE = SUPPORT["LIVE"]


class WireGuardHubLanRecoveryTest(SUPPORT["HubLanHarness"]):
    def test_a_move_with_a_fresh_handshake_keeps_the_new_files(self):
        code, output, calls = self.move(9_999_999_999)
        self.assertEqual(code, 0, output)
        self.assertIn('wg set orbit peer HUBKEY remove', calls)
        self.assertIn(f'Endpoint = {self.LAN}', self.left['wg/orbit.peers'])
        self.assertEqual(self.left['wg/orbit.conf'], 'new conf\n')
        self.assertNotIn('wg/orbit.peers.previous', self.left)
        self.assertNotIn('wg/orbit.live.previous', self.left)

    def test_a_new_handshake_through_the_old_public_address_is_no_proof_of_the_lan(self):
        # UDP on the LAN fails, the hub starts a handshake to the Node's old public address, and the Node roams there.
        code, output, _ = self.move(9_999_999_999, **{'endpoint.txt': self.PUBLIC})
        self.assertNotEqual(code, 0)
        self.assertIn('node.hub_switch_no_handshake', output)
        self.assertIn(f'Endpoint = {self.PUBLIC}', self.left['wg/orbit.peers'])

    def test_a_move_without_a_handshake_after_it_restores_the_files_and_the_live_configuration(self):
        # A handshake from before the move went through the old endpoint and does not count.
        code, output, _ = self.move(1)
        self.assertNotEqual(code, 0)
        self.assertIn('node.hub_switch_no_handshake', output)
        self.assertNotIn('wg/orbit.move-result', self.left)
        self.assertIn(f'Endpoint = {self.PUBLIC}', self.left['wg/orbit.peers'])
        self.assertEqual(self.left['wg/orbit.conf'], 'old conf\n')
        self.assertTrue(self.left['loaded.log'].rstrip().endswith('setconf: LIVE CONFIG'))
        self.assertNotIn('wg/orbit.live.previous', self.left)

    def test_a_move_without_a_previous_peer_file_still_goes_back_to_the_live_configuration(self):
        code, _, _ = self.move(1, **{'wg/orbit.peers': None})
        self.assertNotEqual(code, 0)
        self.assertTrue(self.left['loaded.log'].rstrip().endswith('setconf: LIVE CONFIG'))
        self.assertEqual(self.left['wg/orbit.conf'], 'old conf\n')
        # The new peer file goes too, so the next converge reads no endpoint and moves with a rollback again.
        self.assertNotIn('wg/orbit.peers', self.left)

    def test_a_rerun_after_a_cut_off_move_keeps_the_files_that_move_replaced(self):
        code, _, _ = self.move(1, **{'wg/orbit.peers': self.PEERS.format(self.LAN),
                                          'wg/orbit.peers.previous': self.PEERS.format(self.PUBLIC)})
        self.assertNotEqual(code, 0)
        self.assertIn(f'Endpoint = {self.PUBLIC}', self.left['wg/orbit.peers'])

    def test_a_failed_move_after_a_cut_off_one_loads_the_previous_files_not_the_live_state(self):
        # A reboot during the cut-off move may have brought the tunnel up on the new files; the live state is not the old one.
        code, _, calls = self.move(1, **{'wg/orbit.peers': self.PEERS.format(self.LAN), 'wg/orbit.peers.previous': self.PEERS.format(self.PUBLIC),
                                         'wg/orbit.conf.previous': 'old conf\n'})
        self.assertNotEqual(code, 0)
        self.assertNotIn('setconf', calls)
        self.assertTrue(self.left['loaded.log'].rstrip().endswith(f'Endpoint = {self.PUBLIC}\nAllowedIPs = 10.44.0.0/24\nPersistentKeepalive = 25\n---'))

    def test_the_node_takes_wireguard_only_from_the_new_endpoint_while_it_moves(self):
        # Operation 15254: the hub, still sending to the Node's public address, started a handshake there; the Node roamed back.
        for handshake, moved in ((9_999_999_999, True), (1, False)):
            with self.subTest(handshake=handshake):
                code, output, calls = self.move(handshake)
                self.assertEqual(code == 0, moved, output)
                self.assertLess(calls.index(self.PIN), calls.index('wg set orbit peer HUBKEY remove'), output)
                self.assertEqual(self.left['rules.txt'], '')


if __name__ == '__main__':
    unittest.main()
