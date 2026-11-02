"""clone parity on the target: the clone holds the candidate's committed source and a private .env with the stored
value, the fetch credential file is gone, and the stored value never enters native history."""
import json
import os
import subprocess
import sys

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
outputs = open(os.environ['PROOF_OUTPUTS']).read()
run = os.environ['PROOF_RUN']
clone = '/home/orbit/apps/proof-clone/k-' + run


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False).stdout.strip()


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


check('proof-private-' not in outputs, 'a stored value reached native history')
check('proof-private-' + run in target('cat', clone + '/.env'), 'the stored value reached the clone .env')
check(target('stat', '-c', '%a', clone + '/.env') == '600', 'the clone .env is private')
check(operation['result'].get('commit') == operation['result'].get('candidate_commit'), 'the clone commit equals the candidate commit')
check(target('sh', '-c', 'ls /tmp/orbit-git-credential-* 2>/dev/null') == '', 'the fetch credential file is removed')
print('parity: clone contract holds')
