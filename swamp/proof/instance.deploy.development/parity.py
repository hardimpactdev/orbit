"""instance:deploy contract for a development default (rule 22): the steps ran in the checkout as the Node account,
their output stayed on the Node, the best-effort failure did not fail the deployment, the checkout sits at the
deployed commit on its branch, and no fetch credential is left."""
import json
import os
import subprocess
import sys
from pathlib import Path

operation = json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text())
outputs = Path(os.environ['PROOF_OUTPUTS']).read_text()
deployment = operation['input']['deployment']
home = deployment['home']


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


check(operation['status'] == 'succeeded', f'the deployment succeeded, got {operation["status"]} {operation.get("error_code")}')
commit = operation['result']['commit']
check(isinstance(commit, str) and len(commit) == 40, 'the result names the deployed commit')
check('proof-private-deploy-output' not in outputs, 'step stdout reached native history')
marker = home + '/.git/orbit-proof-deploy'
check(target('cat', marker).stdout == 'x', 'the required step ran once, in the checkout')
check(target('stat', '-c', '%U', marker).stdout.strip() == deployment['node_user'], 'the step ran as the Node account')
head = target('git', '-c', 'safe.directory=*', '-C', home, 'rev-parse', 'HEAD').stdout.strip()
branch = target('git', '-c', 'safe.directory=*', '-C', home, 'symbolic-ref', '--short', 'HEAD').stdout.strip()
check(head == commit and branch == deployment['branch'], f'the checkout is at {commit} on {deployment["branch"]}, found {head} on {branch}')
left = target('sh', '-c', 'ls /tmp/orbit-git-credential-* 2>/dev/null || true').stdout.strip()
check(left == '', f'no fetch credential is left, found {left}')
print(f'parity: instance:deploy put the default at {commit[:12]} and ran its steps without leaving output or a credential')
