"""database:user:create refusal on PostgreSQL: a replication login on a server without wal_level=logical fails with
database.wal_level_not_logical and creates no role."""
import json
import os
import subprocess
import sys
from pathlib import Path

operation = json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text())
container = operation['input']['container']


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


check(operation['status'] == 'failed' and operation['error_code'] == 'database.wal_level_not_logical',
      f"the Operation fails with the WAL code: {operation['status']} {operation.get('error_code')}")
roles = subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', 'docker', 'exec', container, 'psql', '--no-psqlrc',
                        '--username=postgres', '--tuples-only', '--no-align',
                        "--command=SELECT count(*) FROM pg_roles WHERE rolname = 'proof_repl'"], capture_output=True, text=True, check=False)
check(roles.returncode == 0 and roles.stdout.strip() == '0', f'no role was created: {roles.stdout.strip()} {roles.stderr.strip()}')
print(f'parity: database_user.create refused a replication login on {container} before any change')
