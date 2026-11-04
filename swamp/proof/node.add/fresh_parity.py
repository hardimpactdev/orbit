"""The stock VM gained the exact admitted worker key, observer, tunnel and DNS identity."""
import json
import os
import runpy
import subprocess
from pathlib import Path

state_path = Path('/home/orbit/.orbit/swamp-proof/node-add-stock.json')
state = json.loads(state_path.read_text())
os.environ['PROOF_ENROLLMENT_CANDIDATE'] = state['candidate']
runpy.run_path(str(Path(__file__).with_name('parity.py')))
with open(os.environ['PROOF_OPERATION_FILE']) as stream:
    operation = json.load(stream)
intent = operation['input']['intent']
assert intent['bootstrap']['user'] == 'root'
assert intent['bootstrap']['host'] == state['address']
assert intent['bootstrap']['fingerprint'] == state['fingerprint']
key = intent['identity']['public_key'].split()[:2]
for path in ('/root/.ssh/authorized_keys', '/home/orbit/.ssh/authorized_keys'):
    keys = subprocess.run(['incus', 'exec', state['candidate'], '--', 'cat', path], capture_output=True,
                          text=True, check=True, timeout=30).stdout.splitlines()
    assert sum(line.split()[:2] == key for line in keys) == 1, path
state['node_id'] = operation['target_id']
state_path.write_text(json.dumps(state, indent=2) + '\n')
print('parity: stock Ubuntu 26.04, root bootstrap only, no prepared account, packages or pins')
print('pre-enrollment: ' + json.dumps({key: value for key, value in state.items() if key != 'packages'}))
