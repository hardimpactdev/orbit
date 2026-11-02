"""Legacy env:sync contract on the target (F9): the Gateway-rendered .env byte for byte, private to the
account, the stored value never in native history, and a result with only key names and change flags."""
import hashlib
import json
import os
import subprocess
import sys
from pathlib import Path

operation = json.loads(os.environ['PROOF_OPERATION'])
result = operation['result']
plan = operation['input']['direct_environment']
outputs = Path(os.environ['PROOF_OUTPUTS']).read_text()


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


check('proof-private-' not in outputs, 'a stored value reached native history')
environment = target('cat', plan['base'] + '/.env').stdout
check('proof-private-' + os.environ['PROOF_RUN'] in environment, 'the stored value reached .env')
check(hashlib.sha256(environment.encode()).hexdigest() == plan['sha256'], '.env equals the Gateway rendering')
check(target('stat', '-c', '%a %U', plan['base'] + '/.env').stdout.strip() == '600 ' + plan['user'], '.env is private to the account')
check(result['keys'] == plan['keys'] and 'PROOF_SECRET' in result['keys'], 'the result names the synchronized keys')
check(result['runtime_refresh'] == 'skipped_development' and result['testing_status'] is None, 'development syncs only .env')
check(set(result) == {'instance_id', 'changed', 'keys', 'key_count', 'test_database', 'environment_changed', 'testing_status',
                      'runtime_refresh', 'restarted_process_ids'}, 'the result holds only public fields')
print('parity: legacy env:sync contract holds')
