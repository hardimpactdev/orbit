"""Disposable network state for node.converge; never prints private keys."""
import json
import subprocess
import sys
from pathlib import Path


def on(node, *argv):
    return subprocess.run(['incus', 'exec', node, '--', *argv], capture_output=True, text=True, check=True).stdout.strip()


def fixture(body):
    subprocess.run(['runuser', '-u', 'orbit', '--', 'env', '-C', 'apps/gateway', 'php', '../../tools/swamp-proof-guest/gateway.php', 'fixture'],
                   input=json.dumps(body), text=True, capture_output=True, check=True)


mode = sys.argv[1]
if mode == 'prepare':
    subprocess.run(['bash', 'tools/swamp-proof-guest/network-proof.sh', sys.argv[2]], check=True, capture_output=True)
    on('peer', 'sh', '-eu', '-c', 'umask 077; mkdir -p /etc/wireguard; test -s /etc/wireguard/orbit.key || wg genkey > /etc/wireguard/orbit.key')
    public = on('peer', 'sh', '-c', 'wg pubkey < /etc/wireguard/orbit.key')
    fixture({'wireguard_keys': {sys.argv[3]: public}})
    # Keep control SSH and the outer hub endpoint on the physical proof network.
    on('peer', 'sh', '-eu', '-c', 'dev=$(ip -o -4 addr show | awk \'$4 ~ /^10\\.231\\.0\\./ {print $2; exit}\'); ip route replace 10.231.0.1/32 dev "$dev"; ip route replace 10.231.0.10/32 dev "$dev"')
elif mode == 'drift':
    hub = on('target', 'wg', 'show', 'orbit', 'public-key')
    peer = on('peer', 'wg', 'show', 'orbit', 'public-key')
    on('peer', 'wg', 'set', 'orbit', 'peer', hub, 'allowed-ips', '10.77.0.0/24')
    on('target', 'wg', 'set', 'orbit', 'peer', peer, 'remove')
elif mode == 'hub':
    # This fixture records an already-bootstrapped VPN hub. Seed the same loopback backend bind its
    # bootstrap writes; Ubuntu's stock dnsmasq instead collides with systemd-resolved on port 53.
    backend = subprocess.run(['runuser', '-u', 'orbit', '--', 'env', '-C', 'apps/gateway', 'php', '-r',
        'require "vendor/autoload.php"; echo \App\Infrastructure\AppDev\VpnDnsmasqBackendListen::apply("# Managed by Orbit.\\n");'],
        capture_output=True, text=True, check=True).stdout
    subprocess.run(['incus', 'exec', 'target', '--', 'tee', '/etc/dnsmasq.d/orbit-vpn.conf'],
                   input=backend, capture_output=True, text=True, check=True)
    # One recorded offline peer and one unrecorded live/file residue, beside the real connected peer.
    key = subprocess.run(['sh', '-c', 'wg genkey | wg pubkey'], capture_output=True, text=True, check=True).stdout.strip()
    fixture({'wireguard_keys': {sys.argv[2]: key}})
    stale = subprocess.run(['sh', '-c', 'wg genkey | wg pubkey'], capture_output=True, text=True, check=True).stdout.strip()
    on('target', 'wg', 'set', 'orbit', 'peer', stale, 'allowed-ips', '10.231.0.240/32')
    on('target', 'sh', '-eu', '-c', 'umask 077; printf "[Peer]\\nPublicKey = %s\\nAllowedIPs = 10.231.0.240/32\\n" "$1" > /etc/wireguard/orbit-peers.d/999999.conf', 'sh', stale)
else:
    raise SystemExit('unknown fixture mode')
print('network fixture ' + mode + ': ready')
