"""Service-manager boundary for the stock-fleet fixture. Only its private DNS process is real."""
import json
import os
import shlex
import signal
import subprocess
from pathlib import Path

from ansible.module_utils.basic import AnsibleModule

module = AnsibleModule(argument_spec={
    'name': {'type': 'str'}, 'state': {'type': 'str'}, 'enabled': {'type': 'bool'},
    'daemon_reload': {'type': 'bool', 'default': False},
}, supports_check_mode=True)
if module.check_mode:
    module.exit_json(changed=False, status={'ActiveState': 'active', 'SubState': 'running'})
folder = Path(os.environ['STOCK_FIXTURE'])
name = module.params['name']
with (folder / 'systemd.jsonl').open('a') as calls:
    calls.write(json.dumps(module.params) + '\n')
if name == 'orbit-gateway-dns.service' and module.params['state'] == 'started':
    source = (folder / 'units/orbit-gateway-dns.service').read_text()
    address = next(line.split()[-1] for line in source.splitlines() if line.startswith('ExecStart=/usr/bin/resolvectl dns orbit '))
    (folder / 'resolver-address').write_text(address + '\n')
if name == 'orbit-private-dns.service' and module.params['state'] == 'stopped':
    pidfile = folder / 'listener.pid'
    if pidfile.exists():
        os.kill(int(pidfile.read_text()), signal.SIGTERM)
        pidfile.unlink()
if name == 'orbit-private-dns.service' and module.params['state'] in ('started', 'restarted'):
    pidfile = folder / 'listener.pid'
    if not pidfile.exists():
        source = (folder / 'units/orbit-private-dns.service').read_text()
        command = next(line.removeprefix('ExecStart=') for line in source.splitlines() if line.startswith('ExecStart='))
        with (folder / 'listener.log').open('wb') as log:
            child = subprocess.Popen(shlex.split(command), stdout=log, stderr=log, start_new_session=True)
        pidfile.write_text(str(child.pid))
module.exit_json(changed=False, status={'ActiveState': 'active', 'SubState': 'running'})
