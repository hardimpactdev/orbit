"""The root-only candidate gained the exact admitted worker key in both accounts."""
import json
import os
import subprocess

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
intent = operation['input']['intent']
assert intent['bootstrap']['user'] == 'root'
key = intent['identity']['public_key'].split()[:2]
for path in ('/root/.ssh/authorized_keys', '/home/orbit/.ssh/authorized_keys'):
    keys = subprocess.run(['incus', 'exec', 'metrics', '--', 'cat', path], capture_output=True,
                          text=True, check=True, timeout=30).stdout.splitlines()
    assert sum(line.split()[:2] == key for line in keys) == 1, path
print('parity: root bootstrap installed the admitted worker key once in both accounts')
