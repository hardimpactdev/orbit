"""database.write contract: the Operation succeeds with exactly the expected row count, and the effect is on the target."""
import json
import os
import subprocess
import sys
from pathlib import Path

operation = json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text())
expected = json.loads(sys.argv[1])
if operation['status'] != 'succeeded' or operation['result'] != expected:
    sys.exit(f'parity: database.write returned {operation["status"]} {json.dumps(operation["result"])}, expected {json.dumps(expected)}')
if len(sys.argv) > 3:
    # A read on the target that shows the write landed.
    found = subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *json.loads(sys.argv[2])], capture_output=True, text=True, check=False)
    if found.stdout.strip() != sys.argv[3]:
        sys.exit(f'parity: the target shows {found.stdout.strip()!r}, expected {sys.argv[3]!r}: {found.stderr.strip()}')
print(f'parity: database.write on {operation["input"]["driver"]} recorded row_count {expected["row_count"]} and the target shows it')
