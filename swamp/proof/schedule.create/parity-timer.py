"""Check the real retained Node timer after the native resource checks."""
import json
import os
import subprocess

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
assert operation['kind'] == 'schedule.create' and operation['status'] == 'succeeded', operation
assert operation['result'] == {'ready': True}, operation['result']
unit = 'orbit-schedule-' + operation['input']['id']
for suffix in ['.service', '.timer']:
    path = '/etc/systemd/system/' + unit + suffix
    actual = subprocess.check_output(['incus', 'exec', 'target', '--', 'stat', '-c', '%a:%U:%G', path], text=True).strip()
    assert actual == '644:root:root', (path, actual)
for action, expected in [('is-enabled', 'disabled'), ('is-active', 'inactive')]:
    result = subprocess.run(['incus', 'exec', 'target', '--', 'systemctl', action, unit + '.timer'], text=True, capture_output=True)
    assert result.stdout.strip() == expected, (action, result.stdout, result.stderr)
for suffix in ['.sh', '.complete']:
    result = subprocess.run(['incus', 'exec', 'target', '--', 'test', '!', '-e', '/etc/orbit/schedules/' + operation['input']['id'] + suffix])
    assert result.returncode == 0, suffix
print('Real unit files are root:root 0644; the captured timer is disabled and inactive; obsolete script/completion files are absent. Native resource checks confirmed readiness.')
