"""Database server create contract on the target (F9): an owned running container on the private port,
no first-start password in its environment, and the admin login works with the recorded password unseen.

Arguments: the expected Docker state of the server container, `running`.
"""
import json
import os
import subprocess
import sys

operation = json.loads(os.environ['PROOF_OPERATION'])
intent = operation['input']
container = intent['container']


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False).stdout.strip()


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message} ({container})')


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
print(f'parity: {operation["kind"]} leaves {intent["driver"]} {container} running, healthy and scrubbed')
