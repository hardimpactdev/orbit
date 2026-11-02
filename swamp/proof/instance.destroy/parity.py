"""Legacy instance:destroy contract on the target (F9): a normal removal of pushed work runs the teardown list,
stops and removes the development Process, removes the checkout, and keeps teardown stdout off native history."""
import json
import os
import subprocess
import sys
from pathlib import Path

operation = json.loads(os.environ['PROOF_OPERATION'])
result = operation['result']
outputs = Path(os.environ['PROOF_OUTPUTS']).read_text()
member = operation['input']['members'][0]
checkout = member['checkout_path']


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


check('proof-private-teardown-output' not in outputs, 'teardown stdout reached native history')
check(result['removed'] is True and result['source_inspection']['members'][0]['instance_id'] == member['instance_id'], 'the removal receipt with its source capture')
check(target('test', '-e', checkout).returncode != 0, 'the checkout is gone')
check(target('test', '-f', str(Path(checkout).parent / ('teardown-ran-' + os.environ['PROOF_RUN']))).returncode == 0, 'teardown ran in the checkout')
for process in operation['input']['native_destroy']['native_runtime']['processes']:
    check(target('test', '-e', '/etc/systemd/system/' + process['name']).returncode != 0, process['name'] + ' unit is removed')
    check(target('systemctl', 'is-active', process['name']).stdout.strip() != 'active', process['name'] + ' is stopped')
print('parity: legacy instance:destroy contract holds')
