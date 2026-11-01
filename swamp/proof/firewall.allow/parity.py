"""Legacy firewall:allow contract: the admitted tuple is live in UFW exactly once."""
import os
import subprocess

status = subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', 'ufw', 'status'],
                        check=True, capture_output=True, text=True, timeout=30).stdout
print(status)
rows = [line.split() for line in status.splitlines() if line.split()[:3] == ['443/tcp', 'ALLOW', '192.0.2.1']]
# rule-present: the admitted tuple is live once, IPv4 only for an IPv4 source.
assert len(rows) == 1, 'one live 443/tcp ALLOW 192.0.2.1 row'
# backend-active: UFW stays enabled.
assert status.startswith('Status: active'), 'UFW is active'
# unrelated-kept: the unmanaged SSH allowance survives.
assert 'proof-ssh' in status, 'unmanaged SSH allowance is kept'
print('parity: rule-present backend-active unrelated-kept')
