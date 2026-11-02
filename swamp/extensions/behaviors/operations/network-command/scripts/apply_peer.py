"""Apply the peer file; preserve an active tunnel with native syncconf."""

import subprocess

from common import RUN, WG, main, write


def apply(_data):
    active = subprocess.run(['/usr/bin/systemctl', 'is-active', '--quiet', 'wg-quick@orbit'], check=False, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0
    if active:
        import ipaddress
        import json
        configured = next(line.split('=', 1)[1].strip() for line in (WG / 'orbit.conf').read_text().splitlines() if line.strip().startswith('Address'))
        desired = ipaddress.ip_interface(configured)
        links = json.loads(subprocess.run(['/usr/sbin/ip', '-j', '-4', 'address', 'show', 'dev', 'orbit'], input=None, check=True, **RUN).stdout.strip())
        if not any(row['local'] == str(desired.ip) and row['prefixlen'] == desired.network.prefixlen for link in links for row in link['addr_info']):
            raise ValueError('wireguard.peer_address_change_requires_recovery')
        runtime = WG / '.orbit-peer-runtime.conf'
        try:
            write(runtime, subprocess.run(['/usr/bin/wg-quick', 'strip', str(WG / 'orbit.conf')], input=None, check=True, **RUN).stdout.strip() + '\n')
            subprocess.run(['/usr/bin/wg', 'syncconf', 'orbit', str(runtime)], input=None, check=True, **RUN).stdout.strip()
        finally:
            runtime.unlink(missing_ok=True)
    else:
        subprocess.run(['/usr/bin/systemctl', 'start', 'wg-quick@orbit'], input=None, check=True, **RUN).stdout.strip()
    return {'applied': True}
if __name__ == '__main__':
    main(apply)
