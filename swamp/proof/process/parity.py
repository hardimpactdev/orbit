"""Legacy Process contract on the target (F9): the unit's active and boot state, and the projected result.

Arguments: running, stopped, removed or failed, and for running an optional text the unit's ExecStart must contain.
Restart also needs a new main PID; before_target wrote the old one to /tmp/proof-main-pid-<PROOF_RUN>.
"""
import json
import os
import subprocess
import sys

operation = json.loads(os.environ['PROOF_OPERATION'])
expected, *command = sys.argv[1:]
unit = operation['input']['name']


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False).stdout.strip()


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message} ({unit})')


if operation['input']['runtime'] == 'docker':
    # A container has no boot policy; its state is Docker's.
    state = target('docker', 'container', 'inspect', '--format', '{{.State.Status}}', unit)
    running = expected == 'running'
    check(state == ('running' if running else 'exited'), f'the container is {state}')
    check(operation['result'] == {'ready': True, 'state': state}, 'the projected state is the observed state')
    print(f'parity: {operation["kind"]} leaves container {unit} {expected}')
    sys.exit(0)
active = target('systemctl', 'is-active', unit)
enabled = target('systemctl', 'is-enabled', unit)
present = target('sh', '-c', f'test -e /etc/systemd/system/{unit} && echo present') == 'present'
if expected in ('removed', 'failed'):
    check(not present, 'the unit file is gone')
    check(active in ('inactive', 'unknown'), f'is-active is {active}')
    if expected == 'removed':
        check(operation['result'] == {'ready': True}, 'destroy projects ready only')
    else:
        check(operation['status'] == 'failed' and operation['error_code'], 'the Operation failed with an error code')
else:
    running = expected == 'running'
    check(active == ('active' if running else 'inactive'), f'is-active is {active}')
    check(enabled == ('enabled' if running and not operation['input']['on_demand'] else 'disabled'), f'is-enabled is {enabled}')
    check(operation['result'] == {'ready': True, 'state': active}, 'the projected state is the observed state')
    for text in command:
        check(text in target('systemctl', 'show', '--property=ExecStart', '--value', unit), f'ExecStart contains {text}')
    if operation['kind'] == 'process.restart':
        before = target('cat', '/tmp/proof-main-pid-' + os.environ['PROOF_RUN'])
        after = target('systemctl', 'show', '--property=MainPID', '--value', unit)
        check(before not in ('', '0') and after not in ('', '0', before), f'restart replaced main PID {before} with {after}')
print(f'parity: {operation["kind"]} leaves {unit} {expected}')
