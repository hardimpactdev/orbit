"""The hub LAN proof, the hub's source unit and its rules, and the switch decision (ADR 0251)."""
import json
import runpy
import unittest
from typing import ClassVar
from pathlib import Path

import yaml

SUPPORT = runpy.run_path(str(Path(__file__).parent / "_support/wireguard_hub_lan.py"))
ANSIBLE = SUPPORT["ANSIBLE"]
OWNER = SUPPORT["OWNER"]
RETIRE_IP = SUPPORT["RETIRE_IP"]
LAN = SUPPORT["LAN"]
LIVE = SUPPORT["LIVE"]
OLD = SUPPORT["OLD"]
WRONG = SUPPORT["WRONG"]
RIGHT = SUPPORT["RIGHT"]
peer_block = SUPPORT["peer_block"]
peer_task = SUPPORT["peer_task"]


class WireGuardHubLanRulesTest(SUPPORT["HubLanHarness"]):
    def test_the_hubs_host_key_at_a_directly_routed_lan_address_proves_the_lan(self):
        code, output, calls = self.prove(self.DIRECT, self.HUB_KEYS, self.HUB_PRINTS)
        self.assertEqual(code, 0, output)
        self.assertIn('ssh-keyscan -T 5 -p 22 10.0.0.2', calls)

    def test_a_foreign_host_that_answers_at_the_hubs_lan_address_is_refused(self):
        code, output, _ = self.prove(self.DIRECT, '10.0.0.2 ssh-ed25519 AAAAother\n', '256 SHA256:someOtherHost 10.0.0.2 (ED25519)\n')
        self.assertNotEqual(code, 0)
        self.assertIn('node.hub_lan_identity_mismatch', output)

    def test_a_routed_foreign_source_tunnel_or_silent_address_is_unreachable(self):
        for route, keys in (({'dst': '10.0.0.2', 'gateway': '192.168.6.1', 'dev': 'eth0', 'prefsrc': '192.168.6.20'}, self.HUB_KEYS),
                            ({'dst': '10.0.0.2', 'dev': 'eth1', 'prefsrc': '10.0.0.9'}, self.HUB_KEYS),
                            ({'dst': '10.0.0.2', 'dev': 'orbit', 'prefsrc': '10.0.0.4'}, self.HUB_KEYS),
                            (self.DIRECT, '')):
            with self.subTest(route=route, keys=keys):
                code, output, _ = self.prove(route, keys, self.HUB_PRINTS)
                self.assertNotEqual(code, 0)
                self.assertIn('node.hub_lan_unreachable', output)

    def test_a_hub_with_the_old_suppress_rule_gets_the_private_range_rules_from_its_unit(self):
        code, output, calls = self.source(OLD, WRONG)
        self.assertEqual(code, 0, output)
        self.assertIn('restart', calls)
        self.assertIn('ufw allow in proto tcp from 10.0.0.0/24 to 10.0.0.2 port 22', calls)
        # docs/cli/firewall/index.mdx#vpn-peer-forwarding: the hub forwards between members, as production's hand rule does.
        self.assertIn('ufw route allow in on orbit out on orbit from 10.44.0.0/24 to 10.44.0.0/24 comment orbit:vpn-peer-forwarding', calls)

    def test_a_hub_whose_unit_already_applies_the_rules_changes_nothing(self):
        code, output, calls = self.source(LIVE, RIGHT, installed=self.INSTALLED)
        self.assertEqual(code, 0, output)
        self.assertNotIn('restart', calls)
        self.assertIn('changed=0', output)

    def test_rules_that_drifted_under_an_unchanged_unit_restart_it(self):
        code, output, calls = self.source(OLD, WRONG, installed=self.INSTALLED)
        self.assertEqual(code, 0, output)
        self.assertIn('restart', calls)

    def test_drifted_source_files_alone_are_rewritten_and_restart_the_unit(self):
        installed = {**self.INSTALLED, 'etc/orbit-hub-source.service': '[Unit]\nDescription=old\n'}
        code, output, calls = self.source(LIVE, RIGHT, installed=installed, modes={'etc/hub-source': 0o600})
        self.assertEqual(code, 0, output)
        write = output.split('TASK [Write the source script and unit]')[1].split('TASK [')[0]
        self.assertEqual(write.count('changed: '), 2, write)
        self.assertIn('etc/hub-source)', write)
        self.assertIn('etc/orbit-hub-source.service)', write)
        # The copy loops over the drifted files alone; the matching networkd file is not copied again.
        self.assertNotIn('ok: [node] => (item=', write)
        self.assertIn('systemctl daemon-reload', calls)
        self.assertIn('restart orbit-hub-source.service', calls)
        self.assertEqual(self.left['etc/orbit-hub-source.service'], '[Unit]\n')

    def test_a_failed_file_check_rewrites_every_source_file(self):
        installed = {**self.INSTALLED, 'bin/python3': '#!/bin/sh\nexit 1\n'}
        code, output, _ = self.source(LIVE, RIGHT, installed=installed)
        self.assertEqual(code, 0, output)
        write = output.split('TASK [Write the source script and unit]')[1].split('TASK [')[0]
        self.assertEqual(write.count('(item='), 3, write)

    def hub_files(self, files, modes=None):
        """Run the hub's file group, without the prune and filter imports, against files under the test folder."""
        block = next(task for task in yaml.safe_load((ANSIBLE / 'tasks/node-converge-hub.yml').read_text())
                     if task['name'] == 'Reconcile the hub files')
        block['block'] = [task for task in block['block'] if task.get('ansible.builtin.import_tasks') == 'managed-files-check.yml'
                          or 'ansible.builtin.copy' in task]
        loaded = json.loads(json.dumps([block]).replace('/etc/', '@DIR@/etc/'))
        hub_plan = {'config': '[Interface]\n', 'peers': [{'node_id': 3, 'file': '@DIR@/etc/wireguard/orbit-peers.d/3.conf', 'content': '[Peer]\n'}]}
        return self.run_tasks('node-converge-hub.yml', {'hub_plan': hub_plan}, files, loaded=loaded, modes=modes)

    HUB_FILES: ClassVar[dict] = {'etc/wireguard/orbit.conf': '[Interface]\n', 'etc/wireguard/orbit-peers.d/3.conf': '[Peer]\n',
                                 'etc/sysctl.d/99-orbit-forwarding.conf': 'net.ipv4.ip_forward = 1\n'}

    HUB_MODES: ClassVar[dict] = {'etc/wireguard/orbit.conf': 0o600, 'etc/wireguard/orbit-peers.d/3.conf': 0o600}

    def test_matching_hub_files_are_not_copied_again(self):
        code, output, _ = self.hub_files(self.HUB_FILES, self.HUB_MODES)
        self.assertEqual(code, 0, output)
        self.assertIn('changed=0', output)
        self.assertNotIn('(item=', output)

    def test_only_drifted_hub_files_are_copied(self):
        files = {**self.HUB_FILES, 'etc/wireguard/orbit-peers.d/3.conf': '[Peer]\n# drift\n'}
        code, output, _ = self.hub_files(files, {**self.HUB_MODES, 'etc/sysctl.d/99-orbit-forwarding.conf': 0o600})
        self.assertEqual(code, 0, output)
        self.assertIn('changed=2', output)
        # The matching configuration is skipped, which this callback does not print.
        self.assertNotIn('TASK [Reconcile the recorded hub configuration]', output)
        self.assertEqual(self.left['etc/wireguard/orbit-peers.d/3.conf'], '[Peer]\n')
        self.assertEqual(self.left['etc/sysctl.d/99-orbit-forwarding.conf'], 'net.ipv4.ip_forward = 1\n')

    def test_an_endpoint_address_the_hub_does_not_hold_fails_before_any_unit_change(self):
        code, output, calls = self.source(LIVE, RIGHT, local='')
        self.assertNotEqual(code, 0)
        self.assertIn('vpn.endpoint_not_local', output)
        self.assertNotIn('restart', calls)

    def test_a_peer_in_the_hubs_public_prefix_routed_through_the_gateway_restarts_the_unit(self):
        # services sits in the hub's own /22: its marked packets must stay on-link, from the endpoint, not hairpin via the router.
        via = {**self.INSTALLED, 'onlink.txt': '85.9.216.0 via 85.9.216.1 dev eth0 table 51820 src 94.237.124.160 mark 0xca6c uid 0\n'}
        code, output, calls = self.source(LIVE, RIGHT, installed=via)
        self.assertEqual(code, 0, output)
        self.assertIn('restart', calls)
        self.assertIn('ip -4 -o route get 85.9.216.0 mark 51820', calls)

    def test_a_default_gateway_that_changed_since_the_unit_ran_restarts_it(self):
        moved = {**self.INSTALLED, 'main.txt': '192.0.2.1 via 85.9.216.9 dev eth0 src 85.9.218.89 uid 0\n'}
        code, output, calls = self.source(LIVE, RIGHT, installed=moved)
        self.assertEqual(code, 0, output)
        self.assertIn('restart', calls)

    def test_a_lan_ssh_rule_of_an_earlier_hub_lan_address_is_removed_and_the_current_one_kept(self):
        added = ("ufw allow from 10.1.1.0/24 to 10.1.1.2 port 22 proto tcp comment 'orbit:vpn-lan-ssh'\n"
                 "ufw allow from 10.0.0.0/24 to 10.0.0.2 port 22 proto tcp comment 'orbit:vpn-lan-ssh'\n"
                 "ufw allow in on orbit to 10.44.0.1 port 22 proto tcp comment 'orbit:vpn-ssh'\n")
        code, output, calls = self.source(LIVE, RIGHT, installed={**self.INSTALLED, 'ufw-added.txt': added})
        self.assertEqual(code, 0, output)
        self.assertIn('ufw delete allow in proto tcp from 10.1.1.0/24 to 10.1.1.2 port 22\n', calls)
        self.assertNotIn('from 10.0.0.0/24 to 10.0.0.2 port 22\n', calls.replace('ufw allow in proto tcp from 10.0.0.0/24', ''))
        self.assertNotIn('vpn-ssh', calls.replace('vpn-lan-ssh', ''))

    def test_a_hub_whose_endpoint_is_no_longer_an_ipv4_address_removes_the_earlier_unit_rules_and_table(self):
        installed = {name: body for name, body in self.INSTALLED.items() if name.startswith('etc/')}
        code, output, calls = self.run_tasks('node-hub-source.yml', {'hub_source': None, 'hub_source_owner': OWNER, 'hub_lan_ssh_argv': [],
                                                                         'hub_peer_forwarding_argv': []},
                                             {**installed, 'bin/ip': RETIRE_IP, 'ufw.txt': ''})
        self.assertEqual(code, 0, output)
        self.assertIn('systemctl stop orbit-hub-source.service', calls)
        self.assertIn('ip -4 rule del priority 31821', calls)
        self.assertIn('ip -4 route flush table 51820', calls)
        self.assertEqual([name for name in self.left if name.startswith('etc/')], [])

    def test_a_new_hub_endpoint_in_the_files_is_a_switch(self):
        code, output, _ = self.switch({'wg/orbit.peers': self.PEERS.format(self.PUBLIC)}, self.LAN)
        self.assertEqual(code, 0, output)
        self.assertIn('present=True switch=True', output)
        code, output, _ = self.switch({'wg/orbit.conf': 'only a conf\n'}, self.LAN)
        self.assertIn('present=False switch=True', output)
        # A node:add that stopped early leaves an empty peer file: no live tunnel, so a retry is no refused move.
        code, output, _ = self.switch({'wg/orbit.peers': ''}, self.LAN)
        self.assertIn('present=False switch=True', output)

    def test_an_unchanged_endpoint_is_no_switch_even_as_a_hostname_or_after_the_peer_roamed(self):
        # The files decide: `wg show` would print a hostname resolved, and the address a roamed peer moved to.
        for endpoint in ('vpn.example.com:51820', self.PUBLIC):
            with self.subTest(endpoint=endpoint):
                code, output, _ = self.switch({'wg/orbit.peers': self.PEERS.format(endpoint)}, endpoint)
                self.assertEqual(code, 0, output)
                self.assertIn('present=True switch=False', output)

    def test_a_move_that_was_cut_off_is_decided_from_the_file_it_replaced(self):
        files = {'wg/orbit.peers': self.PEERS.format(self.LAN), 'wg/orbit.peers.previous': self.PEERS.format(self.PUBLIC)}
        code, output, _ = self.switch(files, self.LAN)
        self.assertEqual(code, 0, output)
        self.assertIn('switch=True', output)

    def test_the_endpoint_is_read_on_the_node_so_no_key_in_the_file_leaves_it(self):
        code, output, _ = self.switch({'wg/orbit.peers': '[Interface]\nPrivateKey = SECRETKEY\n' + self.PEERS.format(self.PUBLIC)}, self.LAN)
        self.assertEqual(code, 0, output)
        self.assertNotIn('SECRETKEY', output)


class NetworkPhaseWiringTest(unittest.TestCase):
    def test_the_peer_phase_decides_from_the_previous_file_stages_the_files_and_moves_in_one_job(self):
        block = peer_block()
        names = [task['name'] for task in block]
        order = [names.index(name) for name in ("Prove the hub's LAN endpoint before any WireGuard change",
                                                'Decide whether the hub endpoint changes', 'Reconcile recorded peer files',
                                                'Move the tunnel to the new hub endpoint',
                                                'Reconcile live peer state without copying its private key')]
        self.assertEqual(order, sorted(order))
        # The copy writes only the files that the one-read check names, from the same staged list.
        staged = peer_task()['vars']
        self.assertEqual(staged['peer_staged'], "{{ '.next' if hub_switch | bool else '' }}")
        self.assertEqual([path for path, _, _ in staged['peer_files_wanted']],
                         ['/etc/wireguard/orbit.conf{{ peer_staged }}', '/etc/wireguard/orbit.peers{{ peer_staged }}', '/etc/wireguard/orbit.dns-link'])
        self.assertIn('peer_files_wanted', block[names.index('Reconcile recorded peer files')]['loop'])
        self.assertLess(names.index('Check the recorded peer files'), names.index('Reconcile recorded peer files'))
        self.assertNotIn('slurp', yaml.safe_dump(block))
        self.assertEqual(block[names.index('Reconcile live peer state without copying its private key')]['when'], 'not hub_switch | bool')
        tasks = yaml.safe_load((ANSIBLE / 'tasks/node-converge-hub-move.yml').read_text())
        move = tasks[0]
        self.assertEqual((move['async'] > 60, move['poll'] > 0), (True, True))
        # A poll during the move may come back unreachable; the converge reaches the Node again and reads the recorded outcome.
        self.assertTrue(move['ignore_unreachable'])
        names = [task['name'] for task in tasks]
        self.assertLess(names.index('Reach the Node again through the tunnel the move left'), names.index('Read the outcome of the move'))
        self.assertIn('hub_move_outcome', tasks[-1]['ansible.builtin.assert']['that'])

    def test_the_peer_phase_drops_the_copies_of_a_cut_off_move_when_it_no_longer_moves(self):
        block = peer_block()
        names = [task['name'] for task in block]
        drop = block[names.index('Drop the copies of a cut-off move that no longer moves')]
        self.assertEqual(drop['when'], 'not hub_switch | bool')
        self.assertEqual(sorted(drop['loop']), ['/etc/wireguard/orbit.conf.previous', '/etc/wireguard/orbit.live.previous',
                                                '/etc/wireguard/orbit.peers.previous'])
        self.assertEqual(drop['ansible.builtin.file']['state'], 'absent')
        self.assertLess(names.index('Decide whether the hub endpoint changes'), names.index('Drop the copies of a cut-off move that no longer moves'))
        self.assertLess(names.index('Drop the copies of a cut-off move that no longer moves'), names.index('Reconcile recorded peer files'))

    def test_node_add_refuses_to_move_any_live_tunnel_to_another_hub_endpoint(self):
        play = next(play for play in yaml.safe_load((ANSIBLE / 'operation-node-add.yml').read_text()) if play['name'] == 'Set up the tunnel')
        tasks = {task['name']: task for task in play['tasks']}
        self.assertEqual(tasks['Decide whether the hub endpoint changes']['when'], 'not net.is_hub')
        refusal = tasks['Leave the move of a live tunnel to node:converge']
        self.assertEqual((refusal['when'], refusal['ansible.builtin.assert']['fail_msg']), ('not net.is_hub', 'node.hub_switch_by_converge'))


if __name__ == '__main__':
    unittest.main()
