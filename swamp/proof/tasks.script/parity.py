"""tasks.script on the stand-in workspace: literal arguments, the public result, and no change to the checkout.

The target Node stands in for the task VM. The script ran once as one SSH exec argv with its literal arguments; nothing was
expanded by a shell, and the temporary snapshot index is gone.
"""
import json
import os
import subprocess
import sys

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
workspace = f"/srv/proof-task-{os.environ['PROOF_RUN']}"


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, check=False).stdout


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message}')


result = operation.get('result') or {}
check(operation.get('status') == 'succeeded', f'the Operation is {operation.get("status")}')
check(result.get('outcome') == 'passed' and result.get('exit_code') == 0, f'the script result is {result.get("outcome")}')
check(result.get('output') == '$(touch never)\n spaces \n', f'the output is {result.get("output")!r}')
check(result.get('changed') is False, 'the script changed nothing')
check(target('sh', '-c', f'test -e {workspace}/never && echo present') == b'', 'no shell expanded an argument')
check(target('sh', '-c', 'ls /tmp/orbit-task-index-* 2>/dev/null') == b'', 'the temporary snapshot index is removed')
check(target('sh', '-c', 'ls -d /tmp/orbit-task-repository-* 2>/dev/null') == b'', 'the program output directory is removed')
print('parity: tasks.script passed with literal arguments')
