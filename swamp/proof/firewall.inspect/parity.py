"""Legacy inspect contract against native outputs and the real disposable target."""
import json
import os
import shlex
import subprocess
from pathlib import Path

outputs = json.loads(Path(os.environ['PROOF_OUTPUTS']).read_text())
snapshot = json.loads(outputs['stdout'])
assert snapshot['backend'] == 'active', 'active backend is preserved'
owned = [row for row in snapshot['comparison']['live'] if row['name'] == 'proof-owned']
assert len(owned) == 1 and owned[0]['match'] == 'exact', 'owned rule matches captured intent'
assert snapshot['comparison']['missing'] == [], 'captured allowance is present'
assert any(row['comment'] == 'proof-ssh' for row in snapshot['live']), 'unmanaged SSH allowance is preserved'
remote = '''import hashlib,json,pathlib
before=json.loads(pathlib.Path('/tmp/swamp-proof-firewall-before.json').read_text())
assert all(hashlib.sha256(pathlib.Path(name).read_bytes()).hexdigest()==digest for name,digest in before.items()), 'inspect must not change UFW rules'
'''
command = [*shlex.split(os.environ['PROOF_EXEC']), 'app-dev', '--argv=' + json.dumps(['sudo', '-n', 'python3', '-c', remote])]
subprocess.run(command, check=True, capture_output=True, timeout=15)
