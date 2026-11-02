"""Legacy Tool contract on the target (F9), for install, update, remove and adopt of one apt package:
the package state dpkg reports, the version the run returned, and no manager output in native history."""
import json
import os
import subprocess
import sys
from pathlib import Path

operation = json.loads(os.environ['PROOF_OPERATION'])
result, kind, status = operation.get('result') or {}, operation['kind'], operation['status']
outputs = Path(os.environ['PROOF_OUTPUTS']).read_text()
package = operation['input']['package']


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message + ' ' + json.dumps({'status': status, 'error_code': operation.get('error_code'), 'result': result}))


query = subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', 'dpkg-query', '--show', '--showformat=${Status}\t${Version}', package],
                       capture_output=True, text=True, check=False)
installed = query.returncode == 0 and query.stdout.startswith('install ok installed')
version = query.stdout.split('\t')[1] if installed else None
check('Setting up' not in outputs and 'Unpacking' not in outputs, 'apt output reached native history')
if status == 'failed':
    check(operation.get('error_code') == 'tool.version_constraint_blocked', 'the constraint blocked the install')
    check(not installed, 'a blocked install writes nothing')
    check(isinstance(result.get('manager_version'), str), 'the manager is recorded ready, so the Gateway marks the Tool failed')
elif kind == 'tool.remove':
    check(not installed and result.get('outcome') == 'applied', 'the removed package is absent')
else:
    check(installed and result.get('installed_version') == version, 'the recorded version is the dpkg version')
    expected = {'tool.install': 'applied', 'tool.update': 'unchanged', 'tool.adopt': 'applied'}[kind]
    check(result.get('outcome') == expected, 'outcome ' + expected)
print(f'parity: {kind} {status} {package}={version}')
