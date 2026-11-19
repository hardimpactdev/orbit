"""The shared, test-free harness of the WireGuard hub LAN fixtures (ADR 0251): it runs task files on this machine with
stand-in `ip`, `ssh-keyscan`, `ssh-keygen` and `systemctl`."""
import grp
import json
import os
import pwd
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from typing import ClassVar

import yaml

ANSIBLE = Path(__file__).resolve().parents[2] / 'ansible'
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
FORWARDING = [['ufw', 'route', 'allow', 'in', 'on', 'orbit', 'out', 'on', 'orbit', 'from', '10.44.0.0/24', 'to', '10.44.0.0/24', 'comment',
               'orbit:vpn-peer-forwarding']]
LAN_SSH = [['ufw', 'allow', 'in', 'proto', 'tcp', 'from', '10.0.0.0/24', 'to', '10.0.0.2', 'port', '22', 'comment', 'orbit:vpn-lan-ssh']]


def without_owner(node):
    """The task files set root ownership; a test on this machine runs as its user, so the stand-in copy drops it."""
    if isinstance(node, dict):
        return {key: without_owner(value) for key, value in node.items() if key not in ('owner', 'group')}
    if isinstance(node, list):
        return [without_owner(item) for item in node]
    return node


def managed_files_check():
    """The one-read file check expects root ownership; on this machine it expects the test user's, which the stand-in copy leaves."""
    tasks = yaml.safe_load((ANSIBLE / 'tasks/managed-files-check.yml').read_text())
    tasks[0]['ansible.builtin.command']['argv'][-2:] = [pwd.getpwuid(os.getuid()).pw_name, grp.getgrgid(os.getgid()).gr_name]
    return yaml.safe_dump(tasks)


def peer_task():
    return next(task for task in yaml.safe_load((ANSIBLE / 'tasks/node-converge-network.yml').read_text())
                if task['name'] == 'Reconcile the peer after its hub has completed')


def peer_block():
    return peer_task()['block']


@NEEDS_ANSIBLE
class HubLanHarness(unittest.TestCase):
    def run_tasks(self, task_file, variables, files, after=(), loaded=None, modes=None):
        """Run one task file, or the `loaded` tasks under its name, on this machine; returns the exit code, the output and the
        logged calls."""
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
            for name, mode in (modes or {}).items():
                (folder / name).chmod(mode)
            (folder / 'managed-files-check.yml').write_text(managed_files_check())
            tasks = folder / task_file
            loaded = yaml.safe_load((ANSIBLE / 'tasks' / task_file).read_text()) if loaded is None else loaded
            tasks.write_text(yaml.safe_dump(without_owner(loaded)).replace('@DIR@', str(folder)))
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

    def source(self, rules, source, local='2: eth0    inet 94.237.124.160/32 scope global eth0\n', script='#!/bin/sh\n', installed=None,
               modes=None):
        hub_source = {'address': '94.237.124.160', 'mark': 51820, 'priorities': [31820, 31821], 'rules': RULES, 'script': script,
                      'unit': '[Unit]\n', 'networkd_path': '@DIR@/etc/networkd/orbit-hub-source.conf', 'networkd': '[Network]\n',
                      'script_path': '@DIR@/etc/hub-source', 'unit_path': '@DIR@/etc/orbit-hub-source.service',
                      'unit_name': 'orbit-hub-source.service'}
        return self.run_tasks('node-hub-source.yml', {'hub_source': hub_source, 'hub_source_owner': OWNER, 'hub_lan_ssh_argv': LAN_SSH,
                                                      'hub_peer_forwarding_argv': FORWARDING}, {
            'local.txt': local, 'rules.json': rules, 'rules.applied.json': LIVE, 'source.txt': source, 'source.applied.txt': RIGHT,
            **(installed or {})}, modes=modes)

    INSTALLED: ClassVar[dict] = {'etc/hub-source': '#!/bin/sh\n', 'etc/orbit-hub-source.service': '[Unit]\n',
                                 'etc/networkd/orbit-hub-source.conf': '[Network]\n',
                 'ufw.txt': 'Skipping adding existing rule\n'}

    def switch(self, files, endpoint):
        return self.run_tasks('node-converge-hub-switch.yml', {'hub_switch_dir': '@DIR@/wg', 'hub_switch_endpoint': endpoint}, files,
                              after=[{'ansible.builtin.debug': {'msg': 'present={{ hub_switch_present }} switch={{ hub_switch | bool }}'}}])

    PEERS = '[Peer]\nPublicKey = HUBKEY\nEndpoint = {}\nAllowedIPs = 10.44.0.0/24\nPersistentKeepalive = 25\n'

    PUBLIC = '94.237.124.160:51820'

    LAN = '10.0.0.2:51820'

    WG = ('#!/bin/sh\necho "wg $*" >> "$STUB_DIR/calls.log"\n'
          'case "$*" in\n'
          '  "showconf orbit") echo "LIVE CONFIG" ;;\n'
          '  "setconf orbit "*) echo "setconf: $(cat "$3")" >> "$STUB_DIR/loaded.log" ;;\n'
          '  "syncconf orbit "*) cat "$3" >> "$STUB_DIR/loaded.log"; echo --- >> "$STUB_DIR/loaded.log" ;;\n'
          '  "show orbit latest-handshakes") printf "HUBKEY\\t%s\\n" "$(cat "$STUB_DIR/handshake.txt")" ;;\n'
          '  "show orbit endpoints") printf "HUBKEY\\t%s\\n" "$(cat "$STUB_DIR/endpoint.txt")" ;;\n'
          '  "show orbit listen-port") echo 39975 ;;\n'
          'esac\n')

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

    KEY = 'A' * 43 + '='

    STRICT_WG = WG.replace('cat "$3" >> "$STUB_DIR/loaded.log"; echo', r"""cat "$3" > "$STUB_DIR/input"; awk '
      /^$/ || /^\[(Interface|Peer)\]$/ { next }
      NF >= 3 && $2 == "=" && ($1 != "PrivateKey" || (NF == 3 && length($3) == 44 && $3 ~ /=$/)) { next }
      { printf "Key is not the correct length or format: `%s\047\n", $3 > "/dev/stderr"; bad = 1 }
      END { exit bad }' "$STUB_DIR/input" || { echo "Configuration parsing error" >&2; exit 1; }
    cat "$STUB_DIR/input" >> "$STUB_DIR/loaded.log"; echo""")

    INTERFACE = 'ListenPort = 51820\nFwMark = 51820\n'

    HUB_PEER = '[Peer]\nPublicKey = NODEKEY\nAllowedIPs = 10.44.0.9/32\n'

    def sync(self, key, **task):
        """Run the four tasks outside the move that load a configuration composed from the key file, in the order of the
        returned names, with the WireGuard folder in the test folder."""
        converge = {found['name']: found for found in peer_block() + yaml.safe_load((ANSIBLE / 'tasks/node-converge-hub.yml').read_text())}
        add = next(play for play in yaml.safe_load((ANSIBLE / 'operation-node-add.yml').read_text()) if play['name'] == 'Set up the tunnel')
        tasks = [converge['Reconcile live peer state without copying its private key'],
                 converge['Reconcile live hub peers and port without copying its private key'],
                 *yaml.safe_load((ANSIBLE / 'tasks/node-add-hub-peers.yml').read_text()),
                 next(found for found in add['tasks'] if found['name'] == 'Apply the tunnel peers')]
        tasks = [{**{name: value for name, value in found.items() if name != 'when'}, **task} for found in tasks]
        tasks = json.loads(json.dumps(tasks).replace('/etc/wireguard', '@DIR@/wg'))
        variables = {'hub_plan': {'interface': self.INTERFACE},
                     'hub_sync': {'private_key_file': '@DIR@/wg/orbit.key', 'sync_path': '@DIR@/wg/hub.sync', 'interface': self.INTERFACE},
                     'net': {'private_key_file': '@DIR@/wg/orbit.key', 'sync_path': '@DIR@/wg/add.peers', 'sync_body': self.PEERS.format(self.PUBLIC)}}
        files = {'bin/wg': self.STRICT_WG, 'wg/orbit.key': key, 'wg/orbit.peers': self.PEERS.format(self.PUBLIC),
                 'wg/orbit-peers.d/9.conf': self.HUB_PEER}
        code, output, _ = self.run_tasks('sync.yml', variables, files, loaded=tasks)
        return code, output, [found['name'] for found in tasks]
