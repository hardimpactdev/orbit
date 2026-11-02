"""instance:destroy with an owned MySQL database: the database:destroy child drops it on the server Node and leaves
no admin login file there."""
import json
import os
import subprocess
import sys

operation = json.loads(os.environ['PROOF_OPERATION'])
server = operation['input']['direct_destroy']['database']['servers'][0]
names = server['intent']['databases']


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


check(operation['status'] == 'succeeded' and operation['result']['removed'] is True, 'the Instance removal succeeded')
check(names != [], 'the owned set names a database')
container = server['intent']['container']
datadir = target('docker', 'exec', container, 'ls', '/var/lib/mysql').stdout.split()
check(not set(names) & set(datadir), f'the owned databases {names} are gone from {container}')
left = target('sh', '-c', 'ls /run/orbit-database-destroy-owned-*.env 2>/dev/null || true').stdout.strip()
check(left == '', f'no admin login file is left on the Node, found {left}')
print(f'parity: instance:destroy drops {names} on {container} through the database:destroy child')
