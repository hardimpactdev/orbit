"""tasks.analyze on the stand-in workspace: the complete report, its provenance, and no change to the checkout.

The target Node stands in for the task VM. The analyzer ran once as one SSH exec argv; the real index and the tree are unchanged
and the temporary snapshot index is gone.
"""
import hashlib
import json
import os
import subprocess
import sys

operation = json.loads(os.environ['PROOF_OPERATION'])
workspace = f"/srv/proof-task-{os.environ['PROOF_RUN']}"


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, check=False).stdout


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message}')


result = operation.get('result') or {}
check(operation.get('status') == 'succeeded' and result.get('outcome') == 'ready', f'the Operation is {operation.get("status")} {result.get("outcome")}')
provenance = result['provenance']
content = result['artifact']['content'].encode()
check(result['artifact']['sha256'] == hashlib.sha256(content).hexdigest(), 'the artifact digest matches its content')
check(provenance['entrypoint_sha256'] == hashlib.sha256(target('cat', f'{workspace}/bin/task-analyze')).hexdigest(), 'the entrypoint digest matches the file')
check(provenance['before'] == provenance['after'], 'the analyzer changed nothing')
check(target('runuser', '-u', 'orbit', '--', 'git', '-C', workspace, 'status', '--porcelain') == b'', 'the checkout is clean')
check(target('sh', '-c', 'ls /tmp/orbit-task-index-* 2>/dev/null') == b'', 'the temporary snapshot index is removed')
print('parity: tasks.analyze ready, provenance and clean checkout')
