"""database:update contract: a new Valkey index below the live database count is applied, and the run left nothing on
the Node (the check only reads)."""
import json
import os
import subprocess
import sys

operation = json.loads(os.environ['PROOF_OPERATION'])


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


check(operation['status'] == 'succeeded' and operation['result'] == {'backend': 'active'}, 'the projected result is backend active')
left = subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', 'sh', '-c', 'ls /run/orbit-database-* 2>/dev/null || true'],
                      capture_output=True, text=True, check=False).stdout.strip()
check(left == '', f'no database file is left under /run, found {left}')
print('parity: database.update applied index 3 after the live count check')
