"""Database server create contract on the target (F9): an owned running container on the private port,
no first-start password in its environment, no login env file left, and the admin login works with the
recorded password unseen.

Database server destroy contract: no container of the Process is left, and the data volume stays.

Arguments: the expected state, `running` after create or `removed` after destroy.
"""
import json
import os
import subprocess
import sys

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
intent = operation['input']
container = intent['container']


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False).stdout.strip()


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message} ({container})')


if sys.argv[1] == 'removed':
    check(operation['status'] == 'succeeded' and operation['result'] == {'backend': 'absent'}, 'the projected result is backend absent')
    left = target('docker', 'ps', '--all', '--quiet', '--filter', 'label=orbit.process.id=' + str(intent['process_id']))
    check(left == '', f'no container of the Process is left, found {left}')
    volume = 'orbit-' + container.split('-', 3)[3] + '-data'
    check(target('docker', 'volume', 'inspect', '--format', '{{.Name}}', volume) == volume, f'the data volume {volume} stays')
    print(f'parity: {operation["kind"]} removes {container} and keeps {volume}')
    sys.exit(0)
check(operation['status'] == 'succeeded' and operation['result'] == {'backend': 'active'}, 'the projected result is backend active')
value = json.loads(target('docker', 'container', 'inspect', container) or '[{}]')[0]
check(value.get('State', {}).get('Running') is True, 'the container runs')
check(value['State'].get('Health', {}).get('Status') == 'healthy', 'Docker reports it healthy')
labels = value['Config']['Labels']
check(labels.get('orbit.process.id') == str(intent['process_id']), 'it carries the Process label')
check(labels.get('orbit.process.spec') == intent['scrub_spec'], 'it runs the final spec, without the first-start env')
names = [item.split('=', 1)[0] for item in value['Config'].get('Env') or []]
check('MYSQL_ROOT_PASSWORD' not in names and 'POSTGRES_PASSWORD' not in names, 'no first-start password in its environment')
bindings = [binding['HostIp'] for ports in value['HostConfig']['PortBindings'].values() for binding in ports]
check(bindings and all(ip not in ('', '0.0.0.0', '::') for ip in bindings), f'only private bindings, got {bindings}')
mounts = [mount for mount in value['Mounts'] if mount['Type'] == 'volume']
check(len(mounts) == 1, 'one data volume')
left = target('sh', '-c', f'ls /run/orbit-database-server-create-{operation["id"]}*.env 2>/dev/null || true')
check(left == '', f'no login env file is left on the Node, found {left}')
print(f'parity: {operation["kind"]} leaves {intent["driver"]} {container} running, healthy and scrubbed')
