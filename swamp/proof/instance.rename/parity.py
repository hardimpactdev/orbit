"""rename parity on the target: the result records the branch the checkout has checked out, and the checkout's
private value never enters native history."""
import json
import os
import subprocess
import sys

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
outputs = open(os.environ['PROOF_OUTPUTS']).read()
checkout = '/home/orbit/apps/proof-source/r-' + os.environ['PROOF_RUN']
branch = subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', 'runuser', '-u', 'orbit', '--', 'git', '-C', checkout,
                         'branch', '--show-current'], capture_output=True, text=True, check=False).stdout.strip()
if 'proof-private-' in outputs:
    sys.exit('parity: a private value reached native history')
if branch != 'master' or operation['result'].get('branch') != branch:
    sys.exit('parity: the result records the checked-out branch')
print('parity: rename contract holds')
