"""env:import contract on the target: the Node file read once through the vault, the new key stored, no value
in native history, no staged copy left on the Node or on 209, the Node file unchanged, and a value-free result."""
import glob
import json
import os
import subprocess
import sys
from pathlib import Path

operation = json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text())
result = operation['result']
base = operation['input']['direct_import']['base']
outputs = Path(os.environ['PROOF_OUTPUTS']).read_text()
run = os.environ['PROOF_RUN']


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


check('imported-' + run not in outputs and 'proof-private-' + run not in outputs, 'a file value reached native history')
check(set(result) == {'instance_id', 'operation', 'changed', 'key_count'}, 'the result holds only public fields')
check(result['operation'] == 'import' and result['changed'] is True and result['key_count'] >= 2, 'the new file key changed stored configuration')
check('PROOF_IMPORTED="imported-' + run + '"' in target('cat', base + '/.env').stdout, 'the Node file stays as it was')
check(target('sh', '-c', 'ls /tmp/orbit-env-import-* 2>/dev/null').stdout == '', 'no staged copy stays on the Node')
check(glob.glob('/tmp/orbit-env-import-*') == [], 'no copy stays on 209')
print('parity: env:import contract holds')
