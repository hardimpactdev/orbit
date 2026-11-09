"""The LAN proof of a peer and the hub's source unit (ADR 0251), run on this machine with stand-in `ip`, `ssh-keyscan`,
`ssh-keygen` and `systemctl`."""
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from typing import ClassVar

import yaml

ANSIBLE = Path(__file__).resolve().parents[1] / 'ansible'
COMMAND = shutil.which('ansible-playbook') or str(Path(sys.executable).parent / 'ansible-playbook')
NEEDS_ANSIBLE = unittest.skipUnless(shutil.which('ansible-playbook') or (Path(sys.executable).parent / 'ansible-playbook').exists(),
                                    'ansible-playbook is not installed')
# The stand-ins answer from files in $STUB_DIR and log every call. Restarting the unit marks the rules as applied.
IP = """#!/bin/sh
echo "ip $*" >> "$STUB_DIR/calls.log"
case "$*" in
  "-4 -j route get "*) cat "$STUB_DIR/route.json"; exit "$(cat "$STUB_DIR/route.rc" 2>/dev/null || echo 0)" ;;
  "-4 -o address show to "*) cat "$STUB_DIR/local.txt" ;;
  "-4 -j rule show") if [ -f "$STUB_DIR/applied" ]; then cat "$STUB_DIR/rules.applied.json"; else cat "$STUB_DIR/rules.json"; fi; echo ;;
  "-4 -o route get 192.0.2.1 mark "*) if [ -f "$STUB_DIR/applied" ]; then cat "$STUB_DIR/source.applied.txt"; else cat "$STUB_DIR/source.txt"; fi ;;
  "-4 -o route get 192.0.2.1") cat "$STUB_DIR/main.txt" 2>/dev/null || echo '192.0.2.1 via 85.9.216.1 dev eth0 src 85.9.218.89 uid 0' ;;
  "-4 route show default") echo 'default via 85.9.216.1 dev eth0 proto dhcp src 85.9.218.89 metric 100' ;;
  "-4 route show table main proto kernel scope link dev eth0") echo '85.9.216.0/22 proto kernel scope link src 85.9.218.89' ;;
  "-4 -o route get 85.9.216.0 mark "*) if [ -f "$STUB_DIR/applied" ]; then echo '85.9.216.0 dev eth0 table 51820 src 94.237.124.160 mark 0xca6c uid 0'; else cat "$STUB_DIR/onlink.txt" 2>/dev/null || echo '85.9.216.0 dev eth0 table 51820 src 94.237.124.160 mark 0xca6c uid 0'; fi ;;
esac
"""
KEYSCAN = """#!/bin/sh
echo "ssh-keyscan $*" >> "$STUB_DIR/calls.log"
cat "$STUB_DIR/keyscan.txt" 2>/dev/null
"""
KEYGEN = """#!/bin/sh
echo "ssh-keygen $*" >> "$STUB_DIR/calls.log"
cat > /dev/null
cat "$STUB_DIR/keygen.txt"
"""
SYSTEMCTL = """#!/bin/sh
echo "systemctl $*" >> "$STUB_DIR/calls.log"
case "$*" in
  *show*) printf 'Id=orbit-hub-source.service\\nLoadState=loaded\\nActiveState=active\\nSubState=exited\\nUnitFileState=enabled\\n' ;;
  *is-enabled*) echo enabled ;;
  *is-active*) echo active ;;
  *restart*) touch "$STUB_DIR/applied" ;;
esac
"""
UFW = """#!/bin/sh
echo "ufw $*" >> "$STUB_DIR/calls.log"
if [ "$1 $2" = "show added" ]; then cat "$STUB_DIR/ufw-added.txt" 2>/dev/null || true; else cat "$STUB_DIR/ufw.txt"; fi
"""
OWNER = {'unit_name': 'orbit-hub-source.service', 'unit_path': '@DIR@/etc/orbit-hub-source.service', 'script_path': '@DIR@/etc/hub-source',
         'networkd_path': '@DIR@/etc/networkd/orbit-hub-source.conf', 'priorities': [31820, 31821], 'table': 51820}
RETIRE_IP = IP.replace('esac', '  "-4 rule del priority "*) n=$(cat "$STUB_DIR/del" 2>/dev/null || echo 0); echo $((n + 1)) > "$STUB_DIR/del"; '
                       '[ "$n" -lt 2 ] ;;\n  "-4 route show table 51820") echo "default via 85.9.216.1 dev eth0" ;;\nesac')
HUB_KEY = 'SHA256:TP06RDL2oMgX8CDMhbQMVPkTgBz5oH7PDMb2QcpY8CE'
LAN = {'address': '10.0.0.2', 'source': '10.0.0.4', 'fingerprint': HUB_KEY}
RULES = ['31820:0xca6c:10.0.0.0/8:main', '31820:0xca6c:172.16.0.0/12:main', '31820:0xca6c:192.168.0.0/16:main', '31821:0xca6c:all:51820']
LIVE = json.dumps([{'priority': 0, 'src': 'all', 'table': 'local'},
                   {'priority': 31820, 'src': 'all', 'fwmark': '0xca6c', 'dst': '10.0.0.0', 'dstlen': 8, 'table': 'main'},
                   {'priority': 31820, 'src': 'all', 'fwmark': '0xca6c', 'dst': '172.16.0.0', 'dstlen': 12, 'table': 'main'},
                   {'priority': 31820, 'src': 'all', 'fwmark': '0xca6c', 'dst': '192.168.0.0', 'dstlen': 16, 'table': 'main'},
                   {'priority': 31821, 'src': 'all', 'fwmark': '0xca6c', 'table': '51820'},
                   {'priority': 32766, 'src': 'all', 'table': 'main'}])
OLD = json.dumps([{'priority': 31820, 'src': 'all', 'fwmark': '0xca6c', 'table': 'main', 'suppress_prefixlength': 0},
                  {'priority': 31821, 'src': 'all', 'fwmark': '0xca6c', 'table': '51820'},
                  {'priority': 32766, 'src': 'all', 'table': 'main'}])
WRONG = '192.0.2.1 via 85.9.216.1 dev eth0 src 85.9.218.89 mark 0xca6c uid 0 \\    cache\n'
RIGHT = '192.0.2.1 via 85.9.216.1 dev eth0 table 51820 src 94.237.124.160 mark 0xca6c uid 0 \\    cache\n'
LAN_SSH = [['ufw', 'allow', 'in', 'proto', 'tcp', 'from', '10.0.0.0/24', 'to', '10.0.0.2', 'port', '22', 'comment', 'orbit:vpn-lan-ssh']]


def without_owner(node):
    """The task files set root ownership; a test on this machine runs as its user, so the stand-in copy drops it."""
    if isinstance(node, dict):
        return {key: without_owner(value) for key, value in node.items() if key not in ('owner', 'group')}
    if isinstance(node, list):
        return [without_owner(item) for item in node]
    return node


@NEEDS_ANSIBLE
class WireGuardHubLanTest(unittest.TestCase):
    def run_tasks(self, task_file, variables, files, after=()):
        """Run one task file on this machine; returns the exit code, the output and the logged calls."""
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            (folder / 'bin').mkdir()
            for name, body in (('ip', IP), ('ssh-keyscan', KEYSCAN), ('ssh-keygen', KEYGEN), ('systemctl', SYSTEMCTL),
                               ('ufw', UFW)):
                (folder / 'bin' / name).write_text(body)
                (folder / 'bin' / name).chmod(0o755)
            for name, body in {'ufw.txt': 'Rule added\n', **files}.items():
                (folder / name).parent.mkdir(parents=True, exist_ok=True)
                (folder / name).write_text(body)
                if name.startswith('bin/'):
                    (folder / name).chmod(0o755)
            if (folder / 'etc/hub-source').exists():
                (folder / 'etc/hub-source').chmod(0o700)
            tasks = folder / task_file
            tasks.write_text(yaml.safe_dump(without_owner(yaml.safe_load((ANSIBLE / 'tasks' / task_file).read_text()))))
            variables = json.loads(json.dumps(variables).replace('@DIR@', str(folder)))
            (folder / 'play.yml').write_text(json.dumps([{'hosts': 'node', 'gather_facts': False,
                                                          'tasks': [{'ansible.builtin.include_tasks': str(tasks)}, *after]}]))
            (folder / 'inventory.yml').write_text(json.dumps({'all': {'hosts': {'node': {
                'ansible_connection': 'local', 'ansible_python_interpreter': sys.executable}}}}))
            result = subprocess.run([COMMAND, '-i', str(folder / 'inventory.yml'), '-e', json.dumps(variables), str(folder / 'play.yml')],
                                    check=False, capture_output=True, text=True, timeout=120, env={
                                        **os.environ, 'PATH': f"{folder / 'bin'}:{os.environ['PATH']}", 'STUB_DIR': str(folder),
                                        'ANSIBLE_CONFIG': str(ANSIBLE / 'ansible.cfg'), 'ANSIBLE_FORCE_COLOR': '0'})
            calls = (folder / 'calls.log').read_text() if (folder / 'calls.log').exists() else ''
            # What the run left behind, for the tests that read files after a failed playbook.
            self.left = {str(path.relative_to(folder)): path.read_text() for path in folder.rglob('*')
                         if path.is_file() and path.parts[len(folder.parts)] in ('wg', 'etc', 'loaded.log', 'rules.txt')}
            return result.returncode, result.stdout + result.stderr, calls

    def prove(self, route, keys, fingerprints):
        return self.run_tasks('node-hub-lan-proof.yml', {'hub_lan': LAN},
                              {'route.json': json.dumps([route]), 'keyscan.txt': keys, 'keygen.txt': fingerprints})

    DIRECT: ClassVar[dict] = {'dst': '10.0.0.2', 'dev': 'eth1', 'prefsrc': '10.0.0.4'}
    HUB_KEYS = '10.0.0.2 ssh-ed25519 AAAAhub\n10.0.0.2 ssh-rsa AAAArsa\n'
    HUB_PRINTS = f'256 {HUB_KEY} 10.0.0.2 (ED25519)\n3072 SHA256:rsaKeyPrint 10.0.0.2 (RSA)\n'

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

    def source(self, rules, source, local='2: eth0    inet 94.237.124.160/32 scope global eth0\n', script='#!/bin/sh\n', installed=None):
        hub_source = {'address': '94.237.124.160', 'mark': 51820, 'priorities': [31820, 31821], 'rules': RULES, 'script': script,
                      'unit': '[Unit]\n', 'networkd_path': '@DIR@/etc/networkd/orbit-hub-source.conf', 'networkd': '[Network]\n',
                      'script_path': '@DIR@/etc/hub-source', 'unit_path': '@DIR@/etc/orbit-hub-source.service',
                      'unit_name': 'orbit-hub-source.service'}
        return self.run_tasks('node-hub-source.yml', {'hub_source': hub_source, 'hub_source_owner': OWNER, 'hub_lan_ssh_argv': LAN_SSH}, {
            'local.txt': local, 'rules.json': rules, 'rules.applied.json': LIVE, 'source.txt': source, 'source.applied.txt': RIGHT,
            **(installed or {})})

    INSTALLED: ClassVar[dict] = {'etc/hub-source': '#!/bin/sh\n', 'etc/orbit-hub-source.service': '[Unit]\n',
                                 'etc/networkd/orbit-hub-source.conf': '[Network]\n',
                 'ufw.txt': 'Skipping adding existing rule\n'}

    def test_a_hub_with_the_old_suppress_rule_gets_the_private_range_rules_from_its_unit(self):
        code, output, calls = self.source(OLD, WRONG)
        self.assertEqual(code, 0, output)
        self.assertIn('restart', calls)
        self.assertIn('ufw allow in proto tcp from 10.0.0.0/24 to 10.0.0.2 port 22', calls)

    def test_a_hub_whose_unit_already_applies_the_rules_changes_nothing(self):
        code, output, calls = self.source(LIVE, RIGHT, installed=self.INSTALLED)
        self.assertEqual(code, 0, output)
        self.assertNotIn('restart', calls)
        self.assertIn('changed=0', output)

    def test_rules_that_drifted_under_an_unchanged_unit_restart_it(self):
        code, output, calls = self.source(OLD, WRONG, installed=self.INSTALLED)
        self.assertEqual(code, 0, output)
        self.assertIn('restart', calls)

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
        code, output, calls = self.run_tasks('node-hub-source.yml', {'hub_source': None, 'hub_source_owner': OWNER, 'hub_lan_ssh_argv': []},
                                             {**installed, 'bin/ip': RETIRE_IP, 'ufw.txt': ''})
        self.assertEqual(code, 0, output)
        self.assertIn('systemctl stop orbit-hub-source.service', calls)
        self.assertIn('ip -4 rule del priority 31821', calls)
        self.assertIn('ip -4 route flush table 51820', calls)
        self.assertEqual([name for name in self.left if name.startswith('etc/')], [])

    def switch(self, files, endpoint):
        return self.run_tasks('node-converge-hub-switch.yml', {'hub_switch_dir': '@DIR@/wg', 'hub_switch_endpoint': endpoint}, files,
                              after=[{'ansible.builtin.debug': {'msg': 'present={{ hub_switch_present }} switch={{ hub_switch | bool }}'}}])

    PEERS = '[Peer]\nPublicKey = HUBKEY\nEndpoint = {}\nAllowedIPs = 10.44.0.0/24\nPersistentKeepalive = 25\n'
    PUBLIC = '94.237.124.160:51820'
    LAN = '10.0.0.2:51820'

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

    WG = ('#!/bin/sh\necho "wg $*" >> "$STUB_DIR/calls.log"\n'
          'case "$*" in\n'
          '  "showconf orbit") echo "LIVE CONFIG" ;;\n'
          '  "setconf orbit "*) echo "setconf: $(cat "$3")" >> "$STUB_DIR/loaded.log" ;;\n'
          '  "syncconf orbit "*) cat "$3" >> "$STUB_DIR/loaded.log"; echo --- >> "$STUB_DIR/loaded.log" ;;\n'
          '  "show orbit latest-handshakes") printf "HUBKEY\\t%s\\n" "$(cat "$STUB_DIR/handshake.txt")" ;;\n'
          '  "show orbit endpoints") printf "HUBKEY\\t%s\\n" "$(cat "$STUB_DIR/endpoint.txt")" ;;\n'
          '  "show orbit listen-port") echo 39975 ;;\n'
          'esac\n')
    # Keeps the INPUT rules in rules.txt, one spec per line, and lists them as `iptables -n -L --line-numbers` does, with the
    # comment as /* comment */; a rule is deleted by its number. iptables.fail makes an insert fail.
    IPTABLES = ('#!/bin/sh\necho "iptables $*" >> "$STUB_DIR/calls.log"\n[ "$1" != -w ] || shift\ntouch "$STUB_DIR/rules.txt"\n'
                'case "$1" in\n'
                '  -n) echo "Chain INPUT (policy DROP)"; echo "num  target prot opt source destination"\n'
                "      awk '{ c = $0; sub(/.*--comment /, \"\", c); sub(/ .*/, \"\", c); print NR \"    DROP udp -- 0.0.0.0/0 0.0.0.0/0 /* \" c \" */\" }' \"$STUB_DIR/rules.txt\" ;;\n"
                '  -I) [ ! -f "$STUB_DIR/iptables.fail" ] || exit 1; shift 3; { echo "$*"; cat "$STUB_DIR/rules.txt"; } > "$STUB_DIR/rules.new"\n'
                '      mv "$STUB_DIR/rules.new" "$STUB_DIR/rules.txt" ;;\n'
                '  -D) awk -v n="$3" \'NR != n\' "$STUB_DIR/rules.txt" > "$STUB_DIR/rules.new"; mv "$STUB_DIR/rules.new" "$STUB_DIR/rules.txt" ;;\n'
                'esac\n')
    PIN = 'iptables -w -I INPUT 1 -p udp --dport 39975 ! -s 10.0.0.2 -m comment --comment orbit-hub-move -j DROP'

    def move(self, handshake, endpoint=LAN, **extra):
        """Move a tunnel from the public to the LAN endpoint with a stand-in `wg` whose hub handshake is `handshake`."""
        files = {'bin/wg': self.WG, 'bin/iptables': self.IPTABLES, 'bin/ping': '#!/bin/sh\nexit 0\n', 'handshake.txt': str(handshake), 'endpoint.txt': self.LAN,
                 'wg/orbit.key': 'KEY\n', 'wg/orbit.conf': 'old conf\n', 'wg/orbit.peers': self.PEERS.format(self.PUBLIC),
                 'wg/orbit.conf.next': 'new conf\n', 'wg/orbit.peers.next': self.PEERS.format(self.LAN), **extra}
        variables = {'hub_move': {'dir': '@DIR@/wg', 'key': 'HUBKEY', 'endpoint': endpoint, 'probe_ip': '10.44.0.1', 'interval': 0}}
        files = {name: body for name, body in files.items() if body is not None}
        return self.run_tasks('node-converge-hub-move.yml', variables, files)

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

def peer_block():
    return next(task for task in yaml.safe_load((ANSIBLE / 'tasks/node-converge-network.yml').read_text())
                if task['name'] == 'Reconcile the peer after its hub has completed')['block']

class NetworkPhaseWiringTest(unittest.TestCase):
    def test_the_peer_phase_decides_from_the_previous_file_stages_the_files_and_moves_in_one_job(self):
        block = peer_block()
        names = [task['name'] for task in block]
        order = [names.index(name) for name in ("Prove the hub's LAN endpoint before any WireGuard change",
                                                'Decide whether the hub endpoint changes', 'Reconcile recorded peer files',
                                                'Move the tunnel to the new hub endpoint',
                                                'Reconcile live peer state without copying its private key')]
        self.assertEqual(order, sorted(order))
        self.assertIn('.next', block[names.index('Reconcile recorded peer files')]['ansible.builtin.copy']['dest'])
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
