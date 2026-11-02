"""Legacy agent.install and agent.configure contract on the target (F9) and the projected footprint.

Argument: applied or refused. applied: the agent runs the pinned binary with its files, the key the Gateway
recorded is unchanged, and the Operation proved the footprint. refused: a key that does not match the recorded
verifier fails the Operation, and the key is not rewritten.
"""
import hashlib
import json
import os
import subprocess
import sys

operation = json.loads(os.environ['PROOF_OPERATION'])
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
else:
    check(operation['status'] == 'failed', 'the Operation failed')
    check(secret == 'proof-mismatched-key', 'the mismatched key is not rewritten')
    check(operation['result'].get('failed_artifact') == 'agent', 'the agent part failed')
print(f'parity: node.converge {expected}')
