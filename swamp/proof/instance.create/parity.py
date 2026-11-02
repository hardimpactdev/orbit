"""Legacy create contract on the target (F9): the branch and commit, the Gateway-rendered .env byte for byte,
setup at the checkout root, the development Process running, and a run that returned only the commit."""
import hashlib
import json
import os
import subprocess
import sys
from pathlib import Path

operation = json.loads(os.environ['PROOF_OPERATION'])
result = operation['result']
outputs = Path(os.environ['PROOF_OUTPUTS']).read_text()
checkout = operation['input']['checkout']
branch = operation['input']['direct_provisioning']['resolve']['branch']


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


check('proof-private-setup-output' not in outputs, 'setup stdout reached native history')
check('APP_ENV=' not in outputs and 'APP_NAME=' not in outputs, 'environment content reached native history')
head = target('runuser', '-u', 'orbit', '--', 'git', '-C', checkout, 'rev-parse', 'HEAD').stdout.strip()
check(result['commit'] == head, 'the projected commit is the checked-out commit')
check(target('runuser', '-u', 'orbit', '--', 'git', '-C', checkout, 'rev-parse', '--abbrev-ref', 'HEAD').stdout.strip() == branch, 'admitted branch')
environment = target('cat', checkout + '/.env').stdout
rendered = subprocess.run(['runuser', '-u', 'orbit', '--', 'php', str(Path.cwd() / 'swamp/proof/instance.create/rendered.php'),
                           str(operation['input']['instance_id'])], cwd=Path.cwd() / 'apps/gateway', capture_output=True, text=True, check=False)
check(hashlib.sha256(environment.encode()).hexdigest() == rendered.stdout.strip(), '.env equals the Gateway rendering')
check(target('stat', '-c', '%a %U', checkout + '/.env').stdout.strip() == '600 orbit', '.env is private to the account')
if result['ready']:
    check(set(result) == {'instance_id', 'ready', 'name_converted_from', 'commit'}, 'the run returned only the commit')
    check(target('test', '-f', checkout + '/.git/orbit-proof-setup').returncode == 0, 'setup ran at the checkout root')
    for member in operation['input']['members']:
        unit = member['input']['name']
        check(target('systemctl', 'is-active', unit).stdout.strip() == 'active', unit + ' is running')
    if operation['input']['members']:
        check(target('curl', '-fsS', '-o', '/dev/null', 'http://127.0.0.1:8765/').returncode == 0, 'the dev server answers')
else:
    check((result['failed_step'], result['error_code'], result['failed_command']) == ('setup', 'instance.setup_step_failed', 'marker'), 'setup refusal')
print('parity: legacy create contract holds')
