"""database:create contract on the target (F9): the run succeeded, the staged owner password left the
Node, and on MySQL the owner's databases exist in the server's data directory.

Arguments: the expected outcome, `created`.
"""
import json
import os
import subprocess
import sys

operation = json.loads(os.environ['PROOF_OPERATION'])


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message} (operation {operation["id"]})')


check(operation['status'] == 'succeeded' and operation['result'] == {'backend': 'active'}, 'the projected result is backend active')
stage = '/run/orbit-database-create-' + str(operation['id'])
check(target('test', '-e', stage).returncode == 1, f'{stage} is gone from the Node')
listing = target('sh', '-c', 'docker ps --format "{{.Names}}" | grep "^orbit-process-.*-proof-dbsrv-' + os.environ['PROOF_RUN'] + '$"').stdout.strip()
if operation['input']['driver'] == 'mysql':
    check(listing != '', 'the server container runs')
    data = target('docker', 'exec', listing, 'ls', '/var/lib/mysql').stdout.split()
    check(any(name.startswith('proof_db_') for name in data), f'the database directory exists, got {data}')
print(f'parity: {operation["kind"]} created on {operation["input"]["driver"]} and removed {stage}')
