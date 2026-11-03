"""Legacy instance:setup contract on the target (F9): the Project setup list ran again in the checkout as
the Instance account, its stdout never reached native history, and the result is the bounded success receipt."""
import json
import os
import subprocess
import sys
from pathlib import Path

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
result = operation['result']
outputs = Path(os.environ['PROOF_OUTPUTS']).read_text()
directory = operation['input']['execution']['directory']


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


# The command text is a run input by decision; its stdout must stay on the Node.
check('proof-private-step-output' not in outputs, 'step stdout reached native history')
if sys.argv[1:] == ['redaction']:
    check(operation['status'] == 'failed', 'the failing setup Operation failed')
    detail = operation['state']['native_failure']['detail']
    check(detail.strip() == '[REDACTED]\nsafe-diagnostic', 'overlapping stored values are fully redacted from the public stderr diagnostic')
    check(target('test', '!', '-e', directory + '/.git/proof-must-not-run').returncode == 0, 'later steps were skipped')
    check(target('cat', directory + '/.git/orbit-proof-steps').stdout == 'xx', 'the running list executed its first step once')
    print('parity: overlapping values redacted, stdout discarded, later setup steps skipped')
    sys.exit(0)
check(result == {'instance_id': operation['input']['instance_id'], 'outcome': 'succeeded', 'no_op': False}, 'the bounded success receipt')
marker = directory + '/.git/orbit-proof-steps'
check(target('cat', marker).stdout == 'xx', 'create and setup each ran the list once, in the checkout')
check(target('stat', '-c', '%U', marker).stdout.strip() == operation['input']['execution']['user'], 'the list ran as the Instance account')
print('parity: legacy instance:setup contract holds')
