"""tasks.maintenance_finish of a no-change maintenance group without a workspace.

The minimal topology has no task VM host, so like tasks.cancel this proves admission, the shared cleanup run on
serve and the projection: the group ends completed, not cancelled, with a complete cleanup receipt.
"""
import json
import os
import sqlite3
import sys

GATEWAY = 'apps/gateway'
with open(os.environ['PROOF_OPERATION_FILE']) as source:
    operation = json.load(source)
with open(f'{GATEWAY}/.env') as source:
    environment = dict(line.split('=', 1) for line in source.read().splitlines() if '=' in line)
database = sqlite3.connect(environment['DB_DATABASE'])


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message}')


result = operation.get('result') or {}
group = operation.get('target_id')
check(operation.get('kind') == 'tasks.maintenance_finish', f'the Operation is {operation.get("kind")}')
check(operation.get('status') == 'succeeded', f'the Operation is {operation.get("status")}')
check(operation.get('input', {}).get('push') is None, 'no change, so no push is planned')
check(result.get('group_id') == group and result.get('instance_id') is None, 'the receipt names the group and no workspace')
check(result.get('cleanup_complete') is True and not result.get('vm'), 'cleanup is complete with no VM')
status = database.execute('select status from tasks where id = ?', (group,)).fetchone()[0]
check(status == 'completed', f'the group is {status}, not completed')
print(f'parity: tasks.maintenance_finish completed group {group} with no workspace and a complete receipt')
