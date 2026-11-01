"""tool:install on a Linux first use (F9): the inspect phase installed the pinned Vite+ manager, then the package went into its store."""
import json
import os
import subprocess
import sys

operation = json.loads(os.environ['PROOF_OPERATION'])
result = operation.get('result') or {}


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', 'runuser', '-u', 'orbit', '--', *argv],
                          capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message + ' ' + json.dumps(result))


version = target('/usr/local/bin/vp', '--version')
check(version.returncode == 0, 'the managed vp launcher runs')
check(isinstance(result.get('manager_version'), str) and result['manager_version'] in version.stdout, 'the recorded manager version is the installed one')
listed = json.loads(target('/usr/local/bin/vp', 'list', '-g', '--json').stdout or '[]')
check([row.get('version') for row in listed if row.get('name') == 'is-number'] == [result.get('installed_version')], 'the package is in the store')
print('parity: vp ' + result['manager_version'] + ' is-number ' + str(result.get('installed_version')))
