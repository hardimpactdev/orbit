"""Host-local WireGuard file primitives. Never export private configuration."""
import fcntl
import json
import os
import re
import subprocess
import tempfile
from pathlib import Path

RUN = {'text': True, 'stdout': subprocess.PIPE, 'stderr': subprocess.DEVNULL}
NETWORK_INPUT = None
WG = Path('/etc/wireguard')
LOCK = Path('/run/lock/orbit-wireguard-peer.lock')
KEY = re.compile('[A-Za-z0-9+/]{43}=')

def write(path, value):
    fd, name = tempfile.mkstemp(prefix='.orbit-', suffix='.conf', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as stream:
            os.fchmod(stream.fileno(), 0o600)
            stream.write(value)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(name, path)
    finally:
        Path(name).unlink(missing_ok=True)

def key():
    public = subprocess.run(['/usr/bin/wg', 'pubkey'], input=(WG / 'orbit.key').read_text(), check=True, **RUN).stdout.strip()
    if not KEY.fullmatch(public):
        raise ValueError('wireguard.peer_key_invalid')
    return public

def check_live_address(address):
    import ipaddress
    state = subprocess.run(['/usr/bin/systemctl', 'is-active', '--quiet', 'wg-quick@orbit'],
                           check=False, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    if state.returncode != 0:
        return
    desired = ipaddress.ip_interface(address)
    links = json.loads(subprocess.run(['/usr/sbin/ip', '-j', '-4', 'address', 'show', 'dev', 'orbit'], check=True, **RUN).stdout)
    if not any(row['local'] == str(desired.ip) and row['prefixlen'] == desired.network.prefixlen
               for link in links for row in link['addr_info']):
        raise ValueError('wireguard.peer_address_change_requires_recovery')


def locked(action):
    WG.mkdir(mode=0o700, exist_ok=True)
    with os.fdopen(os.open(LOCK, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600), 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        if any((WG / name).exists() for name in ['.orbit.native-network-transaction', '.orbit.peer-transaction', '.orbit.conf.rollback', '.orbit.dns-link.rollback']):
            raise ValueError('wireguard.peer_recovery_pending')
        return action(NETWORK_INPUT)

def main(action):
    try:
        print(json.dumps(locked(action)))
    except (OSError, ValueError, StopIteration, subprocess.SubprocessError):
        raise SystemExit('WireGuard action failed; inspect the native step before recovery.') from None
