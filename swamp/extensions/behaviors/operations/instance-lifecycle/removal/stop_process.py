"""One placed Instance removal action: stop-process."""

import json
import os
import re
import subprocess
import sys

from identity import owned_unit

process_id, name = int(os.environ['ORBIT_PROCESS_ID']), os.environ['ORBIT_PROCESS_NAME']
if re.fullmatch(r'orbit-process-' + str(process_id) + r'-[a-z0-9-]+(?:\.service)?', name) is None:
    sys.exit(1)
if os.environ['ORBIT_RUNTIME'] == 'systemd':
    unit = name if name.endswith('.service') else name + '.service'
    present = owned_unit(unit, 'X-Orbit-Process-ID=' + str(process_id))
    exists = subprocess.run(['systemctl', 'show', '--property=LoadState', '--value', unit],
                            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=False)
    if exists.returncode != 0:
        sys.exit(1)
    if not present and exists.stdout.strip() != b'not-found':
        sys.exit(1)
    if exists.stdout.strip() != b'not-found':
        subprocess.run(['systemctl', 'stop', '--', unit], check=True,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
elif os.environ['ORBIT_RUNTIME'] == 'docker':
    observed = subprocess.run(['docker', 'container', 'ls', '-a',
                               '--filter', 'name=^/' + name + '$', '--format', '{{.Names}}'],
                              stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=False)
    if observed.returncode != 0:
        sys.exit(1)
    if observed.stdout.strip():
        if observed.stdout.strip().decode() != name:
            sys.exit(1)
        labels = subprocess.run(['docker', 'container', 'inspect', '--format', '{{json .Config.Labels}}', name],
                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=True)
        labels = json.loads(labels.stdout)
        if labels.get('orbit.managed') != 'true' or labels.get('orbit.container.kind') != 'process' or labels.get('orbit.process.id') != str(process_id):
            sys.exit(1)
        if re.fullmatch(r'[a-f0-9]{64}', labels.get('orbit.process.spec', '')) is None:
            sys.exit(1)
        subprocess.run(['docker', 'stop', '--', name], check=True,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
else:
    sys.exit(1)
print(json.dumps({'process_id': process_id, 'stopped': True}))
