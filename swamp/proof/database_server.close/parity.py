"""database_server.close gives default the admin password: no-password PING gets NOAUTH, the admin password works,
the ACL file holds the password hash, and the password never reaches the task data."""
import hashlib
import json
import os
import subprocess
import sys

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
run = os.environ['PROOF_RUN']
password = 'proof-admin-' + run


def target(*argv, stdin=None):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], input=stdin, capture_output=True, text=True, check=False).stdout.strip()


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message}')


cli = ['docker', 'exec', '-i', 'proof-valkey-open', 'valkey-cli', '--no-auth-warning', '-p', '36390']
check(operation['status'] == 'succeeded', f'the Operation is {operation["status"]} ({operation.get("error_code")})')
check(target(*cli, 'PING').startswith('NOAUTH'), 'a client without a password gets NOAUTH')
check(target(*cli, stdin=f'AUTH default {password}\nPING\n').split() == ['OK', 'PONG'], 'the admin password works')
check('#' + hashlib.sha256(password.encode()).hexdigest() in target('docker', 'exec', 'proof-valkey-open', 'cat', '/data/users.acl'), 'the ACL file holds the password hash')
check(password not in json.dumps(operation), 'the password never reaches the task')
print('parity: database_server.close closed')
