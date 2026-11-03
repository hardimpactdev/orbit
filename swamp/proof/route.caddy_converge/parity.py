"""Check the actual Caddy file and service after native projection."""
import hashlib
import json
import os
import subprocess

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
assert operation['kind'] == 'route.caddy_converge' and operation['status'] == 'succeeded', operation
result = operation['result']
assert result['ready'] is True and result['result'] == 'published', result
content = subprocess.check_output(['incus', 'exec', 'target', '--', 'cat', '/etc/caddy/Caddyfile'])
assert hashlib.sha256(content).hexdigest()[:32] == result['version'], result
actual = subprocess.check_output(['incus', 'exec', 'target', '--', 'stat', '-c', '%a:%U:%G:%F', '/etc/caddy/Caddyfile'], text=True).strip()
assert actual == '640:root:caddy:regular file', actual
subprocess.run(['incus', 'exec', 'target', '--', 'systemctl', 'is-active', '--quiet', 'caddy'], check=True, timeout=30)
subprocess.run(['incus', 'exec', 'target', '--', 'runuser', '-u', 'caddy', '--', 'caddy', 'validate', '--config', '/etc/caddy/Caddyfile', '--adapter', 'caddyfile'], check=True, timeout=60)
print('Real Caddy configuration matches the captured version, is root:caddy 0640, validates and has an active service. Native end check and shared projection passed.')
