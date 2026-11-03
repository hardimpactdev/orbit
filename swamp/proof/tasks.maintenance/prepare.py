"""Prepare a real Composer workspace with one local package, then admit its normal maintenance root."""
import json
import subprocess
import sys
from pathlib import Path

source = Path.cwd()
node, run_id = sys.argv[1:]
workspace = '/home/orbit/proof-maintenance-' + run_id
subprocess.run(['incus', 'exec', 'target', '--', 'install', '-d', '-o', 'orbit', '-g', 'orbit', workspace], check=True, timeout=30)
script = '''set -eu
mkdir -p packages/fixture
printf '%s\n' '{"name":"proof/fixture","version":"1.0.0","license":"MIT"}' > packages/fixture/composer.json
printf '%s\n' '{"name":"proof/maintenance","require":{"proof/fixture":"1.0.0"},"repositories":[{"type":"path","url":"packages/fixture","options":{"symlink":true}}]}' > composer.json
printf '%s\n' '/vendor/' > .gitignore
timeout 60 composer update --no-interaction --no-install --no-progress
git init -q -b main
git add .
git -c user.name=Proof -c user.email=proof@example.test -c commit.gpgsign=false commit -qm baseline
git rev-parse HEAD
'''
prepared = subprocess.check_output(['incus', 'exec', 'target', '--', 'runuser', '-u', 'orbit', '--',
                                    'env', '-C', workspace, 'sh', '-c', script], text=True, timeout=90)
base = prepared.strip().splitlines()[-1]
reply = subprocess.check_output(['php', str(source / 'swamp/proof/tasks.maintenance/prepare.php'), node, workspace, base],
                                cwd=source / 'apps/gateway', text=True, timeout=60)
print(json.dumps(json.loads(reply.strip().splitlines()[-1])))
