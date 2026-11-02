"""Legacy removal contract: owned runtime and records go, user data and unrelated access stay."""
import json
import os
import subprocess
import sys

operation = json.loads(os.environ['PROOF_OPERATION'])
mode = sys.argv[1]
# peer is intentionally roleless in minimal; this fixture gives it only the role under test.
node = os.environ['PROOF_TARGET'] if mode == 'role' else 'peer'


def run(*argv):
    return subprocess.run(['incus', 'exec', node, '--', *argv], capture_output=True, text=True, check=False)


def check(ok, message):
    if not ok:
        sys.exit('parity: ' + message)


check(operation['status'] == 'succeeded', 'the native removal succeeded')
check(run('cat', '/srv/proof-retained/user-data').stdout.strip() == 'keep', 'user data remains')
if mode == 'role':
    check(run('test', '-e', '/etc/systemd/system/orbit-websocket.service').returncode == 1, 'the retired unit is absent')
    check(run('systemctl', 'is-active', '--quiet', 'orbit-websocket.service').returncode in (3, 4), 'the role is stopped')
    check(run('test', '-e', '/etc/caddy/orbit-websocket-cert-current').returncode == 1, 'the role certificate is absent')
    check('orbit:role:websocket' not in run('ufw', 'status').stdout, 'the role firewall rule is absent')
elif mode == 'online':
    check(operation['result'].get('removed') is True and operation['result'].get('offline') is False, 'online removal is projected')
    check(run('test', '-e', '/usr/local/bin/orbit-agent').returncode == 1, 'observer binary is absent')
    check(run('test', '-e', '/etc/orbit/agent').returncode == 1, 'observer config is absent')
    keys = run('cat', '/home/orbit/.ssh/authorized_keys').stdout
    check(keys.strip() == 'ssh-ed25519 AAAAProofUnrelated operator', 'only the unrelated SSH key remains')
else:
    check(operation['result'].get('removed') is True and operation['result'].get('offline') is True, 'offline consent is projected')
    check(run('test', '-f', '/etc/orbit/agent/secret').returncode == 0, 'offline target configuration remains')
    check(run('ufw', '--force', 'delete', 'deny', 'proto', 'tcp', 'from', '10.231.0.1', 'to', 'any', 'port', '22').returncode == 0,
          'the fixture SSH block is removed after the offline checks')
print('parity: removal ' + mode)
