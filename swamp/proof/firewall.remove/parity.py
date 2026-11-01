"""Legacy firewall:remove contract: the removed tuple is gone from UFW and other rules stay."""
import os
import subprocess

status = subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', 'ufw', 'status'],
                        check=True, capture_output=True, text=True, timeout=30).stdout
print(status)
# rule-gone: no 8443/tcp row from 192.0.2.2 remains.
assert not [line for line in status.splitlines() if line.split()[:3] == ['8443/tcp', 'ALLOW', '192.0.2.2']], '8443/tcp rule is gone'
# unrelated-kept: the unmanaged SSH allowance survives.
assert 'proof-ssh' in status, 'unmanaged SSH allowance is kept'
# backend-active: UFW stays enabled.
assert status.startswith('Status: active'), 'UFW is active'
print('parity: rule-gone unrelated-kept backend-active')
