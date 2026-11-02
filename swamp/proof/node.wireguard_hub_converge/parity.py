"""Hub converge after Nick's decision (2026-11-02): the hub private key never leaves the Node.

The hub keeps the same key (its public key is unchanged and the key file holds the running key), a real peer's
session survives the load (it still handshakes and traffic keeps flowing), the hub serves exactly the admitted peers
from one file each, with the stale one gone, the service is active and enabled, and the key reached neither native history nor the
Operation. Never prints a private key."""
import json
import os
import subprocess
import sys
import time
from pathlib import Path

operation = json.loads(os.environ['PROOF_OPERATION'])
outputs = Path(os.environ['PROOF_OUTPUTS']).read_text()
keys = set(operation['input']['intent']['peer_keys'])
state = Path('/root/proof-hub')
STALE = 'AwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwM='


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


def peer_field(field):
    rows = dict(line.split('\t')[:2] for line in target('wg', 'show', 'orbit', field).stdout.splitlines() if '\t' in line)
    return int(rows.get((state / 'peer.public').read_text().strip(), '0'))


config = target('cat', '/etc/wireguard/orbit.conf').stdout
running = target('wg', 'show', 'orbit', 'private-key').stdout.strip()
stored = target('cat', '/etc/wireguard/orbit.key').stdout.strip()
check(operation['result'].get('projected') is True, 'the task result is projected: true')
check(target('wg', 'show', 'orbit', 'public-key').stdout.strip() == (state / 'hub.public').read_text().strip(), 'the hub kept its key')
check(running != '(none)' and running == stored, 'the key file holds the running key')
check(time.time() - peer_field('latest-handshakes') < 180, 'the real peer still has a live handshake')
check(peer_field('transfer') > int((state / 'rx.before').read_text().strip() or 0), 'traffic from the real peer keeps flowing')
check(set(target('wg', 'show', 'orbit', 'peers').stdout.split()) == keys, 'live peers equal the intent: the stale one is gone')
check(STALE not in config and '[Peer]' not in config, 'orbit.conf holds no peer, and the stale one is gone')
check('PrivateKey' not in config and 'PostUp = wg set %i private-key /etc/wireguard/orbit.key' in config, 'orbit.conf reads the key file')
check('orbit-peers.d/*.conf' in config, 'orbit.conf loads the peer files on start')
files = {item['file'] for item in operation['input']['intent']['peers']}
listed = set(target('sh', '-c', 'ls /etc/wireguard/orbit-peers.d/*.conf').stdout.split())
check(files <= listed and all(target('cat', path).stdout.count('[Peer]') == 1 for path in files), 'one peer file per admitted Node')
check(not any(STALE in target('cat', path).stdout for path in listed), 'no peer file names the stale peer')
for path in ('/etc/wireguard/orbit.key', '/etc/wireguard/orbit.conf', *sorted(files)):
    check(target('stat', '-c', '%a %U', path).stdout.strip() == '600 root', f'{path} is 0600 root')
check(target('systemctl', 'is-active', '--quiet', 'wg-quick@orbit').returncode == 0, 'the hub service is active')
check(target('systemctl', 'is-enabled', '--quiet', 'wg-quick@orbit').returncode == 0, 'the hub service is enabled')
check(running not in outputs and running not in json.dumps(operation), 'the private key stayed on the hub')
print('parity: key unchanged, real peer session survives, peers, files, service and secrecy hold')
