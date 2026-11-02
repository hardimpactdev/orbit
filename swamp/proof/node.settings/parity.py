"""Legacy node.prepare-storage-root contract on the target (F9) and the stored settings.

Argument: created (/srv/proof-<run>/apps) or refused (/srv/proof-link-<run>/apps, a symlink to /srv/proof-real-<run>). created: the folder is orbit:orbit 0755 and the
Operation projected prepared. refused: the Operation failed with node.settings_root_failed and no folder appeared.
"""
import json
import os
import subprocess
import sys

operation = json.loads(os.environ['PROOF_OPERATION'])
expected = sys.argv[1]
run = os.environ['PROOF_RUN']
path = f'/srv/proof-{run}/apps' if expected == 'created' else f'/srv/proof-real-{run}'


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False).stdout.strip()


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message} ({path})')


observed = target('stat', '-c', '%F %U:%G %a', path)
if expected == 'created':
    check(observed == 'directory orbit:orbit 755', f'the folder is {observed!r}')
    check(operation['status'] == 'succeeded' and operation['result'] == {'prepared': True}, 'the Operation projected prepared')
else:
    check(operation['status'] == 'failed' and operation['error_code'] == 'node.settings_root_failed', 'the Operation failed with node.settings_root_failed')
    check(target('sh', '-c', f'test -e {path}/apps || echo absent') == 'absent', 'nothing was created behind the symlink')
print(f'parity: node.settings {expected} {path}')
