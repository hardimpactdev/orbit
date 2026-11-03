"""Role-owned resources work through the real Gateway and native workflow."""
import json
import os
import re
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from common import doctor, gateway

operation = json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text())
target, role = sys.argv[1:3]


def run(*argv):
    return subprocess.run(['incus', 'exec', target, '--', *argv], capture_output=True, text=True, check=False)


def check(ok, message):
    if not ok:
        sys.exit('parity: ' + message)


if operation['status'] != 'succeeded' and role == 'websocket':
    state = run('systemctl', 'show', 'orbit-websocket.service', '-p', 'Result', '-p', 'ExecMainStatus')
    sys.stderr.write(state.stdout)
    web = operation['input']['native_role']['runtime']['websocket']
    private = run('cat', web['release'] + '/.env').stdout
    logs = run('journalctl', '-u', 'orbit-websocket.service', '--no-pager', '-n', '20').stdout
    for line in private.splitlines():
        if line and not line.startswith('#') and '=' in line:
            value = line.split('=', 1)[1]
            if len(value) >= 6:
                logs = logs.replace(value, '[REDACTED]')
    sys.stderr.write(logs)
check(operation['status'] == 'succeeded', 'native role-add succeeded')
check(operation['result'].get('role_proven') is True, 'the Gateway records the native end check')
intent = operation['input']['native_role']
check(intent['role'] == role, 'the admitted role is preserved')
for service in intent['services']:
    check(run('systemctl', 'is-active', '--quiet', service['name']).returncode == 0, service['name'] + ' is active')
for resource in [*intent['files'], *intent['runtime']['files']]:
    if resource.get('ensure') == 'absent':
        check(run('test', '-e', resource['path']).returncode == 1, 'retired role file is absent')
    else:
        check(run('cat', resource['path']).stdout == resource['content'], 'the admitted public role file is installed')
for resource in intent['runtime']['secret_files']:
    check(run('stat', '-c', '%a', resource['path']).stdout.strip() == '600', 'private runtime file is mode 0600')
if role in ('app-dev', 'app-prod'):
    check(run('bun', '--version').stdout.strip() == intent['bun']['version'], 'Bun matches the recipe')
    check(run('test', '-d', '/home/orbit/apps').returncode == 0, 'the application storage directory exists')
    for php in intent['php']:
        check(run('systemctl', 'is-active', '--quiet', 'php' + php['version'] + '-fpm.service').returncode == 0,
              'the admitted PHP-FPM version serves')
elif role == 'websocket':
    check(run('systemctl', 'is-active', '--quiet', 'orbit-websocket.service').returncode == 0, 'Reverb serves')
    check(run('readlink', intent['runtime']['websocket']['path']).stdout.strip() == intent['runtime']['websocket']['release'],
          'Reverb uses the admitted release')
elif role == 'metrics':
    check(run('docker', 'inspect', '--format', '{{.State.Running}}', 'orbit-metrics-prometheus').stdout.strip() == 'true',
          'Prometheus runs')
    response = run('curl', '--fail', '--silent', 'http://127.0.0.1:9090/api/v1/status/config')
    check(response.returncode == 0, 'Prometheus exposes its active configuration')
    active = json.loads(response.stdout)['data']['yaml']
    response = subprocess.run(['curl', '--max-time', '15', '--fail', '--silent', '--show-error',
                               '--cacert', '/home/orbit/.orbit/ca/root.pem', '--resolve',
                               'metrics.orbit:443:10.231.0.1', 'https://metrics.orbit/api/health'],
                              capture_output=True, text=True, check=False)
    check(response.returncode == 0, 'the published Metrics HTTPS route serves Grafana: ' + response.stderr)
    for exporter in intent['exporters']:
        if exporter['enabled']:
            address = exporter['ssh_execution']['target']['host']
            check(address + ':9100' in active, 'Prometheus loaded the selected exporter target')
elif role == 'ci-runner':
    check(run('incus', 'project', 'get', 'orbit-ci', 'restricted').stdout.strip() == 'true', 'CI is restricted')
    check(run('incus', 'network', 'get', 'orbitci0', 'security.acls').stdout.strip() == 'orbit-ci-egress', 'CI egress ACL applies')
print('parity: node.role_add ' + role)

if 'no-drift' in sys.argv[3:]:
    outputs = json.loads(Path(os.environ['PROOF_OUTPUTS']).read_text())
    recaps = re.findall(r'changed=(\d+)', json.dumps(outputs))
    check(bool(recaps), 'Ansible phase recaps were retained')
    check(all(value == '0' for value in recaps), 'the second run changed no Node state: ' + ','.join(recaps))
    print('parity: all Ansible phases changed=0')

# Doctor reads the observer facts installed by fixture preparation.

doctor(json.loads(os.environ['PROOF_NODES'])[target])
check(not Path('/tmp/orbit-ansible-' + operation['swamp_run_id']).exists(), 'the private Ansible run folder was removed')
# --idempotency-key replays the original admission without another run or Node change.
body = {'role': role, **({'converge_existing': True} if operation['kind'] == 'node.role_converge' else {})}
replayed = gateway('POST', 'nodes/' + str(intent['node_id']) + '/roles', body, 'proof-' + os.environ['PROOF_RUN'] + '-request')
record = replayed['response']['data']
check((record.get('operation') or record).get('id') == operation['id'], 'the idempotency key returns the same Operation')
print('parity: private inputs removed; original admission replayed')
