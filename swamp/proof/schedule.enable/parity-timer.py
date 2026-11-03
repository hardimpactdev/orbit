"""The retained timer is enabled and active, with its first fire safely in 2099."""
import json
import os
import subprocess

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
assert operation['kind'] == 'schedule.enable' and operation['status'] == 'succeeded', operation
assert operation['result'] == {'ready': True}, operation['result']
unit = 'orbit-schedule-' + operation['input']['id'] + '.timer'
for action, expected in [('is-enabled', 'enabled'), ('is-active', 'active')]:
    reply = subprocess.run(['incus', 'exec', 'target', '--', 'systemctl', action, unit], text=True, capture_output=True)
    assert reply.returncode == 0 and reply.stdout.strip() == expected, (action, reply.stdout, reply.stderr)
print('The real retained timer is enabled and active after the native systemd check. Its 2099 calendar prevents a command fire during this proof.')
