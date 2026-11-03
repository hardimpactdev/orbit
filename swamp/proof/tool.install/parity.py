"""Legacy Tool contract on the target (F9), for install, update, remove and adopt of one apt package:
the package state dpkg reports, the version the run returned, and no manager output in the API result."""
import json
import os
import subprocess
import sys
from pathlib import Path

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
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
check('Setting up' not in json.dumps(result) and 'Unpacking' not in json.dumps(result), 'apt output reached the API result')
if sys.argv[1:] == ['wrong-fingerprint']:
    check(status == 'failed' and operation.get('error_code') == 'tool.repository_key_mismatch', 'the key mismatch stops the install')
    check(not installed, 'a mismatched key installs no package')
    for path in ['/etc/apt/keyrings/orbit-rex.gpg', '/etc/apt/sources.list.d/orbit-rex.sources', '/etc/apt/preferences.d/orbit-rex.pref']:
        check(subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', 'test', '!', '-e', path]).returncode == 0, 'no repository file was published: ' + path)
    print('parity: mismatched fingerprint refused before repository publication')
    sys.exit(0)
if status == 'failed':
    check(operation.get('error_code') == 'tool.version_constraint_blocked', 'the constraint blocked the install')
    check(not installed, 'a blocked install writes nothing')
    check(isinstance(result.get('manager_version'), str), 'the manager is recorded ready, so the Gateway marks the Tool failed')
elif kind == 'tool.remove':
    check(not installed and result.get('outcome') == 'applied', 'the removed package is absent')
    if operation['input'].get('remove_repository'):
        check(result.get('repository_removed') is True, 'the last Tool reports its source removed')
        name = 'orbit-' + operation['input']['repository']['name']
        for path in ['/etc/apt/sources.list.d/' + name + '.sources', '/etc/apt/keyrings/' + name + '.gpg', '/etc/apt/preferences.d/' + name + '.pref']:
            check(subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', 'test', '!', '-e', path]).returncode == 0, 'managed repository file is absent: ' + path)

else:
    check(installed and result.get('installed_version') == version, 'the recorded version is the dpkg version')
    expected = {'tool.install': 'applied', 'tool.update': 'unchanged'}[kind]
    check(result.get('outcome') == expected, 'outcome ' + expected)
    repository = operation['input'].get('repository')
    if repository:
        key = '/etc/apt/keyrings/orbit-' + repository['name'] + '.gpg'
        fingerprint = subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', 'gpg', '--batch', '--with-colons', '--show-keys', key], capture_output=True, text=True, check=True).stdout
        check([row.split(':')[9] for row in fingerprint.splitlines() if row.startswith('fpr:')][0] == repository['signing_key']['fingerprint'], 'the published binary key has the admitted fingerprint')
        source = subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', 'cat', '/etc/apt/sources.list.d/orbit-' + repository['name'] + '.sources'], capture_output=True, text=True, check=True).stdout
        check('Signed-By: ' + key in source and all(uri in source for uri in repository['uris']), 'deb822 uses the verified key and admitted source')
        preference = subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', 'cat', '/etc/apt/preferences.d/orbit-' + repository['name'] + '.pref'], capture_output=True, text=True, check=True).stdout
        packages = ' '.join(operation['input']['repository_packages'])
        expected = '\n'.join(f'Package: {packages}\nPin: origin "{uri.split("/")[2]}"\nPin-Priority: 990\n\nPackage: *\nPin: origin "{uri.split("/")[2]}"\nPin-Priority: 1\n' for uri in repository['uris'])
        check(preference == expected, 'only the admitted packages have vendor priority 990; other packages have priority 1')
print(f'parity: {kind} {status} {package}={version}')
