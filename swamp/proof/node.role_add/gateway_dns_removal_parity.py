"""Removal mirrors the native Gateway DNS unit introduced by role add."""
import json
import os
import subprocess
import sys

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
if operation['status'] != 'succeeded':
    sys.exit('parity: native Gateway role removal failed')
for path in ('/etc/systemd/system/orbit-gateway-dns.service',
             '/etc/systemd/system/wg-quick@orbit.service.d/orbit-gateway-dns.conf'):
    result = subprocess.run(['incus', 'exec', 'metrics', '--', 'test', '-e', path], check=False)
    if result.returncode != 1:
        sys.exit('parity: Gateway DNS resource remains')
print('parity: Gateway DNS unit and retired drop-in are absent')
