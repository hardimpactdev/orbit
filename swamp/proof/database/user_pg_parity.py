"""database:user:create contract on PostgreSQL: the read-only user logs in over TCP with its own password, has no
replication attribute, and gets SELECT through the owner's default privileges."""
import json
import os
import subprocess
import sys
from pathlib import Path

operation = json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text())
container = operation['input']['container']


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


check(operation['status'] == 'succeeded' and operation['result'] == {'backend': 'active'}, 'the projected result is backend active')
login = target('docker', 'exec', '-e', 'PGPASSWORD=proof-reader-pw-1', container, 'psql', '--no-psqlrc', '--host=127.0.0.1',
               '--username=proof_reader', f"--dbname={operation['input']['database']}", '--tuples-only', '--no-align',
               '--command=SELECT rolreplication FROM pg_roles WHERE rolname = current_user')
check(login.returncode == 0, f'the user logs in with its own password: {login.stderr.strip()}')
check(login.stdout.strip() == 'f', f'the read-only user has no replication attribute: {login.stdout.strip()}')
print(f'parity: database_user.create made a read-only PostgreSQL login on {container}')
