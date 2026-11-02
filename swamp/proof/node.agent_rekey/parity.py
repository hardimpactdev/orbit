"""The Gateway's new agent key reaches the Node through the vault; the task keeps only its verifier.

Argument: rekeyed or failed. before_target saved the key to /tmp/proof-secret-<run>. rekeyed: the key changed,
the agent is active, the Operation succeeded and the key is in no task data. failed: the restart failed, the
Operation failed and the key is in no task data; there is no rollback.
"""
import json
import os
import subprocess
import sys

operation = json.loads(os.environ['PROOF_OPERATION'])
expected = sys.argv[1]
run = os.environ['PROOF_RUN']


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False).stdout.strip()


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message}')


before = target('cat', f'/tmp/proof-secret-{run}')
after = target('cat', '/etc/orbit/agent/secret')
check(len(before) == 64, 'before_target saved the old key')
if expected == 'rekeyed':
    check(operation['status'] == 'succeeded' and operation['result'] == {'rekeyed': True, 'restarted': True}, f'the Operation is {operation["status"]}')
    check(len(after) == 64 and after != before, 'the key is new')
    check(target('systemctl', 'is-active', 'orbit-agent') == 'active', 'the agent is active')
else:
    check(operation['status'] == 'failed', 'the Operation failed')
check(after not in json.dumps(operation), 'the key never reaches the task')
print(f'parity: node.agent_rekey {expected}')
