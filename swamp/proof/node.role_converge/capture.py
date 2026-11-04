"""Record service identity after baseline convergence, then introduce a repairable directory drift."""
import json
import subprocess
import sys
from pathlib import Path

target, role = sys.argv[1:3]


def run(*argv):
    return subprocess.run(['incus', 'exec', target, '--', *argv], capture_output=True, text=True, check=True, timeout=30).stdout.strip()


state = {}
if role == 'app-dev':
    units = run('systemctl', 'list-units', '--type=service', '--state=running', '--no-legend', '--plain', 'php*-fpm.service')
    services = [line.split()[0] for line in units.splitlines()]
    if not services:
        sys.exit('the baseline has no running PHP-FPM master')
    state['services'] = {service: run('systemctl', 'show', service, '-p', 'MainPID', '--value') for service in services}
    if 'stable' not in sys.argv[3:]:
        run('chmod', '0777', '/home/orbit/apps')
elif role == 'websocket':
    state['services'] = {'orbit-websocket.service': run('systemctl', 'show', 'orbit-websocket.service', '-p', 'MainPID', '--value')}
elif role == 'metrics':
    state['containers'] = {name: run('docker', 'inspect', '--format', '{{.Id}} {{.State.StartedAt}}', name)
                           for name in ('orbit-metrics-prometheus', 'orbit-metrics-grafana')}
else:
    sys.exit('unsupported convergence fixture')
if any(pid == '0' for pid in state.get('services', {}).values()):
    sys.exit('the baseline service is not running')
Path('/home/orbit/.orbit/swamp-proof/role-converge-' + role + '-before.json').write_text(json.dumps(state))
