"""The LAN proof of a peer and the hub's source unit (ADR 0251), run on this machine with stand-in `ip`, `ssh-keyscan`,
`ssh-keygen` and `systemctl`."""
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path

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
  "-4 -j rule show") if [ -f "$STUB_DIR/applied" ]; then cat "$STUB_DIR/rules.applied.json"; else cat "$STUB_DIR/rules.json"; fi ;;
  "-4 -o route get 192.0.2.1 mark "*) if [ -f "$STUB_DIR/applied" ]; then cat "$STUB_DIR/source.applied.txt"; else cat "$STUB_DIR/source.txt"; fi ;;
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
    def run_tasks(self, task_file, variables, files):
        """Run one task file on this machine; returns the exit code, the output and the logged calls."""
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            (folder / 'bin').mkdir()
            for name, body in (('ip', IP), ('ssh-keyscan', KEYSCAN), ('ssh-keygen', KEYGEN), ('systemctl', SYSTEMCTL),
                               ('ufw', '#!/bin/sh\necho "ufw $*" >> "$STUB_DIR/calls.log"\ncat "$STUB_DIR/ufw.txt"\n')):
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
                                                          'tasks': [{'ansible.builtin.include_tasks': str(tasks)}]}]))
            (folder / 'inventory.yml').write_text(json.dumps({'all': {'hosts': {'node': {
                'ansible_connection': 'local', 'ansible_python_interpreter': sys.executable}}}}))
            result = subprocess.run([COMMAND, '-i', str(folder / 'inventory.yml'), '-e', json.dumps(variables), str(folder / 'play.yml')],
                                    check=False, capture_output=True, text=True, timeout=120, env={
                                        **os.environ, 'PATH': f"{folder / 'bin'}:{os.environ['PATH']}", 'STUB_DIR': str(folder),
                                        'ANSIBLE_CONFIG': str(ANSIBLE / 'ansible.cfg'), 'ANSIBLE_FORCE_COLOR': '0'})
            calls = (folder / 'calls.log').read_text() if (folder / 'calls.log').exists() else ''
            return result.returncode, result.stdout + result.stderr, calls

    def prove(self, route, keys, fingerprints):
        return self.run_tasks('node-hub-lan-proof.yml', {'hub_lan': LAN},
                              {'route.json': json.dumps([route]), 'keyscan.txt': keys, 'keygen.txt': fingerprints})

    DIRECT = {'dst': '10.0.0.2', 'dev': 'eth1', 'prefsrc': '10.0.0.4'}
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
        hub_source = {'address': '94.237.124.160', 'mark': 51820, 'rules': RULES, 'script': script, 'unit': '[Unit]\n',
                      'script_path': '@DIR@/etc/hub-source', 'unit_path': '@DIR@/etc/orbit-hub-source.service',
                      'unit_name': 'orbit-hub-source.service'}
        return self.run_tasks('node-hub-source.yml', {'hub_source': hub_source, 'hub_lan_ssh_argv': LAN_SSH}, {
            'local.txt': local, 'rules.json': rules, 'rules.applied.json': LIVE, 'source.txt': source, 'source.applied.txt': RIGHT,
            **(installed or {})})

    INSTALLED = {'etc/hub-source': '#!/bin/sh\n', 'etc/orbit-hub-source.service': '[Unit]\n',
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


    def handshake(self, latest, since):
        wg = '#!/bin/sh\nprintf "HUBKEY\\t%s\\n" "$(cat "$STUB_DIR/latest.txt")"\n'
        return self.run_tasks('node-converge-handshake.yml', {'peer_key': 'HUBKEY', 'handshake_since': since},
                              {'latest.txt': str(latest), 'bin/wg': wg})

    def test_after_an_endpoint_switch_only_a_handshake_at_or_after_it_counts(self):
        switched = int(time.time()) - 10
        code, output, _ = self.handshake(switched + 3, str(switched))
        self.assertEqual(code, 0, output)
        # A handshake from before the switch went through the old endpoint; the old 180-second window would accept it.
        code, output, _ = self.handshake(switched - 30, str(switched))
        self.assertNotEqual(code, 0)

if __name__ == '__main__':
    unittest.main()
