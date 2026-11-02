"""Orbit owns only its marked authorized_keys block (the Nov 1 lockout); keys outside it stay.

Argument: written or refused. written: the block holds exactly the swamp role key, the operator keys that
before_target added are still there in order, and the Operation recorded readiness. refused: broken markers
fail the Operation and the file is byte-for-byte what before_target saved.
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
if expected == 'written':
    check(operation['status'] == 'succeeded' and operation['result'] == {'ssh_access_ready': True}, f'the Operation is {operation["status"]}')
    begin, end = lines.index('# BEGIN Orbit managed keys'), lines.index('# END Orbit managed keys')
    check(lines[begin + 1:end] == operation['input']['intent']['keys'], 'the block holds exactly the swamp role key')
    outside = lines[:begin] + lines[end + 1:]
    check([line for line in outside if 'proof-operator' in line] == operators, 'the operator keys outside the block stay, in order')
    check(any(line.endswith('swamp-proof-gateway') for line in outside), 'the Gateway key outside the block stays')
else:
    check(operation['status'] == 'failed', 'the Operation failed')
    check(target('cat', f'/tmp/proof-authorized-keys-{run}').splitlines() == lines, 'the file is unchanged')
print(f'parity: node.ssh_access_converge {expected}')
