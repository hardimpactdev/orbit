"""Check the terminal recipe and the public files with real Node commands."""
import json
import os
import subprocess

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
assert operation['status'] == 'succeeded', operation.get('error_code')
result = operation['result']
assert result['footprint']['footprint_proven'] is True
for path, mode in [('/etc/orbit/agent/config.toml', '644'), ('/etc/orbit/agent/ca.pem', '644'), ('/etc/tmpfiles.d/orbit.conf', '644')]:
    actual = subprocess.check_output(['incus', 'exec', os.environ['PROOF_TARGET'], '--', 'stat', '-c', '%a', path], text=True).strip()
    assert actual == mode, (path, actual)
assert subprocess.check_output(['incus', 'exec', os.environ['PROOF_TARGET'], '--', 'systemctl', 'is-active', 'orbit-agent'], text=True).strip() == 'active'
print('parity: recipe, observer, and role file footprint proven')
