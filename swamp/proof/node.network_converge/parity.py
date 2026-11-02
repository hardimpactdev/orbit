"""Network converge without shipped scripts (rule 22): every WireGuard file comes from the Gateway, and no private key
leaves its Node.

The peer made its own key and runs the tunnel with it; the hub kept its key and admitted the peer; the two handshake.
The peer's orbit.conf reads its key file and holds no PrivateKey; the hub gets only this peer's file, the peer file carries the split-DNS hook, the files are
0600 root, and no private key reached native history or the Operation. Never prints a private key."""
import json
import os
import subprocess
import sys
from pathlib import Path

operation = json.loads(os.environ['PROOF_OPERATION'])
outputs = Path(os.environ['PROOF_OUTPUTS']).read_text()
result = operation['result'] or {}
hub_public = Path('/root/proof-network/hub.public').read_text().strip()


def on(node, *argv):
    return subprocess.run(['incus', 'exec', node, '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


peer_key = on('peer', 'wg', 'show', 'orbit', 'public-key').stdout.strip()
check(result.get('network_proven') is True and result.get('public_key') == peer_key, 'the result names the live peer key')
check(on('target', 'wg', 'show', 'orbit', 'public-key').stdout.strip() == hub_public, 'the hub kept its key')
check(peer_key in on('target', 'wg', 'show', 'orbit', 'peers').stdout.split(), 'the hub admits the peer')
# PersistentKeepalive starts the handshake; one bounded wait on the peer sees it land.
handshake = on('peer', 'timeout', '45', 'sh', '-c', 'until wg show orbit latest-handshakes | awk -v k="$1" \'$1 == k && $2 > 0 {f=1} END {exit !f}\'; do sleep 2; done', 'sh', hub_public)
check(handshake.returncode == 0, 'the peer handshakes with the hub')
running = on('peer', 'wg', 'show', 'orbit', 'private-key').stdout.strip()
check(running not in ('', '(none)') and running == on('peer', 'cat', '/etc/wireguard/orbit.key').stdout.strip(), 'the peer key file holds the running key')
for node in ('peer', 'target'):
    secret = on(node, 'wg', 'show', 'orbit', 'private-key').stdout.strip()
    check(secret not in outputs and secret not in json.dumps(operation), f'{node}: the private key stayed on the Node')
config = on('peer', 'cat', '/etc/wireguard/orbit.conf').stdout
check('PrivateKey' not in config and 'PostUp = wg set %i private-key /etc/wireguard/orbit.key' in config, 'the peer orbit.conf reads the key file')
hub_file = operation['input']['intent']['hub']['file']
check(on('target', 'cat', hub_file).stdout.count('PublicKey = ' + peer_key) == 1, 'the hub has exactly this peer file')
for node, path in (('peer', '/etc/wireguard/orbit.key'), ('peer', '/etc/wireguard/orbit.conf'), ('peer', '/etc/wireguard/orbit-peers.conf'), ('target', hub_file)):
    check(on(node, 'stat', '-c', '%a %U', path).stdout.strip() == '600 root', f'{node}: {path} is 0600 root')
check('PostUp = resolvectl dns %i ' in on('peer', 'cat', '/etc/wireguard/orbit.conf').stdout, 'the peer file keeps the split-DNS hook')
check(on('peer', 'systemctl', 'is-enabled', '--quiet', 'wg-quick@orbit').returncode == 0, 'the peer tunnel starts at boot')
print('parity: peer key made on the peer, hub kept its key, handshake, no private key in any file, history or Operation')
