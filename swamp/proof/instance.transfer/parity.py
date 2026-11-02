"""transfer parity: the checkout moved to the peer with its Git metadata and commit, and the source is gone."""
import os
import subprocess
import sys

run = os.environ['PROOF_RUN']
path = '/home/orbit/apps/proof-move/t-' + run


def node(key, *argv):
    return subprocess.run(['incus', 'exec', key, '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


check(node(os.environ['PROOF_TARGET'], 'test', '-e', path).returncode != 0, 'the source checkout is gone')
head = node('peer', 'runuser', '-u', 'orbit', '--', 'git', '-C', path, 'rev-parse', 'HEAD').stdout.strip()
check(len(head) == 40, 'the destination is an independent Git checkout')
check(node('peer', 'runuser', '-u', 'orbit', '--', 'git', '-C', path, 'status', '--porcelain', '--untracked-files=no').stdout.strip() == '',
      'the destination checkout is clean')
print('parity: transfer contract holds')
