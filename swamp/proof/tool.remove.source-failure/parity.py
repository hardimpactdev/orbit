"""Best-effort source removal (Nick, 2026-11-01): the package is gone and the task succeeded, while the apt source
whose removal failed stays on the Node and the run reports it kept."""
import json
import os
import subprocess
import sys

operation = json.loads(os.environ['PROOF_OPERATION'])
result = operation.get('result') or {}


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message + ' ' + json.dumps(result))


check(operation['status'] == 'succeeded' and result.get('outcome') == 'applied', 'the removal succeeded')
check(not target('dpkg-query', '--show', '--showformat=${Status}', 'rex').stdout.startswith('install ok installed'), 'rex is gone')
check(target('test', '-f', '/etc/apt/sources.list.d/orbit-rex.sources').returncode == 0, 'the source whose removal failed stays')
check(result.get('repository_removed') is False, 'the run reports the source kept, so the Gateway keeps its record')
target('chattr', '-i', '/etc/apt/sources.list.d/orbit-rex.sources')
print('parity: rex removed, apt source kept and reported')
