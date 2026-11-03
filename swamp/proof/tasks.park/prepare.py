"""Create one disposable nested VM for the real provider stop proof."""
import json
import subprocess
import sys
from pathlib import Path

source = Path.cwd()
node, run_id = sys.argv[1:]
environment = source / 'apps/gateway/.env'
lines = [line for line in environment.read_text().splitlines() if not line.startswith('ORBIT_TASK_VMS_WIREGUARD_RANGE=')]
environment.write_text('\n'.join([*lines, 'ORBIT_TASK_VMS_WIREGUARD_RANGE=10.231.0.128/25']) + '\n')
prepared = subprocess.check_output(['php', str(source / 'swamp/proof/tasks.park/prepare.php'), node, run_id],
                                   cwd=source / 'apps/gateway', text=True)
facts = json.loads(prepared.strip().splitlines()[-1])
name = facts['secrets']['vm_name']
subprocess.run(['incus', 'copy', 'node-template', name, '-c', 'limits.memory=512MiB', '-c', 'limits.cpu=1'], check=True, timeout=60)
subprocess.run(['incus', 'start', name], check=True, timeout=60)
subprocess.run(['timeout', '60', 'incus', 'wait', name, 'agent', '--timeout', '55', '--interval', '1'], check=True, timeout=65)
print(json.dumps(facts))
