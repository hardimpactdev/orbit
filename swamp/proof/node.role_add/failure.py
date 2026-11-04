"""An execution refusal retains its failed role and removes all staged private input."""
import json
import os
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from common import gateway

operation = json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text())
assert operation['status'] == 'failed', operation['status']
assert operation['error_code'] == 'role.os_unsupported', operation['error_code']
steps = json.loads(Path(os.environ['PROOF_OUTPUTS']).read_text())
assert 'Write WebSocket input' in json.dumps(steps), 'the private input was staged'
assert 'websocket_environment' in operation['input']['execution_secrets'], 'admission included the private credential'
assert not Path('/tmp/orbit-ansible-' + operation['swamp_run_id']).exists(), 'private run input remains'
roles = gateway('GET', 'nodes/' + str(operation['target_id']) + '/roles')['response']['data']
role = next(item for item in roles if item['role'] == 'websocket')
assert role['status'] == 'failed', role['status']
print('parity: unsupported OS refused after secret staging; failed role retained; all private input removed')
