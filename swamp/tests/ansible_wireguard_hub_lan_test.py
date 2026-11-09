"""The LAN proof of a peer and the hub's source rule (ADR 0251), run on this machine with stand-in `ip` and `ping`."""
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ANSIBLE = Path(__file__).resolve().parents[1] / 'ansible'
COMMAND = shutil.which('ansible-playbook') or str(Path(sys.executable).parent / 'ansible-playbook')
NEEDS_ANSIBLE = unittest.skipUnless(shutil.which('ansible-playbook') or (Path(sys.executable).parent / 'ansible-playbook').exists(),
                                    'ansible-playbook is not installed')
# The stand-ins answer from files in $STUB_DIR and log every call. `route replace` marks the source rule as applied.
IP = """#!/bin/sh
echo "ip $*" >> "$STUB_DIR/calls.log"
case "$*" in
  "-4 -j route get "*) cat "$STUB_DIR/route.json"; exit "$(cat "$STUB_DIR/route.rc" 2>/dev/null || echo 0)" ;;
  "-4 -o address show to "*) cat "$STUB_DIR/local.txt" ;;
  "-4 -j rule show") if [ -f "$STUB_DIR/applied" ]; then cat "$STUB_DIR/rules.applied.json"; else cat "$STUB_DIR/rules.json"; fi ;;
  "-4 -o route get 192.0.2.1 mark "*) if [ -f "$STUB_DIR/applied" ]; then cat "$STUB_DIR/source.applied.txt"; else cat "$STUB_DIR/source.txt"; fi ;;
  "-4 route show default") echo "default via 85.9.216.1 dev eth0 proto dhcp src 85.9.218.89 metric 100" ;;
  "-4 route replace "*) touch "$STUB_DIR/applied" ;;
esac
"""
PING = """#!/bin/sh
echo "ping $*" >> "$STUB_DIR/calls.log"
exit "$(cat "$STUB_DIR/ping.rc")"
"""
LAN = {'address': '10.0.0.2', 'source': '10.0.0.4'}
SOURCE = {'address': '94.237.124.160', 'mark': 51820, 'rules': ['31820:0xca6c:main', '31821:0xca6c:51820'],
          'commands': ['ip -4 rule del priority 31820 2>/dev/null; ip -4 rule add priority 31820 fwmark 51820 lookup main suppress_prefixlength 0',
                       'ip -4 rule del priority 31821 2>/dev/null; ip -4 rule add priority 31821 fwmark 51820 lookup 51820',
                       ('r=$(ip -4 route show default | awk \'{for (i = 1; i < NF; i++) if ($i == "via" || $i == "dev") printf "%s %s ", $i, $(i + 1); exit}\'); '
                        '[ -z "$r" ] || ip -4 route replace default $r src 94.237.124.160 table 51820 || true')]}
RULES = json.dumps([{'priority': 0, 'src': 'all', 'table': 'local'},
                    {'priority': 31820, 'src': 'all', 'fwmark': '0xca6c', 'table': 'main', 'suppress_prefixlength': 0},
                    {'priority': 31821, 'src': 'all', 'fwmark': '0xca6c', 'table': '51820'},
                    {'priority': 32766, 'src': 'all', 'table': 'main'}])
WRONG = '192.0.2.1 via 85.9.216.1 dev eth0 src 85.9.218.89 mark 0xca6c uid 0 \\    cache\n'
RIGHT = '192.0.2.1 via 85.9.216.1 dev eth0 table 51820 src 94.237.124.160 mark 0xca6c uid 0 \\    cache\n'


@NEEDS_ANSIBLE
class WireGuardHubLanTest(unittest.TestCase):
    def run_tasks(self, task_file, variables, files):
        """Run one task file on this machine; returns the exit code, the output and the logged calls."""
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            (folder / 'bin').mkdir()
            for name, body in (('ip', IP), ('ping', PING)):
                (folder / 'bin' / name).write_text(body)
                (folder / 'bin' / name).chmod(0o755)
            for name, body in files.items():
                (folder / name).write_text(body)
            (folder / 'play.yml').write_text(json.dumps([{'hosts': 'node', 'gather_facts': False,
                                                          'tasks': [{'ansible.builtin.include_tasks': str(ANSIBLE / 'tasks' / task_file)}]}]))
            (folder / 'inventory.yml').write_text(json.dumps({'all': {'hosts': {'node': {
                'ansible_connection': 'local', 'ansible_python_interpreter': sys.executable}}}}))
            result = subprocess.run([COMMAND, '-i', str(folder / 'inventory.yml'), '-e', json.dumps(variables), str(folder / 'play.yml')],
                                    check=False, capture_output=True, text=True, timeout=120, env={
                                        **os.environ, 'PATH': f"{folder / 'bin'}:{os.environ['PATH']}", 'STUB_DIR': str(folder),
                                        'ANSIBLE_CONFIG': str(ANSIBLE / 'ansible.cfg'), 'ANSIBLE_FORCE_COLOR': '0'})
            calls = (folder / 'calls.log').read_text() if (folder / 'calls.log').exists() else ''
            return result.returncode, result.stdout + result.stderr, calls

    def prove(self, route, ping=0):
        return self.run_tasks('node-hub-lan-proof.yml', {'hub_lan': LAN}, {'route.json': json.dumps([route]), 'ping.rc': str(ping)})

    def test_a_direct_route_from_the_recorded_lan_address_that_answers_proves_the_lan(self):
        code, output, calls = self.prove({'dst': '10.0.0.2', 'dev': 'eth1', 'prefsrc': '10.0.0.4'})
        self.assertEqual(code, 0, output)
        self.assertIn('ping -c 3 -W 2 -I 10.0.0.4 10.0.0.2', calls)

    def test_a_routed_or_foreign_source_or_silent_hub_refuses_before_any_change(self):
        for route, ping in (({'dst': '10.0.0.2', 'gateway': '192.168.6.1', 'dev': 'eth0', 'prefsrc': '192.168.6.20'}, 0),
                            ({'dst': '10.0.0.2', 'dev': 'eth1', 'prefsrc': '10.0.0.9'}, 0),
                            ({'dst': '10.0.0.2', 'dev': 'orbit', 'prefsrc': '10.0.0.4'}, 0),
                            ({'dst': '10.0.0.2', 'dev': 'eth1', 'prefsrc': '10.0.0.4'}, 1)):
            with self.subTest(route=route, ping=ping):
                code, output, _ = self.prove(route, ping)
                self.assertNotEqual(code, 0)
                self.assertIn('node.hub_lan_unreachable', output)

    def source(self, rules, source, local='2: eth0    inet 94.237.124.160/32 scope global eth0\n'):
        return self.run_tasks('node-hub-source.yml', {'hub_source': SOURCE}, {
            'local.txt': local, 'rules.json': rules, 'rules.applied.json': RULES, 'source.txt': source, 'source.applied.txt': RIGHT})

    def test_a_hub_sending_from_its_primary_address_gets_the_source_rule_and_then_sends_from_the_endpoint(self):
        code, output, calls = self.source(json.dumps([{'priority': 32766, 'src': 'all', 'table': 'main'}]), WRONG)
        self.assertEqual(code, 0, output)
        self.assertIn('ip -4 rule add priority 31820 fwmark 51820 lookup main suppress_prefixlength 0', calls)
        self.assertIn('ip -4 route replace default via 85.9.216.1 dev eth0 src 94.237.124.160 table 51820', calls)

    def test_a_hub_that_already_sends_from_the_endpoint_changes_nothing(self):
        code, output, calls = self.source(RULES, RIGHT)
        self.assertEqual(code, 0, output)
        self.assertNotIn('rule add', calls)
        self.assertIn('changed=0', output)

    def test_an_endpoint_address_the_hub_does_not_hold_fails(self):
        code, output, calls = self.source(RULES, RIGHT, local='')
        self.assertNotEqual(code, 0)
        self.assertIn('vpn.endpoint_not_local', output)
        self.assertNotIn('rule add', calls)


if __name__ == '__main__':
    unittest.main()
