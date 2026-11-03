"""Real timer files and disabled service state for every captured member."""
import json
import os
import subprocess

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
assert operation['status'] == 'succeeded' and operation['result'] == {'ready': True}, operation
members = operation['input']['members']
assert len(members) == 2
for member in members:
    unit = 'orbit-schedule-' + member['id']
    for suffix in ['.service', '.timer']:
        path = '/etc/systemd/system/' + unit + suffix
        actual = subprocess.check_output(['incus', 'exec', 'target', '--', 'stat', '-c', '%a:%U:%G', path], text=True).strip()
        assert actual == '644:root:root', (path, actual)
    for action, expected in [('is-enabled', 'disabled'), ('is-active', 'inactive')]:
        reply = subprocess.run(['incus', 'exec', 'target', '--', 'systemctl', action, unit + '.timer'], text=True, capture_output=True)
        assert reply.stdout.strip() == expected, (action, reply.stdout, reply.stderr)
print('Both real service/timer pairs exist as root:root 0644 and stay disabled and inactive. The native parent projected only readiness from its end check.')
