"""Network convergence and no-write rerun contract on a real peer/hub pair."""
import json
import os
import subprocess
import sys
from pathlib import Path

operation = json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text())
outputs = Path(os.environ['PROOF_OUTPUTS']).read_text()
mode = sys.argv[1]
state = Path('/root/proof-network/converge-baseline.json')


def on(node, *argv):
    return subprocess.run(['incus', 'exec', node, '--', *argv], capture_output=True, text=True, check=True).stdout.strip()


def check(condition, message):
    if not condition:
        raise SystemExit('parity: ' + message)


def snapshot():
    result = {}
    for node in ('peer', 'target'):
        files = on(node, 'sh', '-c', 'find /etc/wireguard -type f -printf "%p %i %T@ %m %u\\n" | sort')
        unit = on(node, 'systemctl', 'show', 'wg-quick@orbit', '-p', 'ActiveEnterTimestampMonotonic', '-p', 'InvocationID')
        result[node] = {'files': files, 'unit': unit, 'peers': on(node, 'wg', 'show', 'orbit', 'allowed-ips')}
    return result


check(operation['status'] == 'succeeded' and operation['result'].get('footprint_proven') is True, 'the full converge succeeded')
network = next(a for a in operation['result']['artifacts'] if a['name'] == 'network')
current = snapshot()
if mode == 'unchanged':
    check(network['status'] == 'unchanged', 'the network apply was skipped')
    check(current == json.loads(state.read_text()), 'no WireGuard file, inode, mtime, service invocation or peer changed')
else:
    check(network['status'] == 'applied', 'the network part applied')
    if mode == 'hub':
        wanted = set(operation['input']['_converge']['network']['apply']['peer_keys'])
        check(len(wanted) >= 2, 'the hub fixture has multiple recorded peers')
        check(set(on('target', 'wg', 'show', 'orbit', 'peers').split()) == wanted, 'the full recorded hub peer list is live')
        check(on('target', 'sh', '-c', 'test ! -e /etc/wireguard/orbit-peers.d/999999.conf && echo gone') == 'gone', 'the stale hub peer file was pruned')
    else:
        hub = on('target', 'wg', 'show', 'orbit', 'public-key')
        peer = on('peer', 'wg', 'show', 'orbit', 'public-key')
        check(hub in on('peer', 'wg', 'show', 'orbit', 'peers').split(), 'the peer uses the recorded hub')
        check(peer in on('target', 'wg', 'show', 'orbit', 'peers').split(), 'the hub includes the peer')
        check('10.231.0.0/24' in current['peer']['peers'], 'live allowed IP drift is repaired')
    state.write_text(json.dumps(current))
for node in ('peer', 'target'):
    private = on(node, 'wg', 'show', 'orbit', 'private-key')
    check(private not in outputs and private not in json.dumps(operation), 'private key stayed on ' + node)
    check(on(node, 'systemctl', 'is-active', 'wg-quick@orbit') == 'active', node + ' tunnel stays active')
print('parity: node.converge network ' + mode + '; public state, private-key absence and no-write contract passed')
