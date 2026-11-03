"""The control worker publishes exactly the admitted public pin snapshot."""
import hashlib
import json
import os
from pathlib import Path
import stat

with open(os.environ['PROOF_OPERATION_FILE']) as source:
    operation = json.load(source)
intent = operation['input']['intent']
assert operation['kind'] == 'node.worker_known_hosts'
assert operation['status'] == 'succeeded'
assert operation['target_id'] == intent['control_node_id'] == 209
assert operation['result'] == {'known_hosts_ready': True, 'digest': intent['digest']}
path = Path('/home/orbit/.ssh/known_hosts')
content = path.read_bytes()
assert content == intent['known_hosts_content'].encode()
assert hashlib.sha256(content).hexdigest() == intent['digest']
assert stat.S_IMODE(path.stat().st_mode) == 0o600
assert path.owner() == 'orbit'
assert len(content.splitlines()) >= 3
assert all(b' ssh-ed25519 ' in line for line in content.splitlines())
print('parity: exact recorded public pins, digest, mode 0600 and worker ownership')
