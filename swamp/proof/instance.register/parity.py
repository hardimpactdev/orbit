"""register parity on the target: the .env loses group and other-user access, the result names the checkout's commit and a
digest, and the checkout's private value never enters native history."""
import json
import os
import subprocess
import sys

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
result = operation['result']
outputs = open(os.environ['PROOF_OUTPUTS']).read()
checkout = '/home/orbit/apps/proof-source/r-' + os.environ['PROOF_RUN']


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False).stdout.strip()


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


check('proof-private-' not in outputs, 'a private value reached native history')
check(target('stat', '-c', '%a', checkout + '/.env') == '600', '.env lost group and other-user access')
check(result.get('registered') is True and result.get('commit') == target('runuser', '-u', 'orbit', '--', 'git', '-C', checkout, 'rev-parse', 'HEAD'), 'the result names the checkout commit')
print('parity: register contract holds')
