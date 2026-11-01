"""Update one admitted hub peer without replacing other peers or restarting the hub."""

import ipaddress
import os
import re
import subprocess
import tempfile
from pathlib import Path

from common import RUN, WG, main, write


def update(text, data):
    address = str(ipaddress.IPv4Interface(data['address']).ip) + '/32'
    sections = re.split('(?m)(?=^\\s*\\[(?:Interface|Peer)\\]\\s*$)', text)
    retained = []
    for section in sections:
        if not re.match('\\s*\\[Peer\\]', section):
            retained.append(section)
            continue
        public = re.search('(?m)^\\s*PublicKey\\s*=\\s*(\\S+)\\s*$', section)
        allowed = re.search('(?m)^\\s*AllowedIPs\\s*=\\s*(.*?)\\s*$', section)
        if not public or not allowed:
            raise ValueError('wireguard.hub_config_invalid')
        if public[1] in [data['existing_key'], data['public_key']]:
            if allowed[1] != address:
                raise ValueError('wireguard.peer_address_conflict')
            continue
        if any(ipaddress.ip_network(value.strip()).overlaps(ipaddress.ip_network(address)) for value in allowed[1].split(',')):
            raise ValueError('wireguard.peer_address_conflict')
        retained.append(section)
    return ''.join(retained).rstrip() + '\n\n[Peer]\nPublicKey = ' + data['public_key'] + '\nAllowedIPs = ' + address + '\n'

def publish(data):
    subprocess.run(['/usr/bin/systemctl', 'is-active', '--quiet', 'wg-quick@orbit'], input=None, check=True, **RUN).stdout.strip()
    path = WG / 'orbit.conf'
    after = update(path.read_text(), data)
    fd, candidate = tempfile.mkstemp(prefix='or-p-', suffix='.conf', dir=WG)
    runtime = Path(candidate + '.runtime')
    try:
        with os.fdopen(fd, 'w') as stream:
            os.fchmod(stream.fileno(), 0o600)
            stream.write(after)
        stripped = subprocess.run(['/usr/bin/wg-quick', 'strip', candidate], input=None, check=True, **RUN).stdout.strip()
        write(runtime, stripped + '\n')
        subprocess.run(['/usr/bin/wg', 'syncconf', 'orbit', str(runtime)], input=None, check=True, **RUN).stdout.strip()
        write(path, after)
    finally:
        Path(candidate).unlink(missing_ok=True)
        runtime.unlink(missing_ok=True)
    return {'hub_published': True}
if __name__ == '__main__':
    main(publish)
