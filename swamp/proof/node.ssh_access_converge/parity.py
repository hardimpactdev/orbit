"""Orbit only adds its own keys (the Nov 1 lockout); every other line of authorized_keys stays.

Argument: written. The swamp role key is present, the operator keys that before_target added are still there
in order, the Gateway key stays, and the Operation recorded readiness.
"""
import json
import os
import subprocess
import sys

operation = json.loads(os.environ['PROOF_OPERATION'])
expected = sys.argv[1]
run = os.environ['PROOF_RUN']


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False).stdout


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message}')


lines = target('cat', '/home/orbit/.ssh/authorized_keys').splitlines()
operators = [f'ssh-ed25519 AAAAproof{n} proof-operator-{n}-{run}' for n in (1, 2)]
check(expected == 'written', 'the only parity case is written')
check(operation['status'] == 'succeeded' and operation['result'] == {'ssh_access_ready': True}, f'the Operation is {operation["status"]}')
for key in operation['input']['intent']['keys']:
    check(sum(1 for line in lines if line.split()[:2] == key.split()[:2]) >= 1, 'the swamp role key is present')
check([line for line in lines if 'proof-operator' in line] == operators, 'the operator keys stay, in order')
check(any(line.endswith('swamp-proof-gateway') for line in lines), 'the Gateway key stays')
print(f'parity: node.ssh_access_converge {expected}')
