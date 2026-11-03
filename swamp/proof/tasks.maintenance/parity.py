"""Verify the producer receipt and unchanged real Git index after Composer maintenance."""
import json
import os
import subprocess

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
assert operation['status'] == 'succeeded', operation
result = operation['result']
assert result['outcome'] == 'passed' and result['changed'] is False, result
for key in ('group_id', 'project_id', 'instance_id', 'subtask'):
    assert result[key] == operation['input'][key], (key, result)
workspace = operation['input']['checkout_path']
def git(*argv):
    return subprocess.check_output(['incus', 'exec', 'target', '--', 'runuser', '-u', 'orbit', '--',
                                    'git', '-C', workspace, *argv], text=True).strip()
assert git('status', '--porcelain') == '', git('status', '--porcelain')
assert git('rev-parse', 'HEAD') == operation['input']['base']
assert git('diff', '--cached', '--name-only') == ''
print('Real Composer routine and native path checks passed; captured identities and the real Git index/base are unchanged. Downstream admission was disabled for this root-only proof.')
