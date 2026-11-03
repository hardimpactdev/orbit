"""tasks.cancel of a group without a workspace: one run, no guest or provider step, a complete cleanup receipt.

The minimal topology has no task VM host, so this proves admission, the five chart steps on serve and the
projection. This receipt does not prove the push, Route, teardown or destroy paths.
"""
import json
import os
import sys

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message}')


result = operation.get('result') or {}
check(operation.get('status') == 'succeeded', f'the Operation is {operation.get("status")}')
check(operation.get('input', {}).get('push') is None, 'no approved work, so no push is planned')
check(result.get('group_id') == 900 and result.get('instance_id') is None, 'the receipt names group 900 and no workspace')
check(result.get('cleanup_complete') is True and not result.get('vm'), 'cleanup is complete with no VM')
print('parity: tasks.cancel ended group 900 with no workspace and a complete receipt')
