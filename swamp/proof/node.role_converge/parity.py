"""Convergence repairs role drift and keeps matching service identities stable."""
import json
import subprocess
import sys
from pathlib import Path

target, role = sys.argv[1:3]
subprocess.run(['python3', 'swamp/proof/node.role_add/parity.py', target, role, *sys.argv[3:]], check=True, timeout=120)
before = json.loads(Path('/home/orbit/.orbit/swamp-proof/role-converge-' + role + '-before.json').read_text())


def run(*argv):
    return subprocess.run(['incus', 'exec', target, '--', *argv], capture_output=True, text=True, check=True, timeout=30).stdout.strip()


for service, pid in before.get('services', {}).items():
    if run('systemctl', 'show', service, '-p', 'MainPID', '--value') != pid:
        sys.exit('parity: matching ' + service + ' restarted during convergence')
for name, identity in before.get('containers', {}).items():
    if run('docker', 'inspect', '--format', '{{.Id}} {{.State.StartedAt}}', name) != identity:
        sys.exit('parity: matching ' + name + ' was replaced during convergence')
if role == 'app-dev' and run('stat', '-c', '%a', '/home/orbit/apps') != '755':
    sys.exit('parity: application storage mode drift was not repaired')
print('parity: node.role_converge ' + role + ', runtime identity preserved')
