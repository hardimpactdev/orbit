"""tasks.check baseline on the stand-in workspace: setup ran before the Project check, which passed with no candidate.

The target Node stands in for the task VM. Setup and the Project check each ran once as one SSH exec argv; the
checked tree is the start commit plus the setup output, the real index is untouched and the run's scratch directory
is gone.
"""
import json
import os
import subprocess
import sys

START = '189e114ecd09ea98d9b30da48f03c93383decf66'
operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
workspace = f"/srv/proof-task-{os.environ['PROOF_RUN']}"


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, check=False).stdout


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message}')


result = operation.get('result') or {}
check(operation.get('status') == 'succeeded' and result.get('outcome') == 'passed', f'the Operation is {operation.get("status")} {result.get("outcome")}')
check(result.get('phase') == 'baseline' and result.get('commit') is None and result.get('ci') is None, 'a baseline records no candidate or CI')
check(result.get('exit_code') == 0 and result.get('failed_step') is None, 'setup and the Project check passed')
check(result.get('head') == START and result.get('head_before') == START and result.get('subtask_start_commit') == START, 'HEAD stayed on the start commit')
check(result.get('tree') == result.get('tree_before') and result.get('changed_paths') == [], 'the Project check changed nothing')
check(all(item.get('outcome') == 'not_checked' for item in result.get('deliverables', [])), 'a baseline checks no deliverable')
check(target('cat', f'{workspace}/setup.txt') == b'new', 'setup ran in the checkout')
check(target('runuser', '-u', 'orbit', '--', 'git', '-C', workspace, 'status', '--porcelain') == b'?? setup.txt\n', 'only the setup output is new; nothing was staged')
check(target('sh', '-c', 'ls -d /tmp/orbit-task-check-* 2>/dev/null') == b'', 'the run scratch directory is removed')
print('parity: tasks.check baseline passed, setup ran first, no candidate and a clean index')
