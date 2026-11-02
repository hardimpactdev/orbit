"""Legacy agent.install and agent.configure contract on the target (F9), the role parts and the projected footprint.

Argument: applied or refused. applied: the agent runs the pinned binary with its files, the key the Gateway
recorded is unchanged, every admitted role part applied, Caddy meets its floor, the Caddyfile candidate is gone,
the tmpfiles rule holds its content, and the Operation proved the footprint. refused: a key that does not match the recorded
verifier fails the Operation, and the key is not rewritten.
"""
import hashlib
import json
import os
import subprocess
import sys

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
expected = sys.argv[1]
intent = operation['input']['_converge']


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False).stdout.strip()


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message}')


secret = target('cat', '/etc/orbit/agent/secret')
if expected == 'applied':
    check(operation['status'] == 'succeeded', f'the Operation is {operation["status"]} ({operation.get("error_code")})')
    check(operation['result'].get('footprint_proven') is True, 'the footprint is proven')
    check(target('systemctl', 'is-active', 'orbit-agent') == 'active', 'orbit-agent is active')
    check(target('sha256sum', '/usr/local/bin/orbit-agent').split()[:1] == [intent['agent_sha256']], 'the binary is the pin')
    check(hashlib.sha256(secret.encode()).hexdigest() == intent['verifier_hash'], 'the recorded key is kept')
    check('ca.pem' in target('ls', '/etc/orbit/agent'), 'the CA file is written')
    roles = intent['roles']
    applied = {a['name'] for a in operation['result']['artifacts'] if a['status'] == 'applied'}
    check({a['name'] for a in intent['artifacts'] if a['required']} <= applied, f'every required part applied: {sorted(applied)}')
    if roles['caddy_package'] is not None:
        version = target('dpkg-query', '-W', '-f=${Version}', 'caddy')
        floor = subprocess.run(['dpkg', '--compare-versions', version, 'ge', roles['caddy_package']['minimum_version']], check=False).returncode
        check(floor == 0, f'Caddy {version} meets the floor')
    if roles['caddy'] is not None:
        check(target('sh', '-c', 'ls /etc/caddy/.orbit-converge-* 2>/dev/null') == '', 'the Caddyfile candidate is removed')
    if roles['tmpfiles'] is not None:
        check(target('cat', '/etc/tmpfiles.d/orbit.conf') == roles['tmpfiles'].strip(), 'the tmpfiles rule holds its content')
else:
    check(operation['status'] == 'failed', 'the Operation failed')
    check(secret == 'proof-mismatched-key', 'the mismatched key is not rewritten')
    check(operation['result'].get('failed_artifact') == 'agent', 'the agent part failed')
print(f'parity: node.converge {expected}')
