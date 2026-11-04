"""The new Node is active and its real WireGuard peer and observer match admission."""
import hashlib
import json
import os
import subprocess
import sys

with open(os.environ['PROOF_OPERATION_FILE']) as stream:
    operation = json.load(stream)


def run(*argv):
    return subprocess.run(['incus', 'exec', 'metrics', '--', *argv], capture_output=True, text=True, check=True, timeout=30).stdout.strip()


def check(ok, message):
    if not ok:
        sys.exit('parity: ' + message)


check(operation['status'] == 'succeeded', 'Node enrollment succeeded')
check(operation['result'].get('enrolled') is True, 'native end check was projected')
intent = operation['input']['intent']
check(intent['agent']['environment_group'] == 'orbit-env', 'Gateway stages the observer group for the later consumer')
check(run('systemctl', 'is-active', 'orbit-agent.service') == 'active', 'observer is active')
check(run('stat', '-c', '%a', '/etc/orbit/agent/secret') == '600', 'observer secret is private')
check(hashlib.sha256(run('cat', '/etc/orbit/agent/secret').encode()).hexdigest()
      == operation['input']['_node_enrollment']['agent_secret_hash'], 'observer secret matches its recorded verifier')
check(run('sha256sum', '/usr/local/bin/orbit-agent').split()[0] == intent['agent']['sha256'], 'agent binary matches its pin')
handshakes = run('wg', 'show', 'orbit', 'latest-handshakes').splitlines()
check(any(len(line.split()) == 2 and int(line.split()[1]) > 0 for line in handshakes), 'real tunnel handshake succeeded')
catalog = subprocess.run(['incus', 'exec', 'target', '--', 'cat', '/var/lib/orbit/private-dns/catalog.json'],
                         capture_output=True, text=True, check=True, timeout=30).stdout
check(json.loads(catalog)['requesters'].get(intent['address']) == operation['target_id'], 'DNS accepts the enrolled Node identity')
print('parity: node.add real WireGuard enrollment and DNS publication')
