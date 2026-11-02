"""Legacy hub contract (F9): the hub serves exactly the admitted peers under its own unchanged key, the service is
active and enabled, no rollback copy is left, and no private key reached native history or the task."""
import json
import os
import subprocess
import sys
from pathlib import Path

operation = json.loads(os.environ['PROOF_OPERATION'])
outputs = Path(os.environ['PROOF_OUTPUTS']).read_text()
keys = set(operation['input']['intent']['peer_keys'])


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


config = target('cat', '/etc/wireguard/orbit.conf').stdout
key = target('wg', 'show', 'orbit', 'private-key').stdout.strip()
check(operation['result'] == {'projected': True}, 'the task result is projected: true')
check(len(keys) == 2, 'the fixture peers are in the intent')
check(set(target('wg', 'show', 'orbit', 'peers').stdout.split()) == keys, 'live peers equal the intent')
check(all(key in config for key in keys), 'the saved config holds the peers')
check('AwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwM=' not in config, 'the stale peer is gone from the saved config')
check(config.count('[Interface]') == 1 and f'PrivateKey = {key}' in config, 'the hub kept its own key')
check(target('systemctl', 'is-active', '--quiet', 'wg-quick@orbit').returncode == 0, 'the hub service is active')
check(target('systemctl', 'is-enabled', '--quiet', 'wg-quick@orbit').returncode == 0, 'the hub service is enabled')
check(key not in outputs and key not in json.dumps(operation), 'the private key stayed on the hub')
print('parity: peers, key, service and secrecy match the legacy hub contract')
