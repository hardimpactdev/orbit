"""Boot stock Ubuntu; root trusts only the Gateway bootstrap identity before admission."""
import ipaddress
import json
import subprocess
import time
from pathlib import Path

STATE = Path('/home/orbit/.orbit/swamp-proof/node-add-stock.json')
CANDIDATE = 'node-add-stock'


def run(*argv, input=None, timeout=60, check=True):
    return subprocess.run(argv, input=input, text=True, capture_output=True, check=check, timeout=timeout)


def guest(*argv, **kwargs):
    return run('incus', 'exec', CANDIDATE, '--', *argv, **kwargs)


bootstrap = Path('/home/orbit/.orbit/ssh/id_ed25519.pub').read_text().strip()
worker = run('ssh-keygen', '-y', '-f', '/etc/orbit/node-proxy/id_ed25519').stdout.strip()
assert bootstrap.split()[:2] != worker.split()[:2], 'bootstrap and control keys must differ'
# This is a cloud image, never the topology's prepared node-template.
run('incus', 'launch', 'images:ubuntu/26.04/cloud', CANDIDATE, '--vm',
    '--storage', 'topology-seed', '--network', 'oeproof',
    '-c', 'limits.cpu=2', '-c', 'limits.memory=2GiB', timeout=300)
deadline = time.monotonic() + 240
while guest('true', check=False).returncode:
    if time.monotonic() >= deadline:
        raise RuntimeError('stock VM agent did not become ready')
    time.sleep(2)
guest('cloud-init', 'status', '--wait', timeout=240)
guest('apt-get', 'update', timeout=180)
guest('apt-get', 'install', '-y', '--no-install-recommends', 'openssh-server', timeout=180)
guest('install', '-d', '-m', '0700', '/root/.ssh')
guest('tee', '/root/.ssh/authorized_keys', input=bootstrap + '\n')
guest('chmod', '0600', '/root/.ssh/authorized_keys')
# No default cloud account may carry a second bootstrap path.
assert not guest('find', '/home', '-name', 'authorized_keys', '-type', 'f', '-size', '+0c').stdout.strip()
assert guest('getent', 'passwd', 'orbit', check=False).returncode == 2
assert guest('getent', 'group', 'orbit-env', check=False).returncode == 2
packages = guest('dpkg-query', '-W', '-f', '${binary:Package}\n').stdout.splitlines()
for package in ('wireguard-tools', 'ufw', 'git', 'composer', 'php-cli'):
    assert package not in packages, f'{package} was already installed'
assert guest('test', '-e', '/usr/local/bin/orbit-agent', check=False).returncode == 1
instances = json.loads(run('incus', 'list', CANDIDATE, '--format=json').stdout)
addresses = instances[0]['state']['network']
address = next(item['address'] for nic in addresses.values() for item in nic['addresses']
               if item['family'] == 'inet' and ipaddress.ip_address(item['address']) in ipaddress.ip_network('10.231.0.0/24'))
fingerprint = guest('ssh-keygen', '-lf', '/etc/ssh/ssh_host_ed25519_key.pub').stdout.split()[1]
known_hosts = Path('/home/orbit/.ssh/known_hosts')
for host in (address, '10.232.0.11'):
    if known_hosts.exists():
        run('ssh-keygen', '-R', host, '-f', str(known_hosts))
        assert run('ssh-keygen', '-F', host, '-f', str(known_hosts), check=False).returncode == 1
state = {'candidate': CANDIDATE, 'image': 'images:ubuntu/26.04/cloud', 'address': address,
         'fingerprint': fingerprint, 'bootstrap': 'root-only', 'orbit_account': False,
         'observer_group': False, 'worker_host_pins': False, 'packages': packages}
STATE.write_text(json.dumps(state, indent=2) + '\n')
print(json.dumps({'bindings': {'stock_address': address}, 'secrets': {'enrollment_fingerprint': fingerprint}}))
