"""The real retained timer files and loaded timer disappear after native removal."""
import json
import os
import subprocess

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
assert operation['kind'] == 'schedule.destroy' and operation['status'] == 'succeeded', operation
assert operation['result'] == {'ready': True}, operation['result']
schedule = operation['input']['id']
for path in ['/etc/systemd/system/orbit-schedule-' + schedule + suffix for suffix in ['.service', '.timer']] + ['/etc/orbit/schedules/' + schedule + suffix for suffix in ['.sh', '.complete']]:
    reply = subprocess.run(['incus', 'exec', 'target', '--', 'test', '!', '-e', path], capture_output=True)
    assert reply.returncode == 0, path
reply = subprocess.run(['incus', 'exec', 'target', '--', 'systemctl', 'show', '--property=LoadState', '--value', 'orbit-schedule-' + schedule + '.timer'], text=True, capture_output=True)
assert reply.stdout.strip() == 'not-found', (reply.stdout, reply.stderr)
print('Four real managed files are absent and the retained timer is no longer loaded after native removal.')
