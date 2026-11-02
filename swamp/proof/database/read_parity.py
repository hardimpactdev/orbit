"""database.read contract: the Operation succeeds with exactly the expected shaped result, from the client on the host."""
import json
import os
import sys

operation = json.loads(os.environ['PROOF_OPERATION'])
expected = json.loads(sys.argv[1])
if operation['status'] != 'succeeded' or operation['result'] != expected:
    sys.exit(f'parity: database.read returned {operation["status"]} {json.dumps(operation["result"])}, expected {json.dumps(expected)}')
print(f'parity: database.read on {operation["input"]["driver"]} returned the expected {operation["input"]["mode"]} result')
