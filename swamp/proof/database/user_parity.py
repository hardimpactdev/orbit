"""database:user:create contract on the target: the read-only user logs in with its own password and can read only
its database, and no login file is left on the Node."""
import json
import os
import subprocess
import sys

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
container = operation['input']['container']


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


check(operation['status'] == 'succeeded' and operation['result'] == {'backend': 'active'}, 'the projected result is backend active')
login = target('docker', 'exec', '-e', 'MYSQL_PWD=proof-reader-pw-1', container, 'mysql', '--protocol=TCP', '--host=127.0.0.1',
               '--user=proof_reader', '--batch', '--skip-column-names', '-e', 'SHOW GRANTS')
check(login.returncode == 0, f'the user logs in with its own password: {login.stderr.strip()}')
# mysql --batch escapes a backslash in its output, so the escaped grant shows doubled backslashes.
grants = login.stdout.replace('\\\\', '\\')
check('GRANT SELECT ON `' + operation['input']['database'].replace('_', '\\_') + '`.*' in grants and 'ALL PRIVILEGES' not in grants, f'only SELECT on its database: {grants}')
left = target('sh', '-c', 'ls /run/orbit-database-user-create-*.env 2>/dev/null || true').stdout.strip()
check(left == '', f'no login env file is left on the Node, found {left}')
print(f'parity: database_user.create made a read-only login on {container} and left no login file')
