"""A new agent key is made on the Node and only its verifier leaves it; a failed restart restores the old key.

Argument: rekeyed or restored. before_target saved the key to /tmp/proof-secret-<run>. rekeyed: the key changed,
secret.previous is gone, the agent is active and the Operation succeeded. restored: the Operation failed and the
key is the saved one again.
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
    check(target('sh', '-c', 'test -e /etc/orbit/agent/secret.previous || echo gone') == 'gone', 'secret.previous is removed')
    check(target('systemctl', 'is-active', 'orbit-agent') == 'active', 'the agent is active')
    check(after not in json.dumps(operation), 'the key never reaches the task')
else:
    check(operation['status'] == 'failed', 'the Operation failed')
    check(after == before, 'the old key is back')
print(f'parity: node.agent_rekey {expected}')
