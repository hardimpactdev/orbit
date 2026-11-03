"""A real WireGuard overlay inside the shared topology; never touch the task VM's orbit link."""
import json
import subprocess
from pathlib import Path


def run(*argv, input=None):
    return subprocess.run(argv, input=input, text=True, capture_output=True, check=True, timeout=600).stdout


# Only the nested target container becomes the hub. The task VM keeps its own fleet connection.
run('incus', 'exec', 'target', '--', 'apt-get', 'update')
run('incus', 'exec', 'target', '--', 'apt-get', 'install', '-y', '--no-install-recommends', 'dnsmasq')
run('incus', 'exec', 'target', '--', 'tee', '/etc/dnsmasq.d/orbit-vpn.conf',
    input='# Managed by Orbit: private DNS backend.\nlisten-address=127.0.0.55\nbind-interfaces\n')
run('incus', 'exec', 'target', '--', 'sh', '-c',
    'install -d -m 0700 /etc/wireguard; umask 077; wg genkey > /etc/wireguard/orbit.key')
public = run('incus', 'exec', 'target', '--', 'sh', '-c', 'wg pubkey < /etc/wireguard/orbit.key').strip()
config = ('[Interface]\nAddress = 10.232.0.1/24\nListenPort = 51820\n'
          'PostUp = wg set %i private-key /etc/wireguard/orbit.key\n')
run('incus', 'exec', 'target', '--', 'tee', '/etc/wireguard/orbit.conf', input=config)
run('incus', 'exec', 'target', '--', 'chmod', '0600', '/etc/wireguard/orbit.conf')
run('incus', 'exec', 'target', '--', 'systemctl', 'enable', '--now', 'wg-quick@orbit.service')
run('incus', 'exec', 'target', '--', 'sysctl', '-w', 'net.ipv4.ip_forward=1')
run('incus', 'exec', 'target', '--', 'ufw', 'allow', '51820/udp')
run('incus', 'exec', 'target', '--', 'ufw', 'route', 'allow', 'from', '10.232.0.0/24', 'to', '10.232.0.0/24')
run('ip', 'address', 'replace', '10.232.0.2/32', 'dev', 'oeproof')
run('ip', 'route', 'replace', '10.232.0.0/24', 'via', '10.231.0.10', 'dev', 'oeproof', 'src', '10.232.0.2')
run('incus', 'exec', 'target', '--', 'ip', 'route', 'replace', '10.232.0.2/32', 'via', '10.231.0.1')
home = Path('/home/orbit/.orbit/wireguard')
home.mkdir(exist_ok=True)
(home / 'public.key').write_text(public + '\n')
run('chown', '-R', 'orbit:orbit', str(home))
fingerprint = run('incus', 'exec', 'metrics', '--', 'ssh-keygen', '-lf', '/etc/ssh/ssh_host_ed25519_key.pub').split()[1]
run('runuser', '-u', 'orbit', '--', 'php', 'swamp/proof/node.add/prepare.php', public)
print(json.dumps({'secrets': {'enrollment_fingerprint': fingerprint}}))
