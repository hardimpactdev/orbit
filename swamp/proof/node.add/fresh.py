"""Leave the enrollment candidate reachable only by the Gateway's root bootstrap key."""
import json
import subprocess
from pathlib import Path


def run(*argv, input=None):
    return subprocess.run(argv, input=input, text=True, capture_output=True, check=True, timeout=30).stdout


bootstrap = Path('/home/orbit/.orbit/ssh/id_ed25519.pub').read_text().strip()
worker = run('ssh-keygen', '-y', '-f', '/etc/orbit/node-proxy/id_ed25519').strip()
assert bootstrap.split()[:2] != worker.split()[:2], 'bootstrap and control keys must differ'
run('incus', 'exec', 'metrics', '--', 'install', '-d', '-m', '0700', '/root/.ssh')
run('incus', 'exec', 'metrics', '--', 'tee', '/root/.ssh/authorized_keys', input=bootstrap + '\n')
run('incus', 'exec', 'metrics', '--', 'chmod', '0600', '/root/.ssh/authorized_keys')
run('incus', 'exec', 'metrics', '--', 'truncate', '-s', '0', '/home/orbit/.ssh/authorized_keys')
for host in ('10.231.0.11', '10.232.0.11'):
    run('ssh-keygen', '-R', host, '-f', '/home/orbit/.ssh/known_hosts')
print(json.dumps({'fresh_bootstrap': 'root-only', 'control_key_authorized': False, 'worker_host_pins': False}))
